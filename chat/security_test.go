package main

import (
	"bytes"
	"fmt"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestExtractToken(t *testing.T) {
	// 1. Authorization header
	req1, _ := http.NewRequest("GET", "/api/messages", nil)
	req1.Header.Set("Authorization", "Bearer test-token-123")
	if tok := ExtractToken(req1); tok != "test-token-123" {
		t.Fatalf("Expected test-token-123, got %s", tok)
	}

	// 2. Cookie
	req2, _ := http.NewRequest("GET", "/media/images/pic.jpg", nil)
	req2.AddCookie(&http.Cookie{Name: "chat_token", Value: "cookie-token-456"})
	if tok := ExtractToken(req2); tok != "cookie-token-456" {
		t.Fatalf("Expected cookie-token-456, got %s", tok)
	}

	// 3. Query param for /ws (Allowed)
	req3, _ := http.NewRequest("GET", "/chat/ws?token=ws-token-789", nil)
	if tok := ExtractToken(req3); tok != "ws-token-789" {
		t.Fatalf("Expected ws-token-789 for /ws, got %s", tok)
	}

	// 4. Query param for /media/ (Allowed)
	req4, _ := http.NewRequest("GET", "/chat/media/images/pic.jpg?token=media-token-999", nil)
	if tok := ExtractToken(req4); tok != "media-token-999" {
		t.Fatalf("Expected media-token-999 for /media/, got %s", tok)
	}

	// 5. Query param for /api/ (CH-10: Must be REJECTED)
	req5, _ := http.NewRequest("GET", "/chat/api/messages?token=api-token-forbidden", nil)
	if tok := ExtractToken(req5); tok != "" {
		t.Fatalf("CH-10 violation: token query param must be rejected for /api/, got %s", tok)
	}
}

func TestUploadExtensionWhitelist(t *testing.T) {
	dangerousExts := []string{".html", ".htm", ".svg", ".php", ".sh", ".exe", ".js", ".jsp"}
	for _, ext := range dangerousExts {
		if allowedUploadExts[ext] {
			t.Fatalf("CH-5 violation: %s must NOT be in allowedUploadExts whitelist", ext)
		}
	}

	safeExts := []string{".jpg", ".png", ".pdf", ".docx", ".mp3", ".txt"}
	for _, ext := range safeExts {
		if !allowedUploadExts[ext] {
			t.Fatalf("Safe extension %s should be in allowedUploadExts whitelist", ext)
		}
	}
}

func TestHandleUploadRejectsDisallowedFiles(t *testing.T) {
	srv := &Server{}
	user := &User{ID: 1, Extension: "19000", FullName: "Test Admin"}

	// 1. Upload HTML file
	body := &bytes.Buffer{}
	writer := multipart.NewWriter(body)
	part, err := writer.CreateFormFile("file", "exploit.html")
	if err != nil {
		t.Fatal(err)
	}
	_, _ = part.Write([]byte("<html><script>alert(1)</script></html>"))
	_ = writer.Close()

	req, _ := http.NewRequest("POST", "/api/upload", body)
	req.Header.Set("Content-Type", writer.FormDataContentType())
	rr := httptest.NewRecorder()

	srv.HandleUpload(rr, req, user)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("Expected 400 Bad Request for .html upload, got %d", rr.Code)
	}
	if !strings.Contains(rr.Body.String(), "Desteklenmeyen dosya uzantısı") {
		t.Fatalf("Unexpected response body: %s", rr.Body.String())
	}

	// 2. Upload SVG file
	body = &bytes.Buffer{}
	writer = multipart.NewWriter(body)
	part, err = writer.CreateFormFile("file", "graphic.svg")
	if err != nil {
		t.Fatal(err)
	}
	_, _ = part.Write([]byte("<svg onload=alert(1)></svg>"))
	_ = writer.Close()

	req, _ = http.NewRequest("POST", "/api/upload", body)
	req.Header.Set("Content-Type", writer.FormDataContentType())
	rr = httptest.NewRecorder()

	srv.HandleUpload(rr, req, user)

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("Expected 400 Bad Request for .svg upload, got %d", rr.Code)
	}
}

