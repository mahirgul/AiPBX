package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"
	"unicode/utf8"
)

func TestTruncateRunesKeepsValidUTF8(t *testing.T) {
	long := strings.Repeat("ş", 300)
	got := truncateRunes(long, 250)
	if !utf8.ValidString(got) {
		t.Fatal("truncateRunes produced invalid UTF-8")
	}
	if n := utf8.RuneCountInString(got); n != 250 {
		t.Fatalf("want 250 characters, got %d", n)
	}
	if !strings.HasSuffix(got, "...") {
		t.Fatal("a cut preview must end with ...")
	}
	if got := truncateRunes("kısa", 250); got != "kısa" {
		t.Fatalf("short text must stay unchanged, got %q", got)
	}
	if got := clipRunes(strings.Repeat("ğ", 120), 100); !utf8.ValidString(got) || utf8.RuneCountInString(got) != 100 {
		t.Fatalf("clipRunes: want 100 valid characters, got %q", got)
	}
}

func TestSystemMetaEncodesUserInput(t *testing.T) {
	title := `Ekip "A", "actor":"999` + "\n"
	var v map[string]string
	if err := json.Unmarshal([]byte(systemMeta(map[string]string{"actor": "100", "value": title})), &v); err != nil {
		t.Fatalf("system_meta is not valid JSON: %v", err)
	}
	if v["actor"] != "100" || v["value"] != title {
		t.Fatalf("system_meta fields changed: %v", v)
	}
}

func TestSendToExtensionFullBufferIsNotDelivered(t *testing.T) {
	h := NewHub()
	if h.SendToExtension("100", []byte("x")) {
		t.Fatal("an extension without devices must not count as delivered")
	}
	c := newTestClient(h, "100", true)
	for len(c.send) < cap(c.send) {
		c.send <- []byte("filler")
	}
	if h.SendToExtension("100", []byte("x")) {
		t.Fatal("a message dropped by a full send buffer must not count as delivered")
	}
	<-c.send
	if !h.SendToExtension("100", []byte("x")) {
		t.Fatal("a queued message must count as delivered")
	}
}

// testExtensions returns two active chat users, or skips without a database.
func testExtensions(t *testing.T) (string, string) {
	t.Helper()
	cfg, err := LoadConfig()
	if err != nil {
		t.Skip("Cannot load config for DB test")
	}
	if err := InitDB(cfg); err != nil {
		t.Skip("Cannot connect to DB for test:", err)
	}
	rows, err := db.Query("SELECT extension FROM sys_users WHERE is_active = 1 AND extension IS NOT NULL AND extension != '' AND role != 'fax_user' ORDER BY id ASC LIMIT 2")
	if err != nil {
		t.Skip("Cannot query sys_users:", err)
	}
	defer rows.Close()
	var exts []string
	for rows.Next() {
		var e string
		if rows.Scan(&e) == nil {
			exts = append(exts, e)
		}
	}
	if len(exts) < 2 {
		t.Skip("Need at least 2 active extensions")
	}
	return exts[0], exts[1]
}

func removeTestConversation(convID int) {
	_, _ = db.Exec("DELETE FROM chat_messages WHERE conversation_id = ?", convID)
	_, _ = db.Exec("DELETE FROM chat_participants WHERE conversation_id = ?", convID)
	_, _ = db.Exec("DELETE FROM chat_conversations WHERE id = ?", convID)
}

