package main

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// Internal endpoints for Admin → File storage (web/src/services/
// FileStorageService.php): a connection test with the settings being entered,
// and the one-time move of the files already on the local disk into the
// bucket. They answer only direct local calls from PHP that carry the
// internal token (an HMAC of the chat secret); requests through the Apache
// proxy are refused.

func internalToken(secret string) string {
	return hex.EncodeToString(hmacSHA256([]byte(secret), "chat-internal:storage"))
}

// localInternalRequest: a direct connection from this machine, not through
// the Apache reverse proxy (which connects from 127.0.0.1 too but adds
// X-Forwarded-For).
func localInternalRequest(r *http.Request) bool {
	if r.Header.Get("X-Forwarded-For") != "" || r.Header.Get("X-Forwarded-Host") != "" {
		return false
	}
	return strings.HasPrefix(r.RemoteAddr, "127.0.0.1:") || strings.HasPrefix(r.RemoteAddr, "[::1]:")
}

func (s *Server) internalStorageAllowed(w http.ResponseWriter, r *http.Request) bool {
	tok := r.Header.Get("X-AiPBX-Internal")
	if !localInternalRequest(r) || s.cfg == nil || s.cfg.SecretKey == "" ||
		!hmac.Equal([]byte(tok), []byte(internalToken(s.cfg.SecretKey))) {
		writeJSONError(w, http.StatusForbidden, "Access denied.")
		return false
	}
	return true
}

// POST /api/internal/storage/test — body: the S3 settings, secret in plain text.
func (s *Server) HandleStorageTest(w http.ResponseWriter, r *http.Request) {
	if !s.internalStorageAllowed(w, r) {
		return
	}
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "POST only.")
		return
	}
	var in struct {
		Endpoint  string `json:"endpoint"`
		Region    string `json:"region"`
		Bucket    string `json:"bucket"`
		Prefix    string `json:"prefix"`
		AccessKey string `json:"access_key"`
		SecretKey string `json:"secret_key"`
		PathStyle bool   `json:"path_style"`
	}
	if err := json.NewDecoder(io.LimitReader(r.Body, 16384)).Decode(&in); err != nil {
		writeJSONError(w, http.StatusBadRequest, "Invalid JSON.")
		return
	}
	store, err := NewS3Store(S3Config{Endpoint: in.Endpoint, Region: in.Region, Bucket: in.Bucket, Prefix: in.Prefix,
		AccessKey: in.AccessKey, SecretKey: in.SecretKey, PathStyle: in.PathStyle})
	if err == nil {
		ctx, cancel := context.WithTimeout(r.Context(), 20*time.Second)
		defer cancel()
		err = testStore(ctx, store)
	}
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{"success": true})
}

// testStore writes, reads back and deletes a small object.
func testStore(ctx context.Context, store Store) error {
	rnd := make([]byte, 8)
	_, _ = rand.Read(rnd)
	key := "aipbx-connection-test-" + hex.EncodeToString(rnd) + ".txt"
	body := []byte("AiPBX storage test " + time.Now().UTC().Format(time.RFC3339))
	if err := store.Put(ctx, key, bytes.NewReader(body), int64(len(body)), "text/plain"); err != nil {
		return fmt.Errorf("upload: %w", err)
	}
	rc, _, err := store.Get(ctx, key)
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	got, err := io.ReadAll(io.LimitReader(rc, 4096))
	rc.Close()
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	if !bytes.Equal(got, body) {
		return errors.New("download: the file came back different")
	}
	if err := store.Delete(ctx, key); err != nil {
		return fmt.Errorf("delete: %w", err)
	}
	return nil
}

// ---------------------------------------------------------------- moving files

type migrationState struct {
	Running   bool   `json:"running"`
	Total     int    `json:"total"`
	Moved     int    `json:"moved"`
	Failed    int    `json:"failed"`
	Bytes     int64  `json:"bytes"`
	LastError string `json:"last_error"`
	Started   string `json:"started"`
	Finished  string `json:"finished"`
}

