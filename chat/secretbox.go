package main

import (
	"crypto/hkdf"
	"crypto/sha256"
	"encoding/base64"
	"os"
	"strings"

	"golang.org/x/crypto/nacl/secretbox"
)

// Reads credentials the portal stored encrypted in sys_settings (the S3
// secret key). The counterpart of web/src/secret_box.php: "sb1:" +
// base64(nonce || secretbox), with AIPBX_SETTINGS_KEY from /etc/ai-pbx.env
// (through HKDF) or else the raw key file /var/lib/aipbx/settings.key. The
// chat service runs as www-data, which owns that file.

const (
	secretBoxPrefix  = "sb1:"
	settingsKeyFile  = "/var/lib/aipbx/settings.key"
	secretBoxKeySize = 32
	secretBoxNonce   = 24
)

// settingsKey returns the key, or nil when there is none yet.
func settingsKey(envKey string) *[secretBoxKeySize]byte {
	var key [secretBoxKeySize]byte
	if envKey != "" {
		k, err := hkdf.Key(sha256.New, []byte(envKey), nil, "aipbx-settings", secretBoxKeySize)
		if err != nil {
			return nil
		}
		copy(key[:], k)
		return &key
	}
	raw, err := os.ReadFile(settingsKeyFile)
	if err != nil || len(raw) != secretBoxKeySize {
		return nil
	}
	copy(key[:], raw)
	return &key
}

// openSecret decrypts a stored value; "" when it is empty, not encrypted or
// cannot be opened (other key, damaged), like SecretBox::decrypt.
func openSecret(stored string, key *[secretBoxKeySize]byte) string {
	if key == nil || !strings.HasPrefix(stored, secretBoxPrefix) {
		return ""
	}
	raw, err := base64.StdEncoding.DecodeString(stored[len(secretBoxPrefix):])
	if err != nil || len(raw) <= secretBoxNonce {
		return ""
	}
	var nonce [secretBoxNonce]byte
	copy(nonce[:], raw[:secretBoxNonce])
	plain, ok := secretbox.Open(nil, raw[secretBoxNonce:], &nonce, key)
	if !ok {
		return ""
	}
	return string(plain)
}
