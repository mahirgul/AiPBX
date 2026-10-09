package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"image"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/disintegration/imaging"
)

// The example from the AWS documentation ("Example: GET Object",
// Signature Version 4, header-based authentication).
func TestS3SignatureMatchesAWSExample(t *testing.T) {
	s, err := NewS3Store(S3Config{Endpoint: "https://s3.amazonaws.com", Region: "us-east-1", Bucket: "examplebucket",
		AccessKey: "AKIAIOSFODNN7EXAMPLE", SecretKey: "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"})
	if err != nil {
		t.Fatal(err)
	}
	req, _ := http.NewRequest(http.MethodGet, s.objectURL("test.txt").String(), nil)
	req.Header.Set("Range", "bytes=0-9")
	s.sign(req, emptyPayloadHash, time.Date(2013, 5, 24, 0, 0, 0, 0, time.UTC))
	want := "AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, " +
		"SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, " +
		"Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41"
	if got := req.Header.Get("Authorization"); got != want {
		t.Fatalf("Authorization\n got %s\nwant %s", got, want)
	}
	if req.URL.Host != "examplebucket.s3.amazonaws.com" || req.URL.EscapedPath() != "/test.txt" {
		t.Fatalf("virtual-host URL: %s", req.URL)
	}
}

func TestS3ObjectURLs(t *testing.T) {
	s, _ := NewS3Store(S3Config{Endpoint: "https://minio.example.com:9000/", Bucket: "chat", Prefix: "/aipbx/",
		AccessKey: "a", SecretKey: "b", PathStyle: true})
	if u := s.objectURL("images/1_a b.jpg").String(); u != "https://minio.example.com:9000/chat/aipbx/images/1_a%20b.jpg" {
		t.Fatalf("path-style URL: %s", u)
	}
	aws, _ := NewS3Store(S3Config{Region: "eu-central-1", Bucket: "files", AccessKey: "a", SecretKey: "b"})
	if u := aws.objectURL("docs/x.pdf").String(); u != "https://files.s3.eu-central-1.amazonaws.com/docs/x.pdf" {
		t.Fatalf("AWS URL: %s", u)
	}
	for _, bad := range []S3Config{
		{Endpoint: "ftp://x", Bucket: "b", AccessKey: "a", SecretKey: "s"},
		{Endpoint: "https://x/path", Bucket: "b", AccessKey: "a", SecretKey: "s"},
		{Endpoint: "https://x", AccessKey: "a", SecretKey: "s"},
		{Endpoint: "https://x", Bucket: "b", AccessKey: "a"},
	} {
		if _, err := NewS3Store(bad); err == nil {
			t.Fatalf("%+v must be refused", bad)
		}
	}
}

// fakeS3 is a minimal path-style S3: PUT, GET, HEAD and DELETE of objects.
type fakeS3 struct {
	mu      sync.Mutex
	objects map[string][]byte
	auth    []string
}

