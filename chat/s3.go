package main

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

// S3-compatible object storage (AWS S3, MinIO, Wasabi, Backblaze B2, ...)
// for chat attachments. Only the four calls the chat service needs (PUT, GET,
// HEAD, DELETE of one object), signed with AWS Signature Version 4, so no SDK
// is pulled in.

const emptyPayloadHash = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

type S3Config struct {
	Endpoint  string // "https://minio.example.com:9000"; empty = AWS for Region
	Region    string
	Bucket    string
	Prefix    string // key prefix inside the bucket, "" or "aipbx/"
	AccessKey string
	SecretKey string
	PathStyle bool // https://host/bucket/key instead of https://bucket.host/key
}

type S3Store struct {
	cfg    S3Config
	client *http.Client
	now    func() time.Time
}

func NewS3Store(cfg S3Config) (*S3Store, error) {
	cfg.Endpoint = strings.TrimRight(strings.TrimSpace(cfg.Endpoint), "/")
	if cfg.Region == "" {
		cfg.Region = "us-east-1"
	}
	if cfg.Endpoint == "" {
		cfg.Endpoint = "https://s3." + cfg.Region + ".amazonaws.com"
	}
	u, err := url.Parse(cfg.Endpoint)
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" || (u.Path != "" && u.Path != "/") || u.RawQuery != "" {
		return nil, fmt.Errorf("invalid S3 endpoint %q", cfg.Endpoint)
	}
	if cfg.Bucket == "" || cfg.AccessKey == "" || cfg.SecretKey == "" {
		return nil, errors.New("S3 bucket, access key and secret key are required")
	}
	if p := strings.Trim(cfg.Prefix, "/"); p != "" {
		cfg.Prefix = p + "/"
	} else {
		cfg.Prefix = ""
	}
	return &S3Store{
		cfg: cfg,
		client: &http.Client{Transport: &http.Transport{
			Proxy:                 http.ProxyFromEnvironment,
			ResponseHeaderTimeout: 30 * time.Second,
			IdleConnTimeout:       90 * time.Second,
			MaxIdleConnsPerHost:   8,
		}},
		now: time.Now,
	}, nil
}

func (s *S3Store) Name() string { return "s3" }

// objectURL is the URL of one object (key without the prefix).
func (s *S3Store) objectURL(key string) *url.URL {
	u, _ := url.Parse(s.cfg.Endpoint)
	plain, escaped := "/"+s.cfg.Prefix+key, "/"+s3EscapePath(s.cfg.Prefix+key)
	if s.cfg.PathStyle {
		u.Path = "/" + s.cfg.Bucket + plain
		u.RawPath = "/" + s3EscapePath(s.cfg.Bucket) + escaped
	} else {
		u.Host = s.cfg.Bucket + "." + u.Host
		u.Path = plain
		u.RawPath = escaped
	}
	return u
}

func (s *S3Store) do(ctx context.Context, method, key string, body io.Reader, size int64, contentType string) (*http.Response, error) {
	u := s.objectURL(key)
	req, err := http.NewRequestWithContext(ctx, method, u.String(), body)
	if err != nil {
		return nil, err
	}
	payloadHash := emptyPayloadHash
	if body != nil {
		// The body is streamed, not hashed first: S3 accepts an unsigned
		// payload (the headers and the URL are still signed).
		payloadHash = "UNSIGNED-PAYLOAD"
		req.ContentLength = size
		if contentType != "" {
			req.Header.Set("Content-Type", contentType)
		}
	}
	s.sign(req, payloadHash, s.now().UTC())
	return s.client.Do(req)
}

func (s *S3Store) Put(ctx context.Context, key string, r io.Reader, size int64, contentType string) error {
	resp, err := s.do(ctx, http.MethodPut, key, r, size, contentType)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		return s3Error(resp)
	}
	return nil
}

func (s *S3Store) Get(ctx context.Context, key string) (io.ReadCloser, int64, error) {
	resp, err := s.do(ctx, http.MethodGet, key, nil, 0, "")
	if err != nil {
		return nil, 0, err
	}
	if resp.StatusCode == http.StatusNotFound {
		resp.Body.Close()
		return nil, 0, ErrNotFound
	}
	if resp.StatusCode/100 != 2 {
		defer resp.Body.Close()
		return nil, 0, s3Error(resp)
	}
	return resp.Body, resp.ContentLength, nil
}

