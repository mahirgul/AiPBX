package main

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestValidPbxKey(t *testing.T) {
	for key, want := range map[string]bool{
		"recordings/2026/10/14/q-1001-1791.mp3": true,
		"faxes/in/fax_12.pdf":                   true,
		"voicemail/1001/msg0003.wav":            true,
		"docs/x.pdf":                            false, // chat files are not PBX files
		"recordings/../etc/passwd":              false,
		"recordings/.hidden":                    false,
		"recordings/a b.mp3":                    false,
		"recordings/":                           false,
		"/recordings/x.mp3":                     false,
	} {
		if got := validPbxKey(key); got != want {
			t.Errorf("validPbxKey(%q) = %v", key, got)
		}
	}
}

func TestPbxFilesRoundTripInTheBucket(t *testing.T) {
	fake, s3srv := newFakeS3(t)
	settings := map[string]string{"storage_backend": "local"}
	st := NewStorage(t.TempDir(), "my-env-key")
	st.loadSettings = func() (map[string]string, error) { return settings, nil }
	srv := &Server{cfg: &Config{SecretKey: "chat-secret"}, storage: st}
	tok := internalToken("chat-secret")
	call := func(method, key, body string) *httptest.ResponseRecorder {
		req := httptest.NewRequest(method, "/api/internal/files?key="+key, strings.NewReader(body))
		req.RemoteAddr = "127.0.0.1:5000"
		req.Header.Set("X-AiPBX-Internal", tok)
		rr := httptest.NewRecorder()
		srv.HandlePbxFiles(rr, req)
		return rr
	}

	if rr := call(http.MethodPut, "recordings/a.mp3", "x"); rr.Code != http.StatusConflict {
		t.Fatalf("without a bucket: %d", rr.Code)
	}

	// A bucket is configured, chat files still on the local disk.
	settings = map[string]string{
		"storage_backend": "local", "storage_s3_endpoint": s3srv.URL, "storage_s3_bucket": "chat",
		"storage_s3_access_key": "AK", "storage_s3_path_style": "1",
		"storage_s3_secret_key": "sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBCJQMjsJDS9Z8c1HrHcrovYJdWOpuWnck",
	}
	st.loadedAt = time.Time{}
	if rr := call(http.MethodPut, "recordings/2026/10/14/a.mp3", "ID3audio"); rr.Code != http.StatusOK {
		t.Fatalf("put: %d %s", rr.Code, rr.Body.String())
	}
	if _, ok := fake.objects["/chat/recordings/2026/10/14/a.mp3"]; !ok {
		t.Fatalf("not in the bucket: %v", fake.objects)
	}
	if rr := call(http.MethodGet, "recordings/2026/10/14/a.mp3", ""); rr.Code != http.StatusOK || rr.Body.String() != "ID3audio" {
		t.Fatalf("get: %d %q", rr.Code, rr.Body.String())
	}
	if rr := call(http.MethodGet, "recordings/missing.mp3", ""); rr.Code != http.StatusNotFound {
		t.Fatalf("missing: %d", rr.Code)
	}
	if rr := call(http.MethodDelete, "recordings/2026/10/14/a.mp3", ""); rr.Code != http.StatusOK {
		t.Fatalf("delete: %d", rr.Code)
	}
	if _, ok := fake.objects["/chat/recordings/2026/10/14/a.mp3"]; ok {
		t.Fatal("still in the bucket after delete")
	}
	if rr := call(http.MethodGet, "docs/x.pdf", ""); rr.Code != http.StatusBadRequest {
		t.Fatalf("chat key: %d", rr.Code)
	}

	// No token, or through the proxy: refused.
	req := httptest.NewRequest(http.MethodGet, "/api/internal/files?key=recordings/a.mp3", nil)
	req.RemoteAddr = "127.0.0.1:5000"
	rr := httptest.NewRecorder()
	srv.HandlePbxFiles(rr, req)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("no token: %d", rr.Code)
	}
}