// A direct chat is never a group: "leave group" must be refused (it used to
// promote the other person to admin), and an admin row left on a direct chat
// must not unlock adding a third person to the private conversation.
func TestDirectChatIsNotAGroup(t *testing.T) {
	a, b := testExtensions(t)

	// A direct conversation of its own (a unique key), never the users' real chat
	res, err := db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, fmt.Sprintf("test-%d", time.Now().UnixNano()), a)
	if err != nil {
		t.Fatalf("create test conversation: %v", err)
	}
	id64, _ := res.LastInsertId()
	convID := int(id64)
	defer removeTestConversation(convID)
	if _, err := db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())`, convID, a, convID, b); err != nil {
		t.Fatalf("add participants: %v", err)
	}

	if _, err := LeaveGroup(convID, a); err == nil {
		t.Fatal("leaving a direct chat must be refused")
	}
	if ok, _ := IsParticipant(convID, a); !ok {
		t.Fatal("a refused leave must not remove the participant")
	}

	// An admin row from before the fix must not make anyone a group admin
	_, _ = db.Exec("UPDATE chat_participants SET role = 'admin' WHERE conversation_id = ? AND extension = ?", convID, b)
	if ok, _ := IsGroupAdmin(convID, b); ok {
		t.Fatal("a direct chat has no group admins")
	}
	if _, _, err := AddGroupMembers(convID, b, []string{a}); err == nil {
		t.Fatal("members must not be added to a direct chat")
	}
}

// Deleting a group only sets is_deleted; after that nobody may read, post to
// or administer it. Also checks that a long Turkish message is stored.
func TestDeletedGroupIsClosed(t *testing.T) {
	a, b := testExtensions(t)

	conv, _, err := CreateGroupConversation(`Test "silinecek" grup`, a, "", "", []string{b})
	if err != nil {
		t.Fatalf("CreateGroupConversation: %v", err)
	}
	defer removeTestConversation(conv.ID)

	var meta string
	_ = db.QueryRow("SELECT system_meta FROM chat_messages WHERE conversation_id = ? AND system_event = 'group_created'", conv.ID).Scan(&meta)
	if !json.Valid([]byte(meta)) {
		t.Fatalf("group_created system_meta is not valid JSON: %s", meta)
	}

	// The preview is cut at 250 characters; a byte cut inside "ş" failed the
	// whole message under strict SQL mode.
	if _, err := SaveMessage(conv.ID, a, "text", strings.Repeat("ş", 300), "", "", 0, ""); err != nil {
		t.Fatalf("a long Turkish message must be stored: %v", err)
	}

	if err := DeleteGroup(conv.ID, a); err != nil {
		t.Fatalf("DeleteGroup: %v", err)
	}
	for _, ext := range []string{a, b} {
		if ok, _ := IsParticipant(conv.ID, ext); ok {
			t.Fatalf("%s is still a participant of a deleted group", ext)
		}
	}
	if ok, _ := IsGroupAdmin(conv.ID, a); ok {
		t.Fatal("a deleted group has no admins")
	}
}

// Two admins leaving at the same moment each saw the other as an admin, nobody
// was promoted, and the group stayed without an admin for good.
func TestLeavingAdminsAtOnceLeaveAnAdmin(t *testing.T) {
	testExtensions(t)
	for i := 0; i < 25; i++ {
		res, err := db.Exec(`INSERT INTO chat_conversations (type, created_by, created_at, updated_at, is_deleted)
			VALUES ('group', '91001', NOW(), NOW(), 0)`)
		if err != nil {
			t.Fatalf("create group: %v", err)
		}
		id64, _ := res.LastInsertId()
		convID := int(id64)
		defer removeTestConversation(convID)
		if _, err := db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
			VALUES (?, '91001', 'admin', NOW()), (?, '91002', 'admin', NOW()), (?, '91003', 'member', NOW())`,
			convID, convID, convID); err != nil {
			t.Fatalf("add participants: %v", err)
		}

		var wg sync.WaitGroup
		errs := make(chan error, 2)
		for _, ext := range []string{"91001", "91002"} {
			wg.Add(1)
			go func(ext string) {
				defer wg.Done()
				if _, err := LeaveGroup(convID, ext); err != nil {
					errs <- err
				}
			}(ext)
		}
		wg.Wait()
		close(errs)
		for err := range errs {
			t.Fatalf("round %d: leave failed: %v", i, err)
		}

		var admins int
		if err := db.QueryRow(`SELECT COUNT(*) FROM chat_participants
			WHERE conversation_id = ? AND role = 'admin' AND left_at IS NULL`, convID).Scan(&admins); err != nil {
			t.Fatal(err)
		}
		if admins != 1 {
			t.Fatalf("round %d: %d admins left in the group, want the remaining member promoted", i, admins)
		}
	}
}