// Size returns the stored object's size (HEAD), ErrNotFound when it is missing.
func (s *S3Store) Size(ctx context.Context, key string) (int64, error) {
	resp, err := s.do(ctx, http.MethodHead, key, nil, 0, "")
	if err != nil {
		return 0, err
	}
	resp.Body.Close()
	if resp.StatusCode == http.StatusNotFound {
		return 0, ErrNotFound
	}
	if resp.StatusCode/100 != 2 {
		return 0, fmt.Errorf("S3 HEAD %s: HTTP %d", key, resp.StatusCode)
	}
	return resp.ContentLength, nil
}

func (s *S3Store) Delete(ctx context.Context, key string) error {
	resp, err := s.do(ctx, http.MethodDelete, key, nil, 0, "")
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	// S3 answers 204 whether or not the object existed.
	if resp.StatusCode/100 != 2 && resp.StatusCode != http.StatusNotFound {
		return s3Error(resp)
	}
	return nil
}

// sign adds the AWS Signature Version 4 Authorization header.
func (s *S3Store) sign(req *http.Request, payloadHash string, t time.Time) {
	amzDate := t.Format("20060102T150405Z")
	day := t.Format("20060102")
	req.Header.Set("x-amz-date", amzDate)
	req.Header.Set("x-amz-content-sha256", payloadHash)

	headers := map[string]string{"host": req.URL.Host}
	for k, v := range req.Header {
		lk := strings.ToLower(k)
		if lk == "x-amz-date" || lk == "x-amz-content-sha256" || lk == "range" || lk == "content-type" {
			headers[lk] = strings.TrimSpace(strings.Join(v, ","))
		}
	}
	names := make([]string, 0, len(headers))
	for k := range headers {
		names = append(names, k)
	}
	sort.Strings(names)
	var canonHeaders strings.Builder
	for _, k := range names {
		canonHeaders.WriteString(k + ":" + headers[k] + "\n")
	}
	signedHeaders := strings.Join(names, ";")

	canonical := strings.Join([]string{
		req.Method,
		req.URL.EscapedPath(),
		s3CanonicalQuery(req.URL.Query()),
		canonHeaders.String(),
		signedHeaders,
		payloadHash,
	}, "\n")
	scope := day + "/" + s.cfg.Region + "/s3/aws4_request"
	sum := sha256.Sum256([]byte(canonical))
	stringToSign := "AWS4-HMAC-SHA256\n" + amzDate + "\n" + scope + "\n" + hex.EncodeToString(sum[:])

	key := hmacSHA256([]byte("AWS4"+s.cfg.SecretKey), day)
	key = hmacSHA256(key, s.cfg.Region)
	key = hmacSHA256(key, "s3")
	key = hmacSHA256(key, "aws4_request")
	signature := hex.EncodeToString(hmacSHA256(key, stringToSign))

	req.Header.Set("Authorization", "AWS4-HMAC-SHA256 Credential="+s.cfg.AccessKey+"/"+scope+
		", SignedHeaders="+signedHeaders+", Signature="+signature)
}

func hmacSHA256(key []byte, data string) []byte {
	m := hmac.New(sha256.New, key)
	m.Write([]byte(data))
	return m.Sum(nil)
}

// s3EscapePath encodes an object key the way SigV4 expects: every byte
// except the unreserved characters and '/' is percent-encoded.
func s3EscapePath(p string) string {
	var b strings.Builder
	for i := 0; i < len(p); i++ {
		c := p[i]
		if (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') ||
			c == '-' || c == '_' || c == '.' || c == '~' || c == '/' {
			b.WriteByte(c)
		} else {
			fmt.Fprintf(&b, "%%%02X", c)
		}
	}
	return b.String()
}

func s3CanonicalQuery(q url.Values) string {
	if len(q) == 0 {
		return ""
	}
	keys := make([]string, 0, len(q))
	for k := range q {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var parts []string
	for _, k := range keys {
		vals := append([]string(nil), q[k]...)
		sort.Strings(vals)
		for _, v := range vals {
			parts = append(parts, strings.ReplaceAll(url.QueryEscape(k), "+", "%20")+"="+strings.ReplaceAll(url.QueryEscape(v), "+", "%20"))
		}
	}
	return strings.Join(parts, "&")
}

// s3Error turns an S3 error answer into "HTTP 403: AccessDenied (Access Denied)".
func s3Error(resp *http.Response) error {
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	var e struct {
		Code    string `xml:"Code"`
		Message string `xml:"Message"`
	}
	if xml.Unmarshal(body, &e) == nil && e.Code != "" {
		return fmt.Errorf("S3: HTTP %d: %s (%s)", resp.StatusCode, e.Code, e.Message)
	}
	return fmt.Errorf("S3: HTTP %d", resp.StatusCode)
}
