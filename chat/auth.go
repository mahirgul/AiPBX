package main

import (
	"context"
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

type cachedAuth struct {
	user      *User
	expiresAt time.Time
}

var (
	authCache sync.Map
)

// PruneAuthCache drops expired entries. An entry is otherwise only removed
// when the same token is presented again after it expired, so tokens that are
// never reused (every web chat page load issues a new one) would pile up.
func PruneAuthCache(now time.Time) {
	authCache.Range(func(key, val any) bool {
		if c, ok := val.(cachedAuth); !ok || !now.Before(c.expiresAt) {
			authCache.Delete(key)
		}
		return true
	})
}

// StartAuthCachePruner runs PruneAuthCache every interval until ctx is done.
func StartAuthCachePruner(ctx context.Context, interval time.Duration) {
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case now := <-ticker.C:
				PruneAuthCache(now)
			}
		}
	}()
}

func ValidateBearerToken(tokenStr string, secretKey string) (*User, error) {
	tokenStr = strings.TrimSpace(tokenStr)
	if tokenStr == "" {
		return nil, fmt.Errorf("empty token")
	}

	if strings.Contains(tokenStr, "%") {
		if unescaped, err := url.QueryUnescape(tokenStr); err == nil && unescaped != "" {
			tokenStr = unescaped
		}
	}

	// Check cache
	if val, ok := authCache.Load(tokenStr); ok {
		c := val.(cachedAuth)
		if time.Now().Before(c.expiresAt) {
			return c.user, nil
		}
		authCache.Delete(tokenStr)
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

	expiresAt, err := strconv.ParseInt(expiresAtStr, 10, 64)
	if err != nil {
		return nil, fmt.Errorf("invalid expiration timestamp")
	}

	if time.Now().Unix() > expiresAt {
		return nil, fmt.Errorf("token expired")
	}

	// The signature is hex SHA-256 (64 chars). Checking the shape here turns
	// most garbage tokens away before the DB lookup below; the HMAC itself
	// can only be verified after it, since it covers the user's token_epoch.
	if len(sig) != 64 {
		return nil, fmt.Errorf("invalid signature")
	}
	if _, err := hex.DecodeString(sig); err != nil {
		return nil, fmt.Errorf("invalid signature")
	}

	userID, err := strconv.Atoi(userIDStr)
	if err != nil || strconv.Itoa(userID) != userIDStr {
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

	if user.Extension == "" {
		return nil, fmt.Errorf("user has no assigned extension")
	}

	// Cache for 60 seconds
	authCache.Store(tokenStr, cachedAuth{
		user:      user,
		expiresAt: time.Now().Add(60 * time.Second),
	})

	return user, nil
}

// TokenFromCookie reports whether ExtractToken would fall back to the
// chat_token cookie, i.e. the request carries no Authorization header and no
// ?token= query parameter. A browser attaches that cookie on its own, so only
// such requests can be forged by another page (cross-site WebSocket hijacking).
func TokenFromCookie(r *http.Request) bool {
	if strings.TrimSpace(r.Header.Get("Authorization")) != "" || r.URL.Query().Get("token") != "" {
		return false
	}
	cookie, err := r.Cookie("chat_token")
	return err == nil && cookie.Value != ""
}

// SameOrigin reports whether the request's Origin header (if any) names the
// host the request was sent to. Behind Apache's ProxyPass the original host
// is in X-Forwarded-Host; r.Host is then 127.0.0.1:8086. Requests without
// an Origin header (native apps, curl) are not browser-initiated and pass.
func SameOrigin(r *http.Request) bool {
	origin := r.Header.Get("Origin")
	if origin == "" {
		return true
	}
	u, err := url.Parse(origin)
	if err != nil || u.Host == "" {
		return false
	}
	host := r.Header.Get("X-Forwarded-Host")
	if host == "" {
		host = r.Host
	}
	host = strings.TrimSpace(strings.Split(host, ",")[0])
	return strings.EqualFold(u.Host, host)
}

func ExtractToken(r *http.Request) string {
	// 1. Authorization header: Bearer <token>
	authHeader := r.Header.Get("Authorization")
	if authHeader != "" {
		parts := strings.Fields(authHeader)
		if len(parts) == 2 && strings.EqualFold(parts[0], "Bearer") {
			tok := parts[1]
			if strings.Contains(tok, "%") {
				if unescaped, err := url.QueryUnescape(tok); err == nil {
					return unescaped
				}
			}
			return tok
		}
	}

	// 2. Query param ?token=... (ONLY for WebSocket /ws or /media/ requests, CH-10)
	// On a WebSocket connection the browser passes the query param directly, so it comes before the cookie
	path := r.URL.Path
	if strings.HasSuffix(path, "/ws") || strings.Contains(path, "/media/") {
		if qToken := r.URL.Query().Get("token"); qToken != "" {
			if strings.Contains(qToken, "%") {
				if unescaped, err := url.QueryUnescape(qToken); err == nil {
					return unescaped
				}
			}
			return qToken
		}
	}

	// 3. Cookie (if any, especially for /media/ in <img> tags)
	if cookie, err := r.Cookie("chat_token"); err == nil && cookie.Value != "" {
		val := cookie.Value
		if strings.Contains(val, "%") {
			if unescaped, err := url.QueryUnescape(val); err == nil {
				return unescaped
			}
		}
		return val
	}

	return ""
}
