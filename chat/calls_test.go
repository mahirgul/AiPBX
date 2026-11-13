package main

import (
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
)

func TestChatRoomIsTwelveRandomDigits(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		room, err := newChatRoom()
		if err != nil || !regexp.MustCompile(`^[0-9]{12}$`).MatchString(room) {
			t.Fatalf("room %q, err %v", room, err)
		}
		seen[room] = true
	}
	if len(seen) < 50 {
		t.Fatalf("rooms repeat: %d different of 50", len(seen))
	}
}

func TestChatCallMetaCarriesIdVideoAndActor(t *testing.T) {
	meta := chatCallMeta(&ChatCall{ID: 7, Video: true, StartedBy: "1001"}, map[string]string{"duration": "65"})
	for _, want := range []string{`"call_id":"7"`, `"video":"1"`, `"actor":"1001"`, `"duration":"65"`} {
		if !strings.Contains(meta, want) {
			t.Fatalf("meta %s lacks %s", meta, want)
		}
	}
}

func TestCallEndpointNeedsAConversationOrACall(t *testing.T) {
	s := NewServer(&Config{}, NewHub())
	for _, body := range []string{`{}`, `not json`, `{"conversation_id":0,"call_id":0}`} {
		rec := httptest.NewRecorder()
		s.HandleCall(rec, httptest.NewRequest(http.MethodPost, "/api/conversations/call", strings.NewReader(body)), &User{Extension: "1001"})
		if rec.Code != http.StatusBadRequest {
			t.Fatalf("body %s: want 400, got %d", body, rec.Code)
		}
	}
	rec := httptest.NewRecorder()
	s.HandleCall(rec, httptest.NewRequest(http.MethodGet, "/api/conversations/call", nil), &User{Extension: "1001"})
	if rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("GET: want 405, got %d", rec.Code)
	}
}

func TestCallEndedIsForThePbxOnly(t *testing.T) {
	s := NewServer(&Config{}, NewHub())
	req := httptest.NewRequest(http.MethodPost, "/api/internal/call/ended", strings.NewReader(`{"room":"123456789012"}`))
	req.RemoteAddr = "203.0.113.9:40000"
	rec := httptest.NewRecorder()
	s.HandleInternalCallEnded(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("a request from outside: want 403, got %d", rec.Code)
	}
}
