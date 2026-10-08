package main

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestCheckDeleteAllowed(t *testing.T) {
	now := time.Date(2026, 10, 9, 12, 0, 0, 0, time.UTC)
	own := func() *messageForDelete {
		return &messageForDelete{SenderExt: "1001", MsgType: "text", CreatedAt: now.Add(-10 * time.Minute)}
	}

	if e := checkDeleteAllowed(own(), "1001", 0, now); e != nil {
		t.Fatalf("the sender must be able to delete their message: %s", e.msg)
	}
	if e := checkDeleteAllowed(own(), "1002", 0, now); e == nil || e.status != http.StatusForbidden {
		t.Fatal("someone else's message must not be deletable")
	}

	sys := own()
	sys.MsgType = "system"
	if e := checkDeleteAllowed(sys, "1001", 0, now); e == nil {
		t.Fatal("system messages must not be deletable")
	}

	gone := own()
	gone.IsDeleted = true
	if e := checkDeleteAllowed(gone, "1001", 0, now); e == nil || e.status != http.StatusConflict {
		t.Fatal("a deleted message must not be deleted again")
	}

	// Time limit: 15 minutes allows a 10-minute-old message, 5 minutes does not; 0 = always.
	if e := checkDeleteAllowed(own(), "1001", 15, now); e != nil {
		t.Fatalf("within the time limit: %s", e.msg)
	}
	if e := checkDeleteAllowed(own(), "1001", 5, now); e == nil {
		t.Fatal("after the time limit the message must not be deletable")
	}
	old := own()
	old.CreatedAt = now.Add(-365 * 24 * time.Hour)
	if e := checkDeleteAllowed(old, "1001", 0, now); e != nil {
		t.Fatal("0 minutes means no time limit")
	}
}

func TestAttachmentFilePaths(t *testing.T) {
	dir := "/data/chat"
	img := attachmentFilePaths(dir, "/chat/media/images/1_ab_cd.jpg")
	want := []string{"/data/chat/images/1_ab_cd.jpg", "/data/chat/thumbs/1_ab_cd_thumb.jpg"}
	if len(img) != 2 || img[0] != want[0] || img[1] != want[1] {
		t.Fatalf("image paths: got %v, want %v", img, want)
	}
	doc := attachmentFilePaths(dir, "/media/docs/1_ab_cd.pdf")
	if len(doc) != 1 || doc[0] != "/data/chat/docs/1_ab_cd.pdf" {
		t.Fatalf("document path: got %v", doc)
	}

	// Nothing outside the message media folders may ever be removed.
	for _, bad := range []string{
		"", "/chat/media/images/../../etc/passwd", "/chat/media/avatars/1_ab_cd.png",
		"/chat/media/thumbs/1_ab_cd_thumb.jpg", "/etc/passwd", "https://evil.example/x.jpg",
		"/chat/media/images/", "/chat/media/docs/sub/x.pdf",
	} {
		if p := attachmentFilePaths(dir, bad); len(p) != 0 {
			t.Fatalf("%q must map to no file, got %v", bad, p)
		}
	}
}

func TestHandleDeleteMessageRejectsBadRequests(t *testing.T) {
	srv := &Server{hub: NewHub()}
	user := &User{ID: 1, Extension: "19000", FullName: "Test"}

	req := httptest.NewRequest(http.MethodGet, "/api/messages/delete", nil)
	rr := httptest.NewRecorder()
	srv.HandleDeleteMessage(rr, req, user)
	if rr.Code != http.StatusMethodNotAllowed {
		t.Fatalf("GET: got HTTP %d", rr.Code)
	}

	for _, body := range []string{`{}`, `{"message_id":0}`, `{"message_id":-4}`, `not json`} {
		req := httptest.NewRequest(http.MethodPost, "/api/messages/delete", bytes.NewBufferString(body))
		rr := httptest.NewRecorder()
		srv.HandleDeleteMessage(rr, req, user)
		if rr.Code != http.StatusBadRequest {
			t.Fatalf("body %s: got HTTP %d, want 400", body, rr.Code)
		}
	}
}