func (f *fakeS3) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.auth = append(f.auth, r.Header.Get("Authorization"))
	if !strings.HasPrefix(r.Header.Get("Authorization"), "AWS4-HMAC-SHA256 Credential=AK/") {
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>`))
		return
	}
	switch r.Method {
	case http.MethodPut:
		if r.ContentLength < 0 {
			w.WriteHeader(http.StatusLengthRequired)
			return
		}
		b, _ := io.ReadAll(r.Body)
		f.objects[r.URL.Path] = b
	case http.MethodGet, http.MethodHead:
		b, ok := f.objects[r.URL.Path]
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.Header().Set("Content-Length", strconv.Itoa(len(b)))
		if r.Method == http.MethodGet {
			_, _ = w.Write(b)
		}
	case http.MethodDelete:
		delete(f.objects, r.URL.Path)
		w.WriteHeader(http.StatusNoContent)
	}
}

func newFakeS3(t *testing.T) (*fakeS3, *httptest.Server) {
	f := &fakeS3{objects: map[string][]byte{}}
	srv := httptest.NewServer(f)
	t.Cleanup(srv.Close)
	return f, srv
}

func TestS3StoreRoundTrip(t *testing.T) {
	fake, srv := newFakeS3(t)
	s, err := NewS3Store(S3Config{Endpoint: srv.URL, Bucket: "chat", Prefix: "pbx", AccessKey: "AK", SecretKey: "SK", PathStyle: true})
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	if err := s.Put(ctx, "docs/a.pdf", strings.NewReader("hello"), 5, "application/pdf"); err != nil {
		t.Fatal(err)
	}
	if _, ok := fake.objects["/chat/pbx/docs/a.pdf"]; !ok {
		t.Fatalf("object not under bucket/prefix: %v", fake.objects)
	}
	if n, err := s.Size(ctx, "docs/a.pdf"); err != nil || n != 5 {
		t.Fatalf("Size = %d, %v", n, err)
	}
	rc, n, err := s.Get(ctx, "docs/a.pdf")
	if err != nil || n != 5 {
		t.Fatalf("Get: %d, %v", n, err)
	}
	b, _ := io.ReadAll(rc)
	rc.Close()
	if string(b) != "hello" {
		t.Fatalf("Get body %q", b)
	}
	if err := s.Delete(ctx, "docs/a.pdf"); err != nil {
		t.Fatal(err)
	}
	if _, _, err := s.Get(ctx, "docs/a.pdf"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("deleted object: %v", err)
	}
	if err := testStore(ctx, s); err != nil {
		t.Fatalf("connection test: %v", err)
	}

	bad, _ := NewS3Store(S3Config{Endpoint: srv.URL, Bucket: "chat", AccessKey: "WRONG", SecretKey: "SK", PathStyle: true})
	err = testStore(ctx, bad)
	if err == nil || !strings.Contains(err.Error(), "AccessDenied") {
		t.Fatalf("a refused upload must say why: %v", err)
	}
}

// The values were made with web/src/secret_box.php (sodium_crypto_secretbox).
func TestOpenSecretReadsPortalSecretBox(t *testing.T) {
	var key [32]byte
	copy(key[:], "0123456789abcdef0123456789abcdef")
	if got := openSecret("sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBUS2TyQFZLZ82zPZzL+7j8yS7/elR0rfqJ4HFb8gZ0w==", &key); got != "s3-secret/Key+1" {
		t.Fatalf("openSecret = %q", got)
	}
	// AIPBX_SETTINGS_KEY goes through HKDF like hash_hkdf('sha256', $env, 32, 'aipbx-settings').
	if got := openSecret("sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBCJQMjsJDS9Z8c1HrHcrovYJdWOpuWnck", settingsKey("my-env-key")); got != "from-env" {
		t.Fatalf("openSecret with the env key = %q", got)
	}
	for _, bad := range []string{"", "plain", "sb1:!!", "sb1:AQEB"} {
		if openSecret(bad, &key) != "" {
			t.Fatalf("%q must give nothing", bad)
		}
	}
	var other [32]byte
	if openSecret("sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBUS2TyQFZLZ82zPZzL+7j8yS7/elR0rfqJ4HFb8gZ0w==", &other) != "" {
		t.Fatal("another key must not open it")
	}
}

func TestValidMediaKey(t *testing.T) {
	for _, ok := range []string{"images/1_a_b.jpg", "thumbs/1_a_b_thumb.png", "docs/x.pdf", "avatars/y.webp"} {
		if !validMediaKey(ok) {
			t.Fatalf("%q should be valid", ok)
		}
	}
	for _, bad := range []string{"", "images/", "images/../x", "other/x.jpg", "images/sub/x.jpg", "x.jpg", "images/.hidden", "../images/x.jpg"} {
		if validMediaKey(bad) {
			t.Fatalf("%q must be refused", bad)
		}
	}
}

// With S3 chosen, new files go to the bucket, old ones are still read from
// the disk, deletes reach both, and the move empties the local folder.
func TestStorageSwitchFallbackAndMigration(t *testing.T) {
	fake, srv := newFakeS3(t)
	dir := t.TempDir()
	settings := map[string]string{"storage_backend": "local"}
	st := NewStorage(dir, "")
	st.loadSettings = func() (map[string]string, error) { return settings, nil }
	ctx := context.Background()

	if st.Primary().Name() != "local" {
		t.Fatal("local disk is the default")
	}
	if err := st.Primary().Put(ctx, "docs/old.pdf", strings.NewReader("old"), 3, ""); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, "docs", "old.pdf")); err != nil {
		t.Fatalf("local file: %v", err)
	}

	// The secret is stored encrypted; with an env key the service can open it.
	st.envKey = "my-env-key"
	settings = map[string]string{
		"storage_backend": "s3", "storage_s3_endpoint": srv.URL, "storage_s3_bucket": "chat",
		"storage_s3_access_key": "AK", "storage_s3_path_style": "1",
		"storage_s3_secret_key": "sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBCJQMjsJDS9Z8c1HrHcrovYJdWOpuWnck",
	}
	st.loadedAt = time.Time{} // skip the cache
	if st.Primary().Name() != "s3" {
		t.Fatal("S3 must be used once selected")
	}
	if err := st.Primary().Put(ctx, "docs/new.pdf", strings.NewReader("new"), 3, ""); err != nil {
		t.Fatal(err)
	}
	if _, ok := fake.objects["/chat/docs/new.pdf"]; !ok {
		t.Fatal("new file must be in the bucket")
	}
	for key, want := range map[string]string{"docs/new.pdf": "new", "docs/old.pdf": "old"} {
		rc, _, err := st.Open(ctx, key)
		if err != nil {
			t.Fatalf("Open %s: %v", key, err)
		}
		b, _ := io.ReadAll(rc)
		rc.Close()
		if string(b) != want {
			t.Fatalf("Open %s = %q", key, b)
		}
	}

	// Moving: old.pdf and a thumbnail go to the bucket and leave the disk.
	_ = os.MkdirAll(filepath.Join(dir, "thumbs"), 0755)
	_ = os.WriteFile(filepath.Join(dir, "thumbs", "1_x_thumb.jpg"), []byte("thumb"), 0644)
	_ = os.WriteFile(filepath.Join(dir, "docs", ".tmp"), []byte("skip"), 0644)
	state, err := st.StartMigration()
	if err != nil || state.Total != 2 {
		t.Fatalf("StartMigration = %+v, %v", state, err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for st.Status().Running && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	if s := st.Status(); s.Running || s.Moved != 2 || s.Failed != 0 || s.Bytes != 8 {
		t.Fatalf("migration state %+v", s)
	}
	if !bytes.Equal(fake.objects["/chat/docs/old.pdf"], []byte("old")) || fake.objects["/chat/thumbs/1_x_thumb.jpg"] == nil {
		t.Fatalf("bucket after the move: %v", fake.objects)
	}
	if left, _ := st.localFiles(); len(left) != 0 {
		t.Fatalf("local files left: %v", left)
	}

	st.Remove(ctx, []string{"docs/new.pdf"})
	if _, ok := fake.objects["/chat/docs/new.pdf"]; ok {
		t.Fatal("Remove must delete from the bucket")
	}

	// A secret that cannot be opened keeps the files on the disk.
	settings = map[string]string{"storage_backend": "s3", "storage_s3_endpoint": srv.URL, "storage_s3_bucket": "chat",
		"storage_s3_access_key": "AK", "storage_s3_secret_key": "sb1:broken"}
	st.loadedAt = time.Time{}
	if st.Primary().Name() != "local" {
		t.Fatal("unusable S3 settings must fall back to the local disk")
	}
	if _, err := st.StartMigration(); err == nil {
		t.Fatal("no move without S3")
	}
}

func TestInternalStorageEndpointsNeedTokenAndDirectCall(t *testing.T) {
	srv := &Server{cfg: &Config{SecretKey: "chat-secret", UploadDir: t.TempDir()}}
	call := func(mod func(*http.Request)) int {
		req := httptest.NewRequest(http.MethodGet, "/api/internal/storage/status", nil)
		req.RemoteAddr = "127.0.0.1:5000"
		mod(req)
		rr := httptest.NewRecorder()
		srv.HandleStorageStatus(rr, req)
		return rr.Code
	}
	tok := internalToken("chat-secret")
	if c := call(func(r *http.Request) { r.Header.Set("X-AiPBX-Internal", tok) }); c != http.StatusOK {
		t.Fatalf("direct call with token: %d", c)
	}
	if c := call(func(r *http.Request) {}); c != http.StatusForbidden {
		t.Fatalf("no token: %d", c)
	}
	if c := call(func(r *http.Request) {
		r.Header.Set("X-AiPBX-Internal", tok)
		r.Header.Set("X-Forwarded-For", "203.0.113.5")
	}); c != http.StatusForbidden {
		t.Fatalf("through the proxy: %d", c)
	}
	if c := call(func(r *http.Request) {
		r.Header.Set("X-AiPBX-Internal", tok)
		r.RemoteAddr = "192.0.2.1:5000"
	}); c != http.StatusForbidden {
		t.Fatalf("remote address: %d", c)
	}
}

func uploadPNG(t *testing.T, srv *Server) map[string]interface{} {
	t.Helper()
	img := image.NewRGBA(image.Rect(0, 0, 600, 400))
	var png bytes.Buffer
	if err := imaging.Encode(&png, img, imaging.PNG); err != nil {
		t.Fatal(err)
	}
	body := &bytes.Buffer{}
	mw := multipart.NewWriter(body)
	part, _ := mw.CreateFormFile("file", "photo.png")
	_, _ = part.Write(png.Bytes())
	_ = mw.Close()
	req := httptest.NewRequest(http.MethodPost, "/api/upload", body)
	req.Header.Set("Content-Type", mw.FormDataContentType())
	rr := httptest.NewRecorder()
	srv.HandleUpload(rr, req, &User{ID: 1, Extension: "1001"})
	if rr.Code != http.StatusOK {
		t.Fatalf("upload: %d %s", rr.Code, rr.Body.String())
	}
	var out map[string]interface{}
	_ = json.Unmarshal(rr.Body.Bytes(), &out)
	return out
}

func TestUploadStoresImageAndThumbnail(t *testing.T) {
	dir := t.TempDir()
	srv := &Server{cfg: &Config{UploadDir: dir, SecretKey: "s"}}
	out := uploadPNG(t, srv)
	url, thumb := out["attachment_url"].(string), out["thumb_url"].(string)
	if !strings.HasPrefix(url, "/chat/media/images/") || !strings.HasPrefix(thumb, "/chat/media/thumbs/") {
		t.Fatalf("urls: %v", out)
	}
	for _, u := range []string{url, thumb} {
		if _, err := os.Stat(filepath.Join(dir, strings.TrimPrefix(u, "/chat/media/"))); err != nil {
			t.Fatalf("%s not on disk: %v", u, err)
		}
	}

	fake, s3srv := newFakeS3(t)
	st := NewStorage(t.TempDir(), "my-env-key")
	st.loadSettings = func() (map[string]string, error) {
		return map[string]string{"storage_backend": "s3", "storage_s3_endpoint": s3srv.URL, "storage_s3_bucket": "chat",
			"storage_s3_access_key": "AK", "storage_s3_path_style": "1",
			"storage_s3_secret_key": "sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBCJQMjsJDS9Z8c1HrHcrovYJdWOpuWnck"}, nil
	}
	srv = &Server{cfg: &Config{UploadDir: st.Local.dir, SecretKey: "s"}, storage: st}
	out = uploadPNG(t, srv)
	for _, u := range []string{out["attachment_url"].(string), out["thumb_url"].(string)} {
		if _, ok := fake.objects["/chat/"+strings.TrimPrefix(u, "/chat/media/")]; !ok {
			t.Fatalf("%s not in the bucket: %v", u, fake.objects)
		}
	}
	if left, _ := st.localFiles(); len(left) != 0 {
		t.Fatalf("nothing may be written to the disk with S3: %v", left)
	}
}

// After switching back to the local disk, files already in the bucket stay readable.
func TestStorageBackToLocalStillReadsBucket(t *testing.T) {
	fake, srv := newFakeS3(t)
	fake.objects["/chat/docs/in-bucket.pdf"] = []byte("s3")
	st := NewStorage(t.TempDir(), "my-env-key")
	st.loadSettings = func() (map[string]string, error) {
		return map[string]string{"storage_backend": "local", "storage_s3_endpoint": srv.URL, "storage_s3_bucket": "chat",
			"storage_s3_access_key": "AK", "storage_s3_path_style": "1",
			"storage_s3_secret_key": "sb1:AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBCJQMjsJDS9Z8c1HrHcrovYJdWOpuWnck"}, nil
	}
	if st.Primary().Name() != "local" {
		t.Fatal("local is selected")
	}
	rc, _, err := st.Open(context.Background(), "docs/in-bucket.pdf")
	if err != nil {
		t.Fatalf("bucket file after switching back: %v", err)
	}
	b, _ := io.ReadAll(rc)
	rc.Close()
	if string(b) != "s3" {
		t.Fatalf("got %q", b)
	}
	st.Remove(context.Background(), []string{"docs/in-bucket.pdf"})
	if _, ok := fake.objects["/chat/docs/in-bucket.pdf"]; ok {
		t.Fatal("Remove must reach the bucket too")
	}
}
