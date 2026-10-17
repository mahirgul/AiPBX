package main

import "testing"

func TestAttachmentOwnership(t *testing.T) {
	const secret = "test-secret"
	base := signedUploadBase(secret, "3001", "1790000000_0123456789abcdef")

	if !attachmentOwnedBy(secret, "/chat/media/images/"+base+".jpg", "3001") {
		t.Fatal("the uploader must be able to attach their own file")
	}
	if !attachmentOwnedBy(secret, "/chat/media/thumbs/"+base+"_thumb.jpg", "3001") {
		t.Fatal("the thumbnail must carry the same signature")
	}
	if attachmentOwnedBy(secret, "/chat/media/images/"+base+".jpg", "3002") {
		t.Fatal("a file uploaded by someone else must not be attachable")
	}
	if attachmentOwnedBy(secret, "/chat/media/images/1790000000_0123456789abcdef.jpg", "3001") {
		t.Fatal("an unsigned (old) name must not be attachable to a new message")
	}
	if attachmentOwnedBy("other-secret", "/chat/media/images/"+base+".jpg", "3001") {
		t.Fatal("the signature must depend on the server key")
	}
	if attachmentOwnedBy(secret, "/chat/media/images/extra/"+base+".jpg", "3001") {
		t.Fatal("extra path segments must be rejected by attachmentOwnedBy")
	}
}

func TestValidMediaURL(t *testing.T) {
	for _, u := range []string{"/chat/media/images/a.jpg", "/media/docs/b.pdf", "/chat/media/avatars/c.png"} {
		if !validMediaURL(u) {
			t.Fatalf("must be valid: %s", u)
		}
	}
	for _, u := range []string{
		"https://evil.example/p.png",
		"javascript:alert(1)",
		"/chat/media/images/../../x",
		"/etc/passwd",
		"/chat/media/images/sub/a.jpg",
		"/chat/media/images/",
		"/chat/media/images/.hidden.jpg",
		"/chat/media/images/a.jpg?x=1",
	} {
		if validMediaURL(u) {
			t.Fatalf("reddedilmeli: %s", u)
		}
	}
}
