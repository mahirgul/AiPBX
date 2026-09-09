package main

import (
	"bytes"
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
