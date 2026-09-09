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

type cachedAuth struct {
	user      *User
	expiresAt time.Time
}

var (
	authCache sync.Map
)

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

	// Compute HMAC SHA-256
	mac := hmac.New(sha256.New, []byte(secretKey))
	mac.Write([]byte(userIDStr + ":" + expiresAtStr))
	expectedSig := hex.EncodeToString(mac.Sum(nil))

	if !hmac.Equal([]byte(sig), []byte(expectedSig)) {
		return nil, fmt.Errorf("invalid signature")
	}

	userID, err := strconv.Atoi(userIDStr)
	if err != nil {
		return nil, fmt.Errorf("invalid user id")
	}

	user, err := GetUserByID(userID)
	if err != nil {
		return nil, fmt.Errorf("user not found or inactive: %w", err)
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

	// 2. Query param ?token=... (YALNIZCA WebSocket /ws veya /media/ istekleri için, CH-10)
	// WebSocket bağlantısında browser doğrudan query param geçtiği için cookie'nin önüne alınır
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

	// 3. Cookie (if any, özellikle /media/ için <img> etiketlerinde)
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