func TestAttachmentUrlValidationInSendMessage(t *testing.T) {
	srv := &Server{}
	user := &User{ID: 1, Extension: "19000", FullName: "Test Admin"}

	maliciousUrls := []string{
		"');alert(1);//",
		"javascript:alert(1)",
		"http://attacker.com/evil.jpg",
		"https://evil.com/xss.png",
		"data:text/html;base64,PHNjcmlwdD4=",
	}

	for _, badURL := range maliciousUrls {
		jsonBody := `{"conversation_id":1,"message":"test","attachment_url":"` + badURL + `"}`
		req, _ := http.NewRequest("POST", "/api/messages", strings.NewReader(jsonBody))
		req.Header.Set("Content-Type", "application/json")
		rr := httptest.NewRecorder()

		srv.HandleSendMessage(rr, req, user)

		if rr.Code != http.StatusBadRequest && rr.Code != http.StatusForbidden {
			t.Fatalf("CH-4 violation: Expected rejection (400 or 403) for attachment_url %q, got HTTP %d", badURL, rr.Code)
		}
	}
}

func TestIsParticipantWithDB(t *testing.T) {
	cfg, err := LoadConfig()
	if err != nil {
		t.Skip("Cannot load config for DB test")
	}
	if err := InitDB(cfg); err != nil {
		t.Skip("Cannot connect to DB for test:", err)
	}

	// 0 or empty extension must always return false
	if ok, _ := IsParticipant(0, "19000"); ok {
		t.Fatal("IsParticipant(0, ...) should return false")
	}
	if ok, _ := IsParticipant(1, ""); ok {
		t.Fatal("IsParticipant(..., '') should return false")
	}

	// Non-existent participant
	if ok, _ := IsParticipant(999999, "nonexistent-ext"); ok {
		t.Fatal("Non-existent conversation/ext should return false")
	}
}

func TestValidateUserToken(t *testing.T) {
	cfg, err := LoadConfig()
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("Config SecretKey length: %d", len(cfg.SecretKey))

	if err := InitDB(cfg); err != nil {
		t.Fatal(err)
	}

	token := "MToxNzkxMzAwMDQ4OmYwYjYzZDBmNjNhMTY4OGQ3NjM1Y2U0YzM0MjMyZDExODdiNWJlZjUzOGU0Yjg0ZTk2Mjg0OTI3YTJkMDhmYjI="
	user, err := ValidateBearerToken(token, cfg.SecretKey)
	if err != nil {
		t.Fatalf("ValidateBearerToken failed: %v", err)
	}
	t.Logf("Validated user: %s (Ext: %s)", user.FullName, user.Extension)
}

