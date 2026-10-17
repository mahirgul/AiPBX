package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func notifyRequest(body, remote string, proxied bool) *http.Request {
	req := httptest.NewRequest("POST", "/api/internal/notify", strings.NewReader(body))
	req.RemoteAddr = remote
	if proxied {
		req.Header.Set("X-Forwarded-For", "203.0.113.9")
	}
	return req
}

func TestInternalNotifyReachesTheExtensionsDevices(t *testing.T) {
	h := NewHub()
	srv := NewServer(&Config{}, h)
	c := newTestClient(h, "1001", false)

	rr := httptest.NewRecorder()
	srv.HandleInternalNotify(rr, notifyRequest(`{"extension":"1001","event":"voicemail","data":{"caller":"0532","duration":42,"new_count":2}}`, "127.0.0.1:5555", false))
	if rr.Code != http.StatusOK || !strings.Contains(rr.Body.String(), `"delivered":true`) {
		t.Fatalf("expected delivery, got %d %s", rr.Code, rr.Body.String())
	}
	var ev map[string]interface{}
	if err := json.Unmarshal(<-c.send, &ev); err != nil || ev["event"] != "voicemail" {
		t.Fatalf("device got %v (%v)", ev, err)
	}
	if data, _ := ev["data"].(map[string]interface{}); data["caller"] != "0532" {
		t.Fatalf("data not passed on: %v", ev)
	}
}

func TestInternalNotifyIsLocalAndWhitelisted(t *testing.T) {
	srv := NewServer(&Config{}, NewHub())
	cases := []struct {
		name, body, remote string
		proxied            bool
		want               int
	}{
		{"through the Apache proxy", `{"extension":"1001","event":"voicemail"}`, "127.0.0.1:1", true, http.StatusForbidden},
		{"from another host", `{"extension":"1001","event":"voicemail"}`, "198.51.100.7:1", false, http.StatusForbidden},
		{"an event that is not allowed", `{"extension":"1001","event":"new_message"}`, "127.0.0.1:1", false, http.StatusBadRequest},
		{"no extension", `{"event":"voicemail"}`, "127.0.0.1:1", false, http.StatusBadRequest},
	}
	for _, tc := range cases {
		rr := httptest.NewRecorder()
		srv.HandleInternalNotify(rr, notifyRequest(tc.body, tc.remote, tc.proxied))
		if rr.Code != tc.want {
			t.Errorf("%s: got %d, want %d", tc.name, rr.Code, tc.want)
		}
	}
}

func TestInternalNotifyAndPresenceRequireHMACWhenConfigured(t *testing.T) {
	srv := NewServer(&Config{SecretKey: "chat-secret"}, NewHub())
	tok := internalToken("chat-secret")

	// Without X-AiPBX-Internal header: forbidden
	rr := httptest.NewRecorder()
	srv.HandleInternalNotify(rr, notifyRequest(`{"extension":"1001","event":"voicemail"}`, "127.0.0.1:5555", false))
	if rr.Code != http.StatusForbidden {
		t.Fatalf("expected 403 without HMAC token on notify, got %d", rr.Code)
	}

	// With valid X-AiPBX-Internal header: OK
	req := notifyRequest(`{"extension":"1001","event":"voicemail"}`, "127.0.0.1:5555", false)
	req.Header.Set("X-AiPBX-Internal", tok)
	rr = httptest.NewRecorder()
	srv.HandleInternalNotify(rr, req)
	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200 with valid HMAC token on notify, got %d", rr.Code)
	}

	// Presence without token: forbidden
	preq := httptest.NewRequest(http.MethodGet, "/api/internal/presence", nil)
	preq.RemoteAddr = "127.0.0.1:5555"
	rr = httptest.NewRecorder()
	srv.HandleInternalPresence(rr, preq)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("expected 403 without HMAC token on presence, got %d", rr.Code)
	}

	// Presence with valid token: OK
	preq = httptest.NewRequest(http.MethodGet, "/api/internal/presence", nil)
	preq.RemoteAddr = "127.0.0.1:5555"
	preq.Header.Set("X-AiPBX-Internal", tok)
	rr = httptest.NewRecorder()
	srv.HandleInternalPresence(rr, preq)
	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200 with valid HMAC token on presence, got %d", rr.Code)
	}
}
