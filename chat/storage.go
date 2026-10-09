package main

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"path"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Where chat files (attachments, thumbnails, group pictures) are kept.
//
// The local folder (CHAT_UPLOAD_DIR, /var/lib/aipbx/chat_files) is the
// default. Admin → File storage can switch to an S3-compatible bucket; the
// settings live in sys_settings (storage_*) and are re-read every few seconds,
// so a change applies without restarting the service. Downloads always go
// through /chat/media, so the access check stays the same; a file that is not
// in the bucket yet (uploaded before the switch, not moved) is still served
// from the local folder.

var ErrNotFound = errors.New("file not found")

// Store keeps files under keys like "images/<name>".
type Store interface {
	Name() string
	Put(ctx context.Context, key string, r io.Reader, size int64, contentType string) error
	Get(ctx context.Context, key string) (io.ReadCloser, int64, error)
	Size(ctx context.Context, key string) (int64, error)
	Delete(ctx context.Context, key string) error
}

// mediaDirs are the only top-level folders a key may use.
var mediaDirs = map[string]bool{"images": true, "thumbs": true, "docs": true, "avatars": true}

// validMediaKey: "<dir>/<name>" with a known dir and a plain file name.
func validMediaKey(key string) bool {
	dir, name := path.Split(key)
	dir = strings.TrimSuffix(dir, "/")
	return mediaDirs[dir] && name != "" && name != "." && name != ".." &&
		!strings.ContainsAny(name, `/\`) && !strings.HasPrefix(name, ".")
}

// ---------------------------------------------------------------- local disk

type LocalStore struct{ dir string }

func (l *LocalStore) Name() string { return "local" }

func (l *LocalStore) path(key string) string { return filepath.Join(l.dir, filepath.FromSlash(key)) }

func (l *LocalStore) Put(_ context.Context, key string, r io.Reader, _ int64, _ string) error {
	dst := l.path(key)
	if err := os.MkdirAll(filepath.Dir(dst), 0755); err != nil {
		return err
	}
	f, err := os.Create(dst)
	if err != nil {
		return err
	}
	if _, err := io.Copy(f, r); err != nil {
		f.Close()
		_ = os.Remove(dst)
		return err
	}
	return f.Close()
}

func (l *LocalStore) Get(_ context.Context, key string) (io.ReadCloser, int64, error) {
	f, err := os.Open(l.path(key))
	if os.IsNotExist(err) {
		return nil, 0, ErrNotFound
	}
	if err != nil {
		return nil, 0, err
	}
	st, err := f.Stat()
	if err != nil || !st.Mode().IsRegular() {
		f.Close()
		return nil, 0, ErrNotFound
	}
	return f, st.Size(), nil
}

func (l *LocalStore) Size(_ context.Context, key string) (int64, error) {
	st, err := os.Stat(l.path(key))
	if os.IsNotExist(err) {
		return 0, ErrNotFound
	}
	if err != nil {
		return 0, err
	}
	return st.Size(), nil
}

func (l *LocalStore) Delete(_ context.Context, key string) error {
	if err := os.Remove(l.path(key)); err != nil && !os.IsNotExist(err) {
		return err
	}
	return nil
}

// ---------------------------------------------------------------- settings

// sys_settings keys written by web/src/services/FileStorageService.php.
var storageSettingKeys = []string{
	"storage_backend", "storage_s3_endpoint", "storage_s3_region", "storage_s3_bucket",
	"storage_s3_prefix", "storage_s3_access_key", "storage_s3_secret_key", "storage_s3_path_style",
}

const storageSettingsTTL = 10 * time.Second

type Storage struct {
	Local  *LocalStore
	envKey string // AIPBX_SETTINGS_KEY
	// loadSettings reads the storage_* settings (replaced in tests).
	loadSettings func() (map[string]string, error)

	mu       sync.Mutex
	primary  Store
	fallback Store
	loadedAt time.Time
	lastSig  string

	migration migrationState
}

func NewStorage(uploadDir, envKey string) *Storage {
	return &Storage{Local: &LocalStore{dir: uploadDir}, envKey: envKey, loadSettings: loadStorageSettings}
}

func loadStorageSettings() (map[string]string, error) {
	out := map[string]string{}
	if db == nil {
		return out, nil
	}
	q := "SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN (?" + strings.Repeat(",?", len(storageSettingKeys)-1) + ")"
	args := make([]interface{}, len(storageSettingKeys))
	for i, k := range storageSettingKeys {
		args[i] = k
	}
	rows, err := db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	for rows.Next() {
		var k string
		var v sql.NullString
		if err := rows.Scan(&k, &v); err != nil {
			return nil, err
		}
		out[k] = v.String
	}
	return out, rows.Err()
}

// s3ConfigFromSettings builds the bucket settings (the secret decrypted).
func s3ConfigFromSettings(set map[string]string, envKey string) S3Config {
	return S3Config{
		Endpoint:  set["storage_s3_endpoint"],
		Region:    set["storage_s3_region"],
		Bucket:    set["storage_s3_bucket"],
		Prefix:    set["storage_s3_prefix"],
		AccessKey: set["storage_s3_access_key"],
		SecretKey: openSecret(set["storage_s3_secret_key"], settingsKey(envKey)),
		PathStyle: set["storage_s3_path_style"] == "1",
	}
}

// Primary is where new files go: the bucket when S3 is chosen and its
// settings are usable, otherwise the local folder.
func (st *Storage) Primary() Store {
	p, _ := st.stores()
	return p
}

// stores returns the primary store and the one older files may still be in:
// the local folder while S3 is in use (files from before the switch, not
// moved), or the bucket after switching back to the local disk (nil when
// there is no usable bucket).
func (st *Storage) stores() (Store, Store) {
	st.mu.Lock()
	defer st.mu.Unlock()
	if st.primary != nil && time.Since(st.loadedAt) < storageSettingsTTL {
		return st.primary, st.fallback
	}
	st.loadedAt = time.Now()
	set, err := st.loadSettings()
	if err != nil {
		// Keep what worked last; the database may be restarting.
		if st.primary == nil {
			st.primary = st.Local
		}
		return st.primary, st.fallback
	}
	sig := fmt.Sprint(set)
	if st.primary != nil && sig == st.lastSig {
		return st.primary, st.fallback
	}
	st.lastSig = sig
	st.primary, st.fallback = Store(st.Local), nil

	var bucket Store
	if set["storage_s3_bucket"] != "" {
		s3, err := NewS3Store(s3ConfigFromSettings(set, st.envKey))
		if err == nil {
			bucket = s3
		} else if set["storage_backend"] == "s3" {
			log.Printf("[Storage] S3 is selected but not usable, keeping files on the local disk: %v", err)
		}
	}
	if bucket != nil && set["storage_backend"] == "s3" {
		st.primary, st.fallback = bucket, st.Local
		log.Printf("[Storage] New chat files go to S3 bucket %q", set["storage_s3_bucket"])
	} else if bucket != nil {
		// Back on the local disk: files already in the bucket stay readable.
		st.fallback = bucket
	}
	return st.primary, st.fallback
}

// Open returns the file from the primary store, else from the fallback one.
func (st *Storage) Open(ctx context.Context, key string) (io.ReadCloser, int64, error) {
	p, fb := st.stores()
	rc, size, err := p.Get(ctx, key)
	if errors.Is(err, ErrNotFound) && fb != nil {
		return fb.Get(ctx, key)
	}
	return rc, size, err
}

// Remove deletes the keys wherever they are (bucket and local folder).
func (st *Storage) Remove(ctx context.Context, keys []string) {
	p, fb := st.stores()
	for _, k := range keys {
		for _, store := range []Store{p, fb} {
			if store == nil {
				continue
			}
			if err := store.Delete(ctx, k); err != nil {
				log.Printf("[Storage] Could not remove %s (%s): %v", k, store.Name(), err)
			}
		}
	}
}
