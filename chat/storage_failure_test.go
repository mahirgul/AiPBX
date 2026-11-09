package main

import (
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type failingUploadReader struct{ sent bool }

func (r *failingUploadReader) Read(p []byte) (int, error) {
	if r.sent {
		return 0, errors.New("upload connection lost")
	}
	r.sent = true
	return copy(p, "partial data"), nil
}

func TestInterruptedLocalUploadLeavesNoPartialFile(t *testing.T) {
	store := &LocalStore{dir: t.TempDir()}
	ctx := context.Background()
	err := store.Put(ctx, "docs/interrupted.pdf", &failingUploadReader{}, 100, "application/pdf")
	if err == nil {
		t.Fatal("interrupted upload reported success")
	}
	if _, err := os.Stat(filepath.Join(store.dir, "docs/interrupted.pdf")); !os.IsNotExist(err) {
		t.Fatal("partial upload remains", err)
	}
	if err := store.Put(ctx, "docs/interrupted.pdf", strings.NewReader("complete"), 8, "application/pdf"); err != nil {
		t.Fatal(err)
	}
	r, n, err := store.Get(ctx, "docs/interrupted.pdf")
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	b, err := io.ReadAll(r)
	if err != nil || n != 8 || string(b) != "complete" {
		t.Fatalf("retry failed: %q %d %v", b, n, err)
	}
}

func TestMissingLocalAttachmentHasConsistentSemantics(t *testing.T) {
	store := &LocalStore{dir: t.TempDir()}
	ctx := context.Background()
	if _, _, err := store.Get(ctx, "docs/missing.pdf"); !errors.Is(err, ErrNotFound) {
		t.Fatal(err)
	}
	if _, err := store.Size(ctx, "docs/missing.pdf"); !errors.Is(err, ErrNotFound) {
		t.Fatal(err)
	}
	if err := store.Delete(ctx, "docs/missing.pdf"); err != nil {
		t.Fatal(err)
	}
}

func TestAttachmentSignatureCannotBeMovedToAnotherUpload(t *testing.T) {
	base := signedUploadBase("secret", "3001", "1790000000_0123456789abcdef")
	forged := strings.Replace(base, "0123456789abcdef", "ffffffffffffffff", 1)
	if attachmentOwnedBy("secret", "/chat/media/docs/"+forged+".pdf", "3001") {
		t.Fatal("signature accepted for another upload")
	}
}
