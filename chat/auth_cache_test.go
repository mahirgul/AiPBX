package main

import (
	"encoding/base64"
	"strings"
	"testing"
	"time"
)

func TestCachedTokenCannotOutliveItsSignedExpiry(t *testing.T) {
	token := base64.StdEncoding.EncodeToString([]byte("1:1:" + strings.Repeat("a", 64)))
	storeAuthCache(token, cachedAuth{user: &User{ID: 1}, expiresAt: time.Now().Add(time.Minute)})
	t.Cleanup(func() { authCacheMu.Lock(); delete(authCache, token); authCacheMu.Unlock() })
	if user, err := ValidateBearerToken(token, "test-secret"); err == nil || user != nil {
		t.Fatal("expired token accepted from cache")
	}
}

func TestExpiredAuthCacheEntryIsEvicted(t *testing.T) {
	const token = "expired-cache-test"
	storeAuthCache(token, cachedAuth{user: &User{ID: 1}, expiresAt: time.Now().Add(-time.Second)})
	if _, ok := loadAuthCache(token); ok {
		t.Fatal("expired entry returned")
	}
	authCacheMu.RLock()
	_, remains := authCache[token]
	authCacheMu.RUnlock()
	if remains {
		t.Fatal("expired entry was not evicted")
	}
}
