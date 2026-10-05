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
	name := path.Base(url)
	name = strings.TrimSuffix(name, path.Ext(name))
	name = strings.TrimSuffix(name, "_thumb")
	i := strings.LastIndex(name, "_")
	if i <= 0 || ext == "" {
		return false
	}
	base, sig := name[:i], name[i+1:]
	return hmac.Equal([]byte(sig), []byte(uploadSignature(secret, ext, base)))
}

// validMediaURL: only our own server's media paths (prevents XSS/tracking pixels).
func validMediaURL(u string) bool {
	for _, p := range []string{"/chat/media/images/", "/chat/media/docs/", "/chat/media/thumbs/", "/chat/media/avatars/",
		"/media/images/", "/media/docs/", "/media/thumbs/", "/media/avatars/"} {
		if strings.HasPrefix(u, p) {
			return !strings.Contains(u, "..")
		}
	}
	return false
}
