package main

import (
	"errors"
	"io"
	"net/http"
	"regexp"
	"strconv"
	"strings"
)

// PBX files in the bucket: call recordings, faxes and archived voicemail
// (#69). The portal (bin/recordings_to_mp3.php, the audio endpoints) puts,
// reads and deletes them here, so there is one S3 client and one place that
// decrypts the bucket settings. The bucket is the one of Admin → File storage;
// it is used for these files even while chat files stay on the local disk.
//
//   PUT    /api/internal/files?key=recordings/2026/10/14/x.mp3   body = file
//   GET    /api/internal/files?key=…                              file
//   DELETE /api/internal/files?key=…
//
// Same guard as the other internal storage endpoints: direct local calls with
// the internal token only.

// pbxDirs are the top-level folders of PBX files.
var pbxDirs = map[string]bool{"recordings": true, "faxes": true, "voicemail": true}

// pbxPart: one path part — letters, digits and ._@+- without a leading dot.
var pbxPart = regexp.MustCompile(`^[A-Za-z0-9_@+-][A-Za-z0-9._@+-]{0,199}$`)

// validPbxKey: <recordings|faxes|voicemail>/<1 to 6 path parts>.
func validPbxKey(key string) bool {
	parts := strings.Split(key, "/")
	if len(key) > 512 || len(parts) < 2 || len(parts) > 7 || !pbxDirs[parts[0]] {
		return false
	}
	for _, p := range parts[1:] {
		if !pbxPart.MatchString(p) || strings.Contains(p, "..") {
			return false
		}
	}
	return true
}

// Bucket is the S3 store when one is configured, whatever chat files use.
func (st *Storage) Bucket() Store {
	p, fb := st.stores()
	for _, s := range []Store{p, fb} {
		if _, ok := s.(*S3Store); ok {
			return s
		}
	}
	return nil
}

func (s *Server) HandlePbxFiles(w http.ResponseWriter, r *http.Request) {
	if !s.internalStorageAllowed(w, r) {
		return
	}
	key := r.URL.Query().Get("key")
	if !validPbxKey(key) {
		writeJSONError(w, http.StatusBadRequest, "invalid key")
		return
	}
	bucket := s.files().Bucket()
	if bucket == nil {
		writeJSONError(w, http.StatusConflict, "no S3 bucket configured")
		return
	}
	switch r.Method {
	case http.MethodPut:
		if r.ContentLength < 0 {
			writeJSONError(w, http.StatusLengthRequired, "Content-Length required")
			return
		}
		ctype := r.Header.Get("Content-Type")
		if ctype == "" {
			ctype = "application/octet-stream"
		}
		if err := bucket.Put(r.Context(), key, r.Body, r.ContentLength, ctype); err != nil {
			writeJSONError(w, http.StatusBadGateway, err.Error())
			return
		}
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": true, "key": key})
	case http.MethodGet:
		rc, size, err := bucket.Get(r.Context(), key)
		if errors.Is(err, ErrNotFound) {
			writeJSONError(w, http.StatusNotFound, "not found")
			return
		}
		if err != nil {
			writeJSONError(w, http.StatusBadGateway, err.Error())
			return
		}
		defer rc.Close()
		w.Header().Set("Content-Type", "application/octet-stream")
		if size >= 0 {
			w.Header().Set("Content-Length", strconv.FormatInt(size, 10))
		}
		_, _ = io.Copy(w, rc)
	case http.MethodDelete:
		if err := bucket.Delete(r.Context(), key); err != nil && !errors.Is(err, ErrNotFound) {
			writeJSONError(w, http.StatusBadGateway, err.Error())
			return
		}
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": true})
	default:
		writeJSONError(w, http.StatusMethodNotAllowed, "PUT, GET or DELETE")
	}
}