// A delete with a database: only the sender's own message, the row is kept
// but cleared, the file goes from disk, every participant gets the event.
func TestDeleteMessageEndToEnd(t *testing.T) {
	a, b := testExtensions(t)

	res, err := db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, fmt.Sprintf("test-del-%d", time.Now().UnixNano()), a)
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

	// An image attachment on disk in a temporary upload folder.
	hub := NewHub()
	hub.secretKey = "test-secret"
	hub.uploadDir = t.TempDir()
	base := signedUploadBase(hub.secretKey, a, "1_test")
	url := "/chat/media/images/" + base + ".jpg"
	files := attachmentFilePaths(hub.uploadDir, url)
	for _, f := range files {
		_ = os.MkdirAll(filepath.Dir(f), 0755)
		if err := os.WriteFile(f, []byte("img"), 0644); err != nil {
			t.Fatal(err)
		}
	}
	msg, err := SaveMessage(convID, a, "image", "caption", url, "photo.jpg", 3, "image/jpeg")
	if err != nil {
		t.Fatalf("SaveMessage: %v", err)
	}

	// Both participants listen.
	listen := func(ext string) *Client {
		c := &Client{hub: hub, user: &User{Extension: ext}, send: make(chan []byte, 8), active: true}
		hub.clients[ext] = map[*Client]bool{c: true}
		return c
	}
	ca, cb := listen(a), listen(b)

	if _, e := hub.DeleteMessage(&User{Extension: b}, msg.ID); e == nil {
		t.Fatal("the other participant must not delete the sender's message")
	}
	if _, e := hub.DeleteMessage(&User{Extension: a}, msg.ID); e != nil {
		t.Fatalf("the sender's delete was refused: %s", e.msg)
	}

	var text, attachment string
	var deleted int
	_ = db.QueryRow("SELECT COALESCE(message, ''), COALESCE(attachment_url, ''), is_deleted FROM chat_messages WHERE id = ?", msg.ID).
		Scan(&text, &attachment, &deleted)
	if deleted != 1 || text != "" || attachment != "" {
		t.Fatalf("row after delete: is_deleted=%d message=%q attachment=%q", deleted, text, attachment)
	}
	for _, f := range files {
		if _, err := os.Stat(f); !os.IsNotExist(err) {
			t.Fatalf("%s is still on disk", f)
		}
	}

	for name, c := range map[string]*Client{"sender": ca, "other participant": cb} {
		select {
		case raw := <-c.send:
			var evt struct {
				Event string `json:"event"`
				Data  struct {
					ConversationID int   `json:"conversation_id"`
					MessageID      int64 `json:"message_id"`
				} `json:"data"`
			}
			if err := json.Unmarshal(raw, &evt); err != nil || evt.Event != "message_deleted" ||
				evt.Data.MessageID != msg.ID || evt.Data.ConversationID != convID {
				t.Fatalf("%s got %s", name, raw)
			}
		default:
			t.Fatalf("the %s got no message_deleted event", name)
		}
	}

	msgs, _ := GetMessages(convID, 10, 0)
	if len(msgs) != 1 || !msgs[0].IsDeleted || msgs[0].AttachmentURL != "" {
		t.Fatalf("the message list must keep the message as deleted: %+v", msgs)
	}
	if _, e := hub.DeleteMessage(&User{Extension: a}, msg.ID); e == nil {
		t.Fatal("a message must not be deleted twice")
	}
}

// The admin setting chat_delete_window_minutes limits how old a message may be.
func TestDeleteWindowSetting(t *testing.T) {
	a, b := testExtensions(t)

	res, err := db.Exec(`INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)`, fmt.Sprintf("test-win-%d", time.Now().UnixNano()), a)
	if err != nil {
		t.Fatalf("create test conversation: %v", err)
	}
	id64, _ := res.LastInsertId()
	convID := int(id64)
	defer removeTestConversation(convID)
	_, _ = db.Exec(`INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())`, convID, a, convID, b)

	var before sql.NullString
	_ = db.QueryRow("SELECT setting_value FROM sys_settings WHERE setting_key = ?", deleteWindowSettingKey).Scan(&before)
	defer func() {
		if before.Valid {
			_, _ = db.Exec("UPDATE sys_settings SET setting_value = ? WHERE setting_key = ?", before.String, deleteWindowSettingKey)
		} else {
			_, _ = db.Exec("DELETE FROM sys_settings WHERE setting_key = ?", deleteWindowSettingKey)
		}
	}()
	setWindow := func(v string) {
		_, _ = db.Exec("DELETE FROM sys_settings WHERE setting_key = ?", deleteWindowSettingKey)
		_, _ = db.Exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)", deleteWindowSettingKey, v)
	}

	hub := NewHub()
	hub.uploadDir = t.TempDir()
	oldMessage := func() int64 {
		m, err := SaveMessage(convID, a, "text", "hello", "", "", 0, "")
		if err != nil {
			t.Fatalf("SaveMessage: %v", err)
		}
		_, _ = db.Exec("UPDATE chat_messages SET created_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?", m.ID)
		return m.ID
	}

	setWindow("5")
	if GetDeleteWindowMinutes() != 5 {
		t.Fatalf("GetDeleteWindowMinutes = %d, want 5", GetDeleteWindowMinutes())
	}
	if _, e := hub.DeleteMessage(&User{Extension: a}, oldMessage()); e == nil {
		t.Fatal("a 10-minute-old message must not be deletable with a 5-minute limit")
	}
	setWindow("15")
	if _, e := hub.DeleteMessage(&User{Extension: a}, oldMessage()); e != nil {
		t.Fatalf("within 15 minutes: %s", e.msg)
	}
	setWindow("0")
	if _, e := hub.DeleteMessage(&User{Extension: a}, oldMessage()); e != nil {
		t.Fatalf("0 = no limit: %s", e.msg)
	}
	setWindow("abc")
	if GetDeleteWindowMinutes() != 0 {
		t.Fatal("an invalid value must mean no limit")
	}
}
