package main

import (
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestSameOrigin(t *testing.T) {
	cases := []struct {
		name, origin, host, fwdHost string
		want                        bool
	}{
		{"no origin (native app)", "", "127.0.0.1:8086", "", true},
		{"same host via proxy", "https://pbx.example.com", "127.0.0.1:8086", "pbx.example.com", true},
		{"same host, case differs", "https://PBX.example.com", "127.0.0.1:8086", "pbx.example.com", true},
		{"proxy host list", "https://pbx.example.com", "127.0.0.1:8086", "pbx.example.com, 10.0.0.1", true},
		{"direct request", "http://localhost:8086", "localhost:8086", "", true},
		{"foreign site", "https://evil.example.net", "127.0.0.1:8086", "pbx.example.com", false},
		{"sibling subdomain", "https://evil.pbx.example.com", "127.0.0.1:8086", "pbx.example.com", false},
		{"opaque origin", "null", "127.0.0.1:8086", "pbx.example.com", false},
	}
	for _, c := range cases {
		r := httptest.NewRequest("GET", "/chat/ws", nil)
		r.Host = c.host
		if c.origin != "" {
			r.Header.Set("Origin", c.origin)
		}
		if c.fwdHost != "" {
			r.Header.Set("X-Forwarded-Host", c.fwdHost)
		}
		if got := SameOrigin(r); got != c.want {
			t.Errorf("%s: SameOrigin = %v, want %v", c.name, got, c.want)
		}
	}
}

func TestTokenFromCookie(t *testing.T) {
	cookieOnly := httptest.NewRequest("GET", "/chat/ws", nil)
	cookieOnly.AddCookie(&http.Cookie{Name: "chat_token", Value: "c"})
	if !TokenFromCookie(cookieOnly) {
		t.Error("cookie-only request must count as cookie-authenticated")
	}

	withQuery := httptest.NewRequest("GET", "/chat/ws?token=q", nil)
	withQuery.AddCookie(&http.Cookie{Name: "chat_token", Value: "c"})
	if TokenFromCookie(withQuery) {
		t.Error("?token= wins over the cookie")
	}

	withHeader := httptest.NewRequest("GET", "/chat/ws", nil)
	withHeader.Header.Set("Authorization", "Bearer h")
	withHeader.AddCookie(&http.Cookie{Name: "chat_token", Value: "c"})
	if TokenFromCookie(withHeader) {
		t.Error("Authorization header wins over the cookie")
	}

	if TokenFromCookie(httptest.NewRequest("GET", "/chat/ws", nil)) {
		t.Error("no token at all is not a cookie token")
	}
}

// A cross-site page can make the browser send the chat_token cookie with a
// WebSocket handshake; HandleWS must refuse it before looking at the token.
func TestHandleWSRejectsForeignOriginCookie(t *testing.T) {
	srv := NewServer(&Config{SecretKey: "test-secret-not-used-anywhere"}, NewHub())
	r := httptest.NewRequest("GET", "/chat/ws", nil)
	r.Header.Set("Origin", "https://evil.example.net")
	r.Header.Set("X-Forwarded-Host", "pbx.example.com")
	r.AddCookie(&http.Cookie{Name: "chat_token", Value: "anything"})
	rr := httptest.NewRecorder()
	srv.HandleWS(rr, r)
	if rr.Code != http.StatusForbidden {
		t.Fatalf("foreign-origin cookie request: got %d, want 403", rr.Code)
	}
}

func TestPruneAuthCache(t *testing.T) {
	now := time.Now()
	authCache.Store("expired-token", cachedAuth{user: &User{}, expiresAt: now.Add(-time.Second)})
	authCache.Store("fresh-token", cachedAuth{user: &User{}, expiresAt: now.Add(time.Minute)})
	defer authCache.Delete("fresh-token")

	PruneAuthCache(now)

	if _, ok := authCache.Load("expired-token"); ok {
		t.Error("expired entry was not pruned")
	}
	if _, ok := authCache.Load("fresh-token"); !ok {
		t.Error("live entry was pruned")
	}
}

func TestValidateBearerTokenRejectsMalformedSignature(t *testing.T) {
	future := strconv.FormatInt(time.Now().Add(time.Hour).Unix(), 10)
	for name, sig := range map[string]string{
		"short":   "abc",
		"not hex": strings.Repeat("z", 64),
	} {
		tok := base64.StdEncoding.EncodeToString([]byte("1:" + future + ":" + sig))
		if _, err := ValidateBearerToken(tok, "s"); err == nil || err.Error() != "invalid signature" {
			t.Errorf("%s: got %v, want invalid signature", name, err)
		}
	}
}
