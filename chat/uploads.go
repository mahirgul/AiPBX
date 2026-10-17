package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"path"
	"strings"
)

// Uploaded file names look like "<unix>_<random>_<signature>"; the signature
// is tied to the uploader's extension. Only a file the sender uploaded
// THEMSELVES can be attached to a message/group picture. attachment_url used
// to be taken from the client as is: someone who knew another chat's file name
// could "attach" it to their own chat and download it through /chat/media (a
// member removed from a group could keep their access that way too).

func uploadSignature(secret, ext, base string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("chat-upload:" + ext + ":" + base))
	return hex.EncodeToString(mac.Sum(nil))[:16]
}

// signedUploadBase returns the signed file name stem for an upload.
func signedUploadBase(secret, ext, base string) string {
	return base + "_" + uploadSignature(secret, ext, base)
}

// attachmentOwnedBy checks that the file in the URL was uploaded by this extension.
func attachmentOwnedBy(secret, url, ext string) bool {
	if secret == "" || ext == "" || url == "" {
		return false
	}
	rel := strings.TrimPrefix(strings.TrimPrefix(url, "/chat/media/"), "/media/")
	if strings.Contains(rel, "/") && !validMediaKey(rel) {
		return false
	}
	name := path.Base(rel)
	name = strings.TrimSuffix(name, path.Ext(name))
	name = strings.TrimSuffix(name, "_thumb")
	i := strings.LastIndex(name, "_")
	if i <= 0 {
		return false
	}
	base, sig := name[:i], name[i+1:]
	return hmac.Equal([]byte(sig), []byte(uploadSignature(secret, ext, base)))
}

// validMediaURL: only our own server's media paths (prevents XSS/tracking pixels).
func validMediaURL(u string) bool {
	var key string
	switch {
	case strings.HasPrefix(u, "/chat/media/"):
		key = strings.TrimPrefix(u, "/chat/media/")
	case strings.HasPrefix(u, "/media/"):
		key = strings.TrimPrefix(u, "/media/")
	default:
		return false
	}
	if strings.Contains(u, "..") || strings.ContainsAny(key, " \t\r\n?#") {
		return false
	}
	return validMediaKey(key)
}