func TestGroupSecurityCH_G(t *testing.T) {
	cfg, err := LoadConfig()
	if err != nil {
		t.Skip("Cannot load config for DB test")
	}
	if err := InitDB(cfg); err != nil {
		t.Skip("Cannot connect to DB for test:", err)
	}

	// CH-G1: Non-existent or empty conversation/ext must return false
	if ok, _ := IsGroupAdmin(0, "19000"); ok {
		t.Fatal("IsGroupAdmin(0, ...) should return false")
	}
	if ok, _ := IsGroupAdmin(1, ""); ok {
		t.Fatal("IsGroupAdmin(..., '') should return false")
	}

	// CH-G3: En fazla 256 üye sınırı
	var excessiveMembers []string
	for i := 0; i < 300; i++ {
		excessiveMembers = append(excessiveMembers, fmt.Sprintf("ext%d", i))
	}
	_, _, err = CreateGroupConversation("Test Group", "19000", "", "", excessiveMembers)
	if err == nil {
		t.Fatal("CH-G3 violation: Expected error for group with >256 members")
	}

	// Find 2 real active users from DB
	rows, err := db.Query("SELECT extension FROM sys_users WHERE is_active = 1 AND extension IS NOT NULL AND extension != '' AND role != 'fax_user' ORDER BY id ASC LIMIT 2")
	if err != nil {
		t.Skip("Cannot query sys_users:", err)
	}
	var exts []string
	for rows.Next() {
		var e string
		if err := rows.Scan(&e); err == nil {
			exts = append(exts, e)
		}
	}
	rows.Close()

	if len(exts) < 2 {
		t.Skip("Need at least 2 active extensions for group test")
	}

	creatorExt := exts[0]
	memberExt := exts[1]

	// Create test group
	conv, sysMsg, err := CreateGroupConversation("CH-G Test Group", creatorExt, "", "Description", []string{memberExt})
	if err != nil {
		t.Fatalf("CreateGroupConversation failed: %v", err)
	}
	defer func() {
		_ = DeleteGroup(conv.ID, creatorExt)
		_, _ = db.Exec("DELETE FROM chat_messages WHERE conversation_id = ?", conv.ID)
		_, _ = db.Exec("DELETE FROM chat_participants WHERE conversation_id = ?", conv.ID)
		_, _ = db.Exec("DELETE FROM chat_conversations WHERE id = ?", conv.ID)
	}()

	if sysMsg == nil || sysMsg.SystemEvent != "group_created" {
		t.Fatalf("Expected group_created system message, got: %v", sysMsg)
	}

	// Verify creator is admin, member is not admin
	creatorIsAdmin, _ := IsGroupAdmin(conv.ID, creatorExt)
	if !creatorIsAdmin {
		t.Fatal("Creator must be group admin")
	}
	memberIsAdmin, _ := IsGroupAdmin(conv.ID, memberExt)
	if memberIsAdmin {
		t.Fatal("Regular member must not be group admin")
	}

	// Verify both are participants
	if isPart, _ := IsParticipant(conv.ID, creatorExt); !isPart {
		t.Fatal("Creator must be participant")
	}
	if isPart, _ := IsParticipant(conv.ID, memberExt); !isPart {
		t.Fatal("Added member must be participant")
	}
	if isPart, _ := IsParticipant(conv.ID, "nonexistent-ext"); isPart {
		t.Fatal("Non-member must not be participant")
	}

	// CH-G1 Fail-closed: HTTP handler tests
	srv := NewServer(cfg, NewHub())
	nonMemberUser := &User{Extension: "99999", FullName: "Attacker"}

	// 1. Non-member cannot get group details
	req, _ := http.NewRequest("GET", fmt.Sprintf("/api/conversations/group?conversation_id=%d", conv.ID), nil)
	rr := httptest.NewRecorder()
	srv.HandleGetGroupDetails(rr, req, nonMemberUser)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("CH-G1 violation: Expected 403 Forbidden for non-member GetGroupDetails, got %d", rr.Code)
	}

	// 2. Non-admin member cannot update group
	memberUser := &User{Extension: memberExt, FullName: "Member"}
	reqBody := fmt.Sprintf(`{"conversation_id":%d,"title":"Hacked Title"}`, conv.ID)
	req, _ = http.NewRequest("POST", "/api/conversations/group/update", strings.NewReader(reqBody))
	rr = httptest.NewRecorder()
	srv.HandleUpdateGroup(rr, req, memberUser)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("CH-G1 violation: Expected 403 Forbidden for non-admin HandleUpdateGroup, got %d", rr.Code)
	}

	// 3. Non-admin member cannot remove others
	reqBody = fmt.Sprintf(`{"conversation_id":%d,"extension":"%s"}`, conv.ID, creatorExt)
	req, _ = http.NewRequest("POST", "/api/conversations/group/members/remove", strings.NewReader(reqBody))
	rr = httptest.NewRecorder()
	srv.HandleRemoveGroupMember(rr, req, memberUser)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("CH-G1 violation: Expected 403 Forbidden for non-admin HandleRemoveGroupMember, got %d", rr.Code)
	}

	// 4. CH-G6: Gruptan çıkarılan üye (left_at) sonrasında katılımcı sayılmaz
	adminUser := &User{Extension: creatorExt, FullName: "Creator"}
	reqBody = fmt.Sprintf(`{"conversation_id":%d,"extension":"%s"}`, conv.ID, memberExt)
	req, _ = http.NewRequest("POST", "/api/conversations/group/members/remove", strings.NewReader(reqBody))
	rr = httptest.NewRecorder()
	srv.HandleRemoveGroupMember(rr, req, adminUser)
	if rr.Code != http.StatusOK {
		t.Fatalf("Admin failed to remove member: %d %s", rr.Code, rr.Body.String())
	}

	if isPart, _ := IsParticipant(conv.ID, memberExt); isPart {
		t.Fatal("CH-G6 violation: Removed member should NOT be active participant")
	}

	// 5. Admin promotes member back, tests leave and last admin promotion
	_, _, err = AddGroupMembers(conv.ID, creatorExt, []string{memberExt})
	if err != nil {
		t.Fatalf("Re-adding member failed: %v", err)
	}
	// Creator leaves group -> member should automatically be promoted to admin
	_, err = LeaveGroup(conv.ID, creatorExt)
	if err != nil {
		t.Fatalf("Creator LeaveGroup failed: %v", err)
	}
	promotedAdmin, _ := IsGroupAdmin(conv.ID, memberExt)
	if !promotedAdmin {
		t.Fatal("Remaining member should have been promoted to admin after last admin left")
	}
}
