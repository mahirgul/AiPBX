package main

import "testing"

func TestAttachmentOwnership(t *testing.T) {
	const secret = "test-secret"
	base := signedUploadBase(secret, "3001", "1790000000_0123456789abcdef")

	if !attachmentOwnedBy(secret, "/chat/media/images/"+base+".jpg", "3001") {
		t.Fatal("yükleyen kendi dosyasını iliştirebilmeli")
	}
	if !attachmentOwnedBy(secret, "/chat/media/thumbs/"+base+"_thumb.jpg", "3001") {
		t.Fatal("thumbnail de aynı imzayı taşımalı")
	}
	if attachmentOwnedBy(secret, "/chat/media/images/"+base+".jpg", "3002") {
		t.Fatal("başkasının yüklediği dosya iliştirilememeli")
	}
	if attachmentOwnedBy(secret, "/chat/media/images/1790000000_0123456789abcdef.jpg", "3001") {
		t.Fatal("imzasız (eski) ad yeni mesaja iliştirilememeli")
	}
	if attachmentOwnedBy("other-secret", "/chat/media/images/"+base+".jpg", "3001") {
		t.Fatal("imza sunucu anahtarına bağlı olmalı")
	}
}

func TestValidMediaURL(t *testing.T) {
	for _, u := range []string{"/chat/media/images/a.jpg", "/media/docs/b.pdf", "/chat/media/avatars/c.png"} {
		if !validMediaURL(u) {
			t.Fatalf("geçerli sayılmalı: %s", u)
		}
	}
	for _, u := range []string{"https://evil.example/p.png", "javascript:alert(1)", "/chat/media/images/../../x", "/etc/passwd"} {
		if validMediaURL(u) {
			t.Fatalf("reddedilmeli: %s", u)
		}
	}
}
