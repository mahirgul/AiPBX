package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

const maxAuthCacheEntries = 4096

type cachedAuth struct {
	user      *User
	expiresAt time.Time
}

var (
	authCacheMu    sync.RWMutex
	authCache      = make(map[string]cachedAuth)
	authCacheSweep sync.Once
)

// sweepAuthCache drops expired entries; they were only removed when the same
// token came back, so the cache grew with every token ever seen.
func sweepAuthCache() {
	for range time.Tick(time.Minute) {
		now := time.Now()
		authCacheMu.Lock()
		for k, v := range authCache {
			if !now.Before(v.expiresAt) {
				delete(authCache, k)
			}
		}
		authCacheMu.Unlock()
	}
}

func loadAuthCache(tokenStr string) (*User, bool) {
	now := time.Now()
	authCacheMu.RLock()
	c, ok := authCache[tokenStr]
	authCacheMu.RUnlock()
	if !ok {
		return nil, false
	}
	if now.Before(c.expiresAt) {
		return c.user, true
	}
	authCacheMu.Lock()
	delete(authCache, tokenStr)
	authCacheMu.Unlock()
	return nil, false
}

func storeAuthCache(tokenStr string, entry cachedAuth) {
	now := time.Now()
	authCacheMu.Lock()
	defer authCacheMu.Unlock()
	if len(authCache) >= maxAuthCacheEntries {
		for k, v := range authCache {
			if !now.Before(v.expiresAt) {
				delete(authCache, k)
			}
		}
		// If still full after purging expired entries, drop one entry to stay bounded.
		if len(authCache) >= maxAuthCacheEntries {
			for k := range authCache {
				delete(authCache, k)
				break
			}
		}
	}
	authCache[tokenStr] = entry
}

func ValidateBearerToken(tokenStr string, secretKey string) (*User, error) {
	tokenStr = strings.TrimSpace(tokenStr)
	if tokenStr == "" || len(tokenStr) > 256 {
		return nil, fmt.Errorf("empty or oversized token")
	}

	if strings.Contains(tokenStr, "%") {
		if unescaped, err := url.PathUnescape(tokenStr); err == nil && unescaped != "" {
			tokenStr = unescaped
		}
	}

	authCacheSweep.Do(func() { go sweepAuthCache() })

	// Check cache
	if user, ok := loadAuthCache(tokenStr); ok {
		return user, nil
	}

	decodedBytes, err := base64.StdEncoding.DecodeString(tokenStr)
	if err != nil {
		return nil, fmt.Errorf("invalid base64 encoding")
	}

	parts := strings.Split(string(decodedBytes), ":")
	if len(parts) != 3 {
		return nil, fmt.Errorf("invalid token format")
	}

	userIDStr, expiresAtStr, sig := parts[0], parts[1], parts[2]
	if len(sig) != sha256.Size*2 {
		return nil, fmt.Errorf("invalid signature format")
	}
	if _, err := hex.DecodeString(sig); err != nil {
		return nil, fmt.Errorf("invalid signature encoding")
	}

	expiresAt, err := strconv.ParseInt(expiresAtStr, 10, 64)
	if err != nil {
		return nil, fmt.Errorf("invalid expiration timestamp")
	}

	if time.Now().Unix() > expiresAt {
		return nil, fmt.Errorf("token expired")
	}

	userID, err := strconv.Atoi(userIDStr)
	if err != nil || userID <= 0 || strconv.Itoa(userID) != userIDStr {
		return nil, fmt.Errorf("invalid user id")
	}

	user, err := GetUserByID(userID)
	if err != nil {
		return nil, fmt.Errorf("user not found or inactive: %w", err)
	}

	// The signed text is the same as mobileTokenPayload() in PHP: "id:exp"
	// while token_epoch is 0, otherwise "id:exp:epoch". The epoch increments on
	// a password reset and old tokens drop here (the cache lags at most 60 s).
	payload := userIDStr + ":" + expiresAtStr
	if user.TokenEpoch > 0 {
		payload += ":" + strconv.FormatInt(user.TokenEpoch, 10)
	}
	mac := hmac.New(sha256.New, []byte(secretKey))
	mac.Write([]byte(payload))
	expectedSig := hex.EncodeToString(mac.Sum(nil))

	if !hmac.Equal([]byte(sig), []byte(expectedSig)) {
		return nil, fmt.Errorf("invalid signature")
	}

	if user.Extension == "" || user.Role == "fax_user" {
		return nil, fmt.Errorf("user has no assigned chat extension")
	}

	// Cache for 60 seconds
	storeAuthCache(tokenStr, cachedAuth{
		user:      user,
		expiresAt: time.Now().Add(60 * time.Second),
	})

	return user, nil
}

func ExtractToken(r *http.Request) string {
	// 1. Authorization header: Bearer <token>
	authHeader := r.Header.Get("Authorization")
	if authHeader != "" {
		parts := strings.Fields(authHeader)
		if len(parts) == 2 && strings.EqualFold(parts[0], "Bearer") {
			tok := parts[1]
			if strings.Contains(tok, "%") {
				if unescaped, err := url.PathUnescape(tok); err == nil {
					return unescaped
				}
			}
			return tok
		}
	}

	// 2. Query param ?token=... (ONLY for WebSocket /ws or /media/ requests, CH-10)
	// On a WebSocket connection the browser passes the query param directly, so it comes before the cookie.
	// r.URL.Query() already unescapes percent-encoding; do not call QueryUnescape again (which turns '+' into ' ').
	path := r.URL.Path
	if strings.HasSuffix(path, "/ws") || strings.Contains(path, "/media/") {
		if qToken := r.URL.Query().Get("token"); qToken != "" {
			if strings.Contains(qToken, " ") {
				qToken = strings.ReplaceAll(qToken, " ", "+")
			}
			return qToken
		}
	}

	// 3. Cookie: ONLY for /media/ (<img> tags cannot send a header). A browser
	// adds the cookie to requests any page makes, and a WebSocket handshake
	// has no CORS: with the cookie on /ws or /api/ another site could act as
	// the user (cross-site WebSocket hijacking, CSRF). Every client sends the
	// token in the Authorization header or ?token= there.
	if !strings.Contains(path, "/media/") {
		return ""
	}
	if cookie, err := r.Cookie("chat_token"); err == nil && cookie.Value != "" {
		val := cookie.Value
		if strings.Contains(val, "%") {
			if unescaped, err := url.PathUnescape(val); err == nil {
				return unescaped
			}
		}
		return val
	}

	return ""
}
