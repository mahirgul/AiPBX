package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"path"
	"strings"
)

// Yüklenen dosya adları "<unix>_<rastgele>_<imza>" biçimindedir; imza
// yükleyenin dahilisine bağlıdır. Mesaja/grup resmine yalnızca göndericinin
// KENDİ yüklediği dosya iliştirilebilir. Önceden attachment_url istemciden
// olduğu gibi alınıyordu: başka bir sohbetin dosya adını bilen biri onu kendi
// sohbetine "iliştirip" /chat/media üzerinden indirebiliyordu (gruptan
// çıkarılan üye de erişimini böyle sürdürebilirdi).

func uploadSignature(secret, ext, base string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("chat-upload:" + ext + ":" + base))
	return hex.EncodeToString(mac.Sum(nil))[:16]
}

// signedUploadBase yükleme için imzalı dosya adı gövdesini döndürür.
func signedUploadBase(secret, ext, base string) string {
	return base + "_" + uploadSignature(secret, ext, base)
}

// attachmentOwnedBy, URL'deki dosyanın bu dahili tarafından yüklendiğini doğrular.
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

// validMediaURL: yalnızca kendi sunucumuzun medya yolları (XSS/izleme pikseli önleme).
func validMediaURL(u string) bool {
	for _, p := range []string{"/chat/media/images/", "/chat/media/docs/", "/chat/media/thumbs/", "/chat/media/avatars/",
		"/media/images/", "/media/docs/", "/media/thumbs/", "/media/avatars/"} {
		if strings.HasPrefix(u, p) {
			return !strings.Contains(u, "..")
		}
	}
	return false
}