// localFiles lists the keys of the files in the local media folders.
func (st *Storage) localFiles() ([]string, error) {
	var keys []string
	for dir := range mediaDirs {
		entries, err := os.ReadDir(filepath.Join(st.Local.dir, dir))
		if os.IsNotExist(err) {
			continue
		}
		if err != nil {
			return nil, err
		}
		for _, e := range entries {
			if e.Type().IsRegular() && validMediaKey(dir+"/"+e.Name()) {
				keys = append(keys, dir+"/"+e.Name())
			}
		}
	}
	sort.Strings(keys)
	return keys, nil
}

// StartMigration moves the local files into the bucket in the background.
// Each file is removed from the disk only once the bucket holds it with the
// same size; a failed file stays on the disk (and is still served from there).
func (st *Storage) StartMigration() (migrationState, error) {
	dst := st.Primary()
	if dst == Store(st.Local) {
		return st.Status(), errors.New("S3 storage is not active")
	}
	st.mu.Lock()
	if st.migration.Running {
		state := st.migration
		st.mu.Unlock()
		return state, nil
	}
	keys, err := st.localFiles()
	if err != nil {
		st.mu.Unlock()
		return st.migration, err
	}
	st.migration = migrationState{Running: true, Total: len(keys), Started: time.Now().UTC().Format(time.RFC3339)}
	state := st.migration
	st.mu.Unlock()

	go st.migrate(dst, keys)
	return state, nil
}

func (st *Storage) migrate(dst Store, keys []string) {
	ctx := context.Background()
	for _, key := range keys {
		n, err := st.moveOne(ctx, dst, key)
		st.mu.Lock()
		if err != nil {
			st.migration.Failed++
			st.migration.LastError = key + ": " + err.Error()
		} else {
			st.migration.Moved++
			st.migration.Bytes += n
		}
		st.mu.Unlock()
		if err != nil {
			log.Printf("[Storage] Could not move %s to S3: %v", key, err)
		}
	}
	st.mu.Lock()
	st.migration.Running = false
	st.migration.Finished = time.Now().UTC().Format(time.RFC3339)
	done := st.migration
	st.mu.Unlock()
	log.Printf("[Storage] Moving files to S3 finished: %d moved, %d failed", done.Moved, done.Failed)
}

func (st *Storage) moveOne(ctx context.Context, dst Store, key string) (int64, error) {
	f, size, err := st.Local.Get(ctx, key)
	if errors.Is(err, ErrNotFound) {
		return 0, nil // deleted meanwhile
	}
	if err != nil {
		return 0, err
	}
	defer f.Close()
	if have, err := dst.Size(ctx, key); err != nil || have != size {
		pctx, cancel := context.WithTimeout(ctx, 10*time.Minute)
		err := dst.Put(pctx, key, f, size, "")
		cancel()
		if err != nil {
			return 0, err
		}
		if have, err := dst.Size(ctx, key); err != nil || have != size {
			return 0, fmt.Errorf("size check after upload failed (%d bytes on disk)", size)
		}
	}
	f.Close()
	if err := st.Local.Delete(ctx, key); err != nil {
		return 0, err
	}
	return size, nil
}

func (st *Storage) Status() migrationState {
	st.mu.Lock()
	defer st.mu.Unlock()
	return st.migration
}

// POST /api/internal/storage/migrate starts the move; GET .../status reads it.
func (s *Server) HandleStorageMigrate(w http.ResponseWriter, r *http.Request) {
	if !s.internalStorageAllowed(w, r) {
		return
	}
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "POST only.")
		return
	}
	state, err := s.files().StartMigration()
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": false, "error": err.Error(), "migration": state})
		return
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{"success": true, "migration": state})
}

func (s *Server) HandleStorageStatus(w http.ResponseWriter, r *http.Request) {
	if !s.internalStorageAllowed(w, r) {
		return
	}
	files := s.files()
	local, _ := files.localFiles()
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":     true,
		"backend":     files.Primary().Name(),
		"local_files": len(local),
		"migration":   files.Status(),
	})
}
