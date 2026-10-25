package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"image"
	"io"
	"log"
	"mime"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/disintegration/imaging"
)

type Server struct {
	cfg *Config
	hub *Hub
	// Local folder or S3 bucket for chat files (storage.go).
	storage *Storage
}

func NewServer(cfg *Config, hub *Hub) *Server {
	return &Server{
		cfg: cfg,
		hub: hub,
	}
}

func (s *Server) authMiddleware(next func(http.ResponseWriter, *http.Request, *User)) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")

		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusOK)
			return
		}

		token := ExtractToken(r)
		if token == "" {
			writeJSONError(w, http.StatusUnauthorized, "No session token.")
			return
		}

		user, err := ValidateBearerToken(token, s.cfg.SecretKey)
		if err != nil {
			writeJSONError(w, http.StatusUnauthorized, "Invalid or expired session.")
			return
		}

		next(w, r, user)
	}
}

func writeJSON(w http.ResponseWriter, status int, data interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(data)
}

func writeJSONError(w http.ResponseWriter, status int, errMsg string) {
	writeJSON(w, status, map[string]interface{}{
		"success": false,
		"error":   errMsg,
	})
}

// GET /chat/api/me
func (s *Server) HandleMe(w http.ResponseWriter, r *http.Request, user *User) {
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"user":    user,
	})
}

// GET /chat/api/contacts
func (s *Server) HandleContacts(w http.ResponseWriter, r *http.Request, user *User) {
	contacts, err := GetContacts(user.Extension)
	if err != nil {
		writeInternalError(w, "Could not load the contacts", err)
		return
	}

	for i := range contacts {
		contacts[i].IsOnline = s.hub.IsOnline(contacts[i].Extension)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":  true,
		"contacts": contacts,
	})
}

// GET /chat/api/conversations
func (s *Server) HandleConversations(w http.ResponseWriter, r *http.Request, user *User) {
	convs, err := GetConversations(user.Extension)
	if err != nil {
		writeInternalError(w, "Could not load the conversations", err)
		return
	}

	for i := range convs {
		if convs[i].TargetExt != "" {
			convs[i].TargetOnline = s.hub.IsOnline(convs[i].TargetExt)
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":       true,
		"conversations": convs,
	})
}

// POST /chat/api/conversations/direct
func (s *Server) HandleCreateDirectConversation(w http.ResponseWriter, r *http.Request, user *User) {
	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		TargetExtension string `json:"target_extension"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.TargetExtension == "" {
		writeJSONError(w, http.StatusBadRequest, "target_extension is required.")
		return
	}

	targetExt := strings.TrimSpace(body.TargetExtension)
	if targetExt == user.Extension {
		writeJSONError(w, http.StatusBadRequest, "You cannot start a conversation with yourself.")
		return
	}

	conv, err := GetOrCreateDirectConversation(user.Extension, targetExt, user.Extension)
	if errors.Is(err, errNotChatUser) {
		writeJSONError(w, http.StatusBadRequest, errNotChatUser.Error()+".")
		return
	}
	if err != nil {
		writeActionError(w, "Could not create the conversation", err)
		return
	}

	conv.TargetOnline = s.hub.IsOnline(conv.TargetExt)

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":      true,
		"conversation": conv,
	})
}

// GET /chat/api/messages
func (s *Server) HandleGetMessages(w http.ResponseWriter, r *http.Request, user *User) {
	convIDStr := r.URL.Query().Get("conversation_id")
	convID, err := strconv.Atoi(convIDStr)
	if err != nil || convID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid conversation_id.")
		return
	}

	// CH-1: authorization check (prevents IDOR)
	isPart, err := IsParticipant(convID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "You have no access to this conversation.")
		return
	}

	limit := 50
	if lStr := r.URL.Query().Get("limit"); lStr != "" {
		if l, err := strconv.Atoi(lStr); err == nil && l > 0 && l <= 100 {
			limit = l
		}
	}

	var beforeID int64
	if bStr := r.URL.Query().Get("before_id"); bStr != "" {
		beforeID, _ = strconv.ParseInt(bStr, 10, 64)
	}

	messages, err := GetMessages(convID, limit, beforeID)
	if err != nil {
		writeInternalError(w, "Could not load the messages", err)
		return
	}

	// Otomatik okundu yap
	_ = MarkConversationAsRead(convID, user.Extension, 0)
	s.hub.PushReceipts(convID)

	readUpto, deliveredUpto, _ := GetReceipts(convID, user.Extension)
	for i := range messages {
		messages[i].IsMe = messages[i].SenderExt == user.Extension
		if messages[i].IsMe && messages[i].SystemEvent == "" {
			messages[i].Status = MessageStatus(messages[i].ID, readUpto, deliveredUpto)
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":  true,
		"messages": messages,
	})
}

// POST /chat/api/messages
func (s *Server) HandleSendMessage(w http.ResponseWriter, r *http.Request, user *User) {
	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var in InMessage
	if err := json.NewDecoder(r.Body).Decode(&in); err != nil {
		writeJSONError(w, http.StatusBadRequest, "Invalid request body.")
		return
	}

	saved, sendErr := s.hub.SendMessage(user, &in)
	if sendErr != nil {
		writeJSONError(w, sendErr.status, sendErr.msg)
		return
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": saved,
	})
}

// POST /chat/api/read
func (s *Server) HandleMarkRead(w http.ResponseWriter, r *http.Request, user *User) {
	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int   `json:"conversation_id"`
		LastMessageID  int64 `json:"last_message_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid conversation_id.")
		return
	}

	// CH-1: participant check
	isPart, err := IsParticipant(body.ConversationID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "You have no access to this conversation.")
		return
	}

	_ = MarkConversationAsRead(body.ConversationID, user.Extension, body.LastMessageID)

	participants, _ := GetParticipants(body.ConversationID)
	out, _ := json.Marshal(map[string]interface{}{
		"event":           "messages_read",
		"conversation_id": body.ConversationID,
		"reader_ext":      user.Extension,
		"last_message_id": body.LastMessageID,
	})
	for _, ext := range participants {
		if ext != user.Extension {
			s.hub.SendToExtension(ext, out)
		}
	}
	s.hub.PushReceipts(body.ConversationID)

	writeJSON(w, http.StatusOK, map[string]interface{}{"success": true})
}

var allowedUploadExts = map[string]bool{
	".jpg":  true,
	".jpeg": true,
	".png":  true,
	".gif":  true,
	".webp": true,
	".pdf":  true,
	".doc":  true,
	".docx": true,
	".xls":  true,
	".xlsx": true,
	".txt":  true,
	".zip":  true,
	".ogg":  true,
	".mp3":  true,
	".m4a":  true,
	".wav":  true,
}

// POST /chat/api/upload
func (s *Server) HandleUpload(w http.ResponseWriter, r *http.Request, user *User) {
	// Max 25 MB
	const maxUploadSize = 25 * 1024 * 1024
	r.Body = http.MaxBytesReader(w, r.Body, maxUploadSize)

	if err := r.ParseMultipartForm(maxUploadSize); err != nil {
		writeJSONError(w, http.StatusBadRequest, "The file is too large (max 25 MB).")
		return
	}

	file, handler, err := r.FormFile("file")
	if err != nil {
		writeJSONError(w, http.StatusBadRequest, "No file to upload.")
		return
	}
	defer file.Close()

	origName := handler.Filename
	ext := strings.ToLower(filepath.Ext(origName))
	fileSize := handler.Size

	// CH-5: extension whitelist check
	if !allowedUploadExts[ext] {
		writeJSONError(w, http.StatusBadRequest, "Unsupported file extension.")
		return
	}

	// CH-8: content MIME detection (first 512 bytes)
	sniffBuf := make([]byte, 512)
	n, _ := file.Read(sniffBuf)
	if n == 0 {
		writeJSONError(w, http.StatusBadRequest, "The uploaded file is empty.")
		return
	}
	detectedMime := http.DetectContentType(sniffBuf[:n])
	var reader io.Reader = io.MultiReader(bytes.NewReader(sniffBuf[:n]), file)
	if seeker, ok := file.(io.Seeker); ok {
		if _, err := seeker.Seek(0, io.SeekStart); err == nil {
			reader = file
		}
	}

	lowerMime := strings.ToLower(detectedMime)
	if strings.Contains(lowerMime, "html") || strings.Contains(lowerMime, "xml") ||
		strings.Contains(lowerMime, "javascript") || strings.Contains(lowerMime, "x-sh") ||
		strings.Contains(lowerMime, "executable") {
		writeJSONError(w, http.StatusBadRequest, "This file type cannot be uploaded for security reasons.")
		return
	}

	isImage := ext == ".jpg" || ext == ".jpeg" || ext == ".png" || ext == ".webp" || ext == ".gif"
	isAudio := ext == ".ogg" || ext == ".mp3" || ext == ".m4a" || ext == ".wav"
	if isImage && !strings.HasPrefix(lowerMime, "image/") {
		writeJSONError(w, http.StatusBadRequest, "Invalid image content.")
		return
	}

	randBytes := make([]byte, 8)
	_, _ = rand.Read(randBytes)
	secretKey := ""
	if s.cfg != nil {
		secretKey = s.cfg.SecretKey
	}
	uniqueBase := signedUploadBase(secretKey, user.Extension, fmt.Sprintf("%d_%s", time.Now().Unix(), hex.EncodeToString(randBytes)))
	savedFileName := uniqueBase + ext

	var subDir string
	var publicURL string
	var thumbURL string

	uploadType := r.URL.Query().Get("type")
	if uploadType == "" {
		uploadType = r.FormValue("type")
	}

	files := s.files()
	ctx := r.Context()
	if isImage {
		if uploadType == "avatar" {
			subDir = "avatars"
			publicURL = "/chat/media/avatars/" + savedFileName
		} else {
			subDir = "images"
			publicURL = "/chat/media/images/" + savedFileName
		}
	} else {
		subDir = "docs"
		publicURL = "/chat/media/docs/" + savedFileName
	}

	store := files.Primary()
	if err := store.Put(ctx, subDir+"/"+savedFileName, reader, fileSize, detectedMime); err != nil {
		log.Printf("[Upload] Could not store %s/%s (%s): %v", subDir, savedFileName, store.Name(), err)
		writeInternalError(w, "Could not save the file", err)
		return
	}

	if isImage {
		// Create a thumbnail (max 300x300); without one the image itself is shown.
		thumbFileName := uniqueBase + "_thumb" + ext
		if thumb, ok := makeThumbnail(file, ext); ok {
			if err := store.Put(ctx, "thumbs/"+thumbFileName, bytes.NewReader(thumb), int64(len(thumb)), detectedMime); err == nil {
				thumbURL = "/chat/media/thumbs/" + thumbFileName
			} else {
				log.Printf("[Upload] Could not store thumbnail %s: %v", thumbFileName, err)
			}
		}
		if thumbURL == "" {
			thumbURL = publicURL
		}
	}

	msgType := "file"
	if isImage {
		msgType = "image"
	} else if isAudio || uploadType == "audio" {
		msgType = "audio"
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":        true,
		"msg_type":       msgType,
		"attachment_url": publicURL,
		"thumb_url":      thumbURL,
		"file_name":      origName,
		"file_size":      fileSize,
		"mime_type":      detectedMime,
	})
}

// GET /chat/media/* (CH-3, CH-5)
func (s *Server) HandleMedia(w http.ResponseWriter, r *http.Request, user *User) {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Cache-Control", "private, max-age=86400")
	w.Header().Set("X-Content-Type-Options", "nosniff")

	subPath := strings.TrimPrefix(r.URL.Path, "/chat/media/")
	subPath = strings.TrimPrefix(subPath, "/media/")
	cleanPath := filepath.Clean(subPath)

	if strings.Contains(cleanPath, "..") {
		http.Error(w, "Forbidden", http.StatusForbidden)
		return
	}

	key := filepath.ToSlash(cleanPath)
	if !validMediaKey(key) {
		http.NotFound(w, r)
		return
	}

	// CH-3 & T-10: authorization check (fail-closed & thumbnail support)
	filename := path.Base(key)
	lookupName := filename
	if strings.Contains(lookupName, "_thumb.") {
		lookupName = strings.Replace(lookupName, "_thumb.", ".", 1)
	}

	// The uploader may view their own signed file (e.g. group avatar preview
	// before the group is saved), or the caller must be an active participant
	// of one of the chats that contain the file (a message attachment or a
	// group picture).
	secretKey := ""
	if s.cfg != nil {
		secretKey = s.cfg.SecretKey
	}
	if !attachmentOwnedBy(secretKey, key, user.Extension) {
		ok, err := CanAccessAttachment(lookupName, user.Extension)
		if err != nil || !ok {
			http.Error(w, "Forbidden: you have no access to this media", http.StatusForbidden)
			return
		}
	}

	ext := strings.ToLower(path.Ext(key))
	isImage := ext == ".jpg" || ext == ".jpeg" || ext == ".png" || ext == ".webp" || ext == ".gif"

	if !isImage {
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", filename))
	} else {
		w.Header().Set("Content-Disposition", "inline")
	}

	files := s.files()
	// On the local disk: ServeFile answers Range and If-Modified-Since requests.
	fullPath := files.Local.path(key)
	if st, err := os.Stat(fullPath); err == nil && st.Mode().IsRegular() {
		http.ServeFile(w, r, fullPath)
		return
	}

	rc, size, err := files.Open(r.Context(), key)
	if errors.Is(err, ErrNotFound) {
		http.NotFound(w, r)
		return
	}
	if err != nil {
		log.Printf("[Media] Could not read %s: %v", key, err)
		http.Error(w, "Storage unavailable", http.StatusBadGateway)
		return
	}
	defer rc.Close()
	ctype := mime.TypeByExtension(ext)
	if ctype == "" {
		ctype = "application/octet-stream"
	}
	w.Header().Set("Content-Type", ctype)
	if size > 0 {
		w.Header().Set("Content-Length", strconv.FormatInt(size, 10))
	}
	if r.Method == http.MethodHead {
		return
	}
	_, _ = io.Copy(w, rc)
}

// maxThumbPixels: larger images are stored without a thumbnail (40 MP covers
// any phone camera).
const maxThumbPixels = 40_000_000

// makeThumbnail returns the image scaled to fit 300x300, encoded like the
// original; false when the format cannot be decoded or encoded (webp).
func makeThumbnail(file io.ReadSeeker, ext string) ([]byte, bool) {
	format, err := imaging.FormatFromExtension(ext)
	if err != nil {
		return nil, false
	}
	if _, err := file.Seek(0, io.SeekStart); err != nil {
		return nil, false
	}
	// The decoder allocates width x height from the header before reading the
	// pixels: a small file claiming 60000x60000 asked for ~14 GB and the
	// out-of-memory crash took the whole chat service down.
	cfg, _, err := image.DecodeConfig(file)
	if err != nil || cfg.Width <= 0 || cfg.Height <= 0 || int64(cfg.Width)*int64(cfg.Height) > maxThumbPixels {
		return nil, false
	}
	if _, err := file.Seek(0, io.SeekStart); err != nil {
		return nil, false
	}
	img, err := imaging.Decode(file)
	if err != nil {
		return nil, false
	}
	var buf bytes.Buffer
	if err := imaging.Encode(&buf, imaging.Fit(img, 300, 300, imaging.Lanczos), format); err != nil {
		return nil, false
	}
	return buf.Bytes(), true
}

// writeInternalError logs the cause and gives the client only the message:
// database and storage errors named tables, paths and hosts.
func writeInternalError(w http.ResponseWriter, msg string, err error) {
	log.Printf("[HTTP] %s: %v", msg, err)
	writeJSONError(w, http.StatusInternalServerError, msg+".")
}

// writeActionError sends a 400 response when err is a known validation error
// (userError), and otherwise logs the internal/DB error and sends a generic 500.
func writeActionError(w http.ResponseWriter, fallbackMsg string, err error) {
	var ue userError
	if errors.As(err, &ue) {
		writeJSONError(w, http.StatusBadRequest, ue.Error())
		return
	}
	if errors.Is(err, errNotChatUser) {
		writeJSONError(w, http.StatusBadRequest, errNotChatUser.Error()+".")
		return
	}
	writeInternalError(w, fallbackMsg, err)
}

// files returns where chat files are kept (the local folder when main.go
// set no storage, as in tests).
func (s *Server) files() *Storage {
	if s.storage != nil {
		return s.storage
	}
	dir := ""
	if s.cfg != nil {
		dir = s.cfg.UploadDir
	}
	return NewStorage(dir, "")
}

// GET /chat/ws
func (s *Server) HandleWS(w http.ResponseWriter, r *http.Request) {
	token := ExtractToken(r)
	if token == "" {
		log.Printf("[WS Auth Failed] Missing token for %s (from %s)", r.URL.Path, r.RemoteAddr)
		http.Error(w, "Unauthorized: missing token", http.StatusUnauthorized)
		return
	}

	user, err := ValidateBearerToken(token, s.cfg.SecretKey)
	if err != nil {
		log.Printf("[WS Auth Failed] Invalid token for %s: %v (remote: %s)", r.URL.Path, err, r.RemoteAddr)
		http.Error(w, "Unauthorized: invalid or expired session", http.StatusUnauthorized)
		return
	}

	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("[WS] Upgrade failed for ext %s: %v", user.Extension, err)
		return
	}

	// ?active=0: a background connection (e.g. the Android foreground service)
	// that must not make the user look online; it sends set_active later.
	client := &Client{
		hub:    s.hub,
		conn:   conn,
		user:   user,
		send:   make(chan []byte, 256),
		active: r.URL.Query().Get("active") != "0",
	}

	client.hub.register <- client

	go client.writePump()
	go client.readPump()
}

// POST /chat/api/conversations/group (CH-G1, CH-G2, CH-G3, CH-G4)
func (s *Server) HandleCreateGroup(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		Title       string   `json:"title"`
		AvatarURL   string   `json:"avatar_url"`
		Description string   `json:"description"`
		Members     []string `json:"members"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeJSONError(w, http.StatusBadRequest, "Invalid request body.")
		return
	}

	body.Title = strings.TrimSpace(body.Title)
	if body.Title == "" {
		writeJSONError(w, http.StatusBadRequest, "The group name is required.")
		return
	}

	if body.AvatarURL != "" && (!validMediaURL(body.AvatarURL) || !attachmentOwnedBy(s.cfg.SecretKey, body.AvatarURL, user.Extension)) {
		writeJSONError(w, http.StatusBadRequest, "Invalid group image.")
		return
	}

	conv, sysMsg, err := CreateGroupConversation(body.Title, user.Extension, body.AvatarURL, body.Description, body.Members)
	if err != nil {
		writeActionError(w, "Could not create the group", err)
		return
	}

	// Notify the participants
	participants, _ := GetParticipants(conv.ID)
	createdPayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_created",
		"data":  conv,
	})
	sysMsgPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  sysMsg,
	})

	for _, ext := range participants {
		s.hub.SendToExtension(ext, createdPayload)
		s.hub.SendToExtension(ext, sysMsgPayload)

		// FCM notification to the added members ("you were added to the group")
		if ext != user.Extension {
			pushTitle := conv.Title
			pushBody := fmt.Sprintf("%s sizi \"%s\" grubuna ekledi", user.FullName, conv.Title)
			TriggerFcmPush(ext, pushTitle, pushBody, "group_created", map[string]string{
				"conversation_id":   strconv.Itoa(conv.ID),
				"conversation_type": "group",
				"group_title":       conv.Title,
			})
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":      true,
		"conversation": conv,
	})
}

// GET /chat/api/conversations/group?conversation_id=X (CH-G1)
func (s *Server) HandleGetGroupDetails(w http.ResponseWriter, r *http.Request, user *User) {
	convIDStr := r.URL.Query().Get("conversation_id")
	convID, err := strconv.Atoi(convIDStr)
	if err != nil || convID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid conversation_id.")
		return
	}

	// CH-G1: participant check
	isPart, err := IsParticipant(convID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "You have no access to this conversation.")
		return
	}

	conv, err := GetGroupDetails(convID, user.Extension)
	if err != nil {
		writeInternalError(w, "Could not load the group details", err)
		return
	}

	// Add the presence information
	onlineCount := 0
	for i := range conv.Participants {
		conv.Participants[i].IsOnline = s.hub.IsOnline(conv.Participants[i].Extension)
		if conv.Participants[i].IsOnline {
			onlineCount++
		}
	}
	conv.OnlineCount = onlineCount

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":      true,
		"conversation": conv,
	})
}

// POST /chat/api/conversations/group/update (CH-G1, CH-G4)
func (s *Server) HandleUpdateGroup(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int    `json:"conversation_id"`
		Title          string `json:"title"`
		AvatarURL      string `json:"avatar_url"`
		Description    string `json:"description"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}

	// CH-G1: admin check
	isAdmin, err := IsGroupAdmin(body.ConversationID, user.Extension)
	if err != nil || !isAdmin {
		writeJSONError(w, http.StatusForbidden, "You must be a group admin to update the group.")
		return
	}

	// When the group picture changes, the new file must be the sender's own upload
	// (sending the current picture back unchanged is allowed).
	if body.AvatarURL != "" {
		cur, _ := GetConversationByID(body.ConversationID)
		if cur == nil || cur.AvatarURL != body.AvatarURL {
			if !validMediaURL(body.AvatarURL) || !attachmentOwnedBy(s.cfg.SecretKey, body.AvatarURL, user.Extension) {
				writeJSONError(w, http.StatusBadRequest, "Invalid group image.")
				return
			}
		}
	}

	sysMsg, err := UpdateGroupInfo(body.ConversationID, user.Extension, body.Title, body.AvatarURL, body.Description)
	if err != nil {
		writeActionError(w, "Could not update the group", err)
		return
	}

	conv, _ := GetConversationByID(body.ConversationID)
	participants, _ := GetParticipants(body.ConversationID)

	updatePayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_updated",
		"data": map[string]interface{}{
			"conversation_id": body.ConversationID,
			"title":           body.Title,
			"avatar_url":      body.AvatarURL,
			"description":     body.Description,
		},
	})
	sysMsgPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  sysMsg,
	})

	for _, ext := range participants {
		s.hub.SendToExtension(ext, updatePayload)
		s.hub.SendToExtension(ext, sysMsgPayload)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":      true,
		"conversation": conv,
	})
}

// POST /chat/api/conversations/group/members/add (CH-G1, CH-G2, CH-G3)
func (s *Server) HandleAddGroupMembers(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int      `json:"conversation_id"`
		Extensions     []string `json:"extensions"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 || len(body.Extensions) == 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}

	// CH-G1: admin check
	isAdmin, err := IsGroupAdmin(body.ConversationID, user.Extension)
	if err != nil || !isAdmin {
		writeJSONError(w, http.StatusForbidden, "You must be a group admin to add members.")
		return
	}

	added, sysMsg, err := AddGroupMembers(body.ConversationID, user.Extension, body.Extensions)
	if err != nil {
		writeActionError(w, "Could not add the members", err)
		return
	}

	conv, _ := GetConversationByID(body.ConversationID)
	participants, _ := GetParticipants(body.ConversationID)

	addedPayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_member_added",
		"data": map[string]interface{}{
			"conversation_id": body.ConversationID,
			"members":         added,
			"actor":           user.Extension,
		},
	})
	sysMsgPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  sysMsg,
	})

	for _, ext := range participants {
		s.hub.SendToExtension(ext, addedPayload)
		s.hub.SendToExtension(ext, sysMsgPayload)
	}

	// "You were added to the group" notification to the added users
	title := ""
	if conv != nil {
		title = conv.Title
	}
	for _, ext := range added {
		pushBody := fmt.Sprintf("%s sizi \"%s\" grubuna ekledi", user.FullName, title)
		TriggerFcmPush(ext, title, pushBody, "group_member_added", map[string]string{
			"conversation_id":   strconv.Itoa(body.ConversationID),
			"conversation_type": "group",
			"group_title":       title,
		})
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"added":   added,
	})
}

// POST /chat/api/conversations/group/members/remove (CH-G1, CH-G6)
func (s *Server) HandleRemoveGroupMember(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int    `json:"conversation_id"`
		Extension      string `json:"extension"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 || body.Extension == "" {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}

	// CH-G1: admin check
	isAdmin, err := IsGroupAdmin(body.ConversationID, user.Extension)
	if err != nil || !isAdmin {
		writeJSONError(w, http.StatusForbidden, "You must be a group admin to remove members.")
		return
	}

	sysMsg, err := RemoveGroupMember(body.ConversationID, user.Extension, body.Extension)
	if err != nil {
		writeActionError(w, "Could not remove the member", err)
		return
	}

	// Notify the removed member too (so the chat drops off their list)
	removedPayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_member_removed",
		"data": map[string]interface{}{
			"conversation_id": body.ConversationID,
			"extension":       body.Extension,
			"actor":           user.Extension,
		},
	})
	s.hub.SendToExtension(body.Extension, removedPayload)

	// Notify the remaining participants
	participants, _ := GetParticipants(body.ConversationID)
	sysMsgPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  sysMsg,
	})

	for _, ext := range participants {
		s.hub.SendToExtension(ext, removedPayload)
		s.hub.SendToExtension(ext, sysMsgPayload)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
	})
}

// POST /chat/api/conversations/group/members/role (CH-G1)
func (s *Server) HandleUpdateGroupMemberRole(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int    `json:"conversation_id"`
		Extension      string `json:"extension"`
		Role           string `json:"role"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 || body.Extension == "" || (body.Role != "admin" && body.Role != "member") {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}

	// CH-G1: admin check
	isAdmin, err := IsGroupAdmin(body.ConversationID, user.Extension)
	if err != nil || !isAdmin {
		writeJSONError(w, http.StatusForbidden, "You must be a group admin to change roles.")
		return
	}

	err = UpdateGroupMemberRole(body.ConversationID, user.Extension, body.Extension, body.Role)
	if err != nil {
		writeActionError(w, "Could not update the member role", err)
		return
	}

	participants, _ := GetParticipants(body.ConversationID)
	rolePayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_role_updated",
		"data": map[string]interface{}{
			"conversation_id": body.ConversationID,
			"extension":       body.Extension,
			"role":            body.Role,
			"actor":           user.Extension,
		},
	})
	for _, ext := range participants {
		s.hub.SendToExtension(ext, rolePayload)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
	})
}

// POST /chat/api/conversations/group/leave (CH-G1)
func (s *Server) HandleLeaveGroup(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int `json:"conversation_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}

	// CH-G1: participant check
	isPart, err := IsParticipant(body.ConversationID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "You are not an active member of this group.")
		return
	}

	sysMsg, err := LeaveGroup(body.ConversationID, user.Extension)
	if err != nil {
		writeActionError(w, "Could not leave the group", err)
		return
	}

	// Notify the user who left
	leftPayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_member_removed",
		"data": map[string]interface{}{
			"conversation_id": body.ConversationID,
			"extension":       user.Extension,
			"actor":           user.Extension,
		},
	})
	s.hub.SendToExtension(user.Extension, leftPayload)

	// Notify the remaining participants
	participants, _ := GetParticipants(body.ConversationID)
	var sysMsgPayload []byte
	if sysMsg != nil {
		sysMsgPayload, _ = json.Marshal(map[string]interface{}{
			"event": "new_message",
			"data":  sysMsg,
		})
	}

	for _, ext := range participants {
		s.hub.SendToExtension(ext, leftPayload)
		if sysMsgPayload != nil {
			s.hub.SendToExtension(ext, sysMsgPayload)
		}
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
	})
}

// POST /chat/api/conversations/group/delete (CH-G1, CH-G7)
func (s *Server) HandleDeleteGroup(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}

	r.Body = http.MaxBytesReader(w, r.Body, 64*1024)
	var body struct {
		ConversationID int `json:"conversation_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}

	// CH-G1: admin check
	isAdmin, err := IsGroupAdmin(body.ConversationID, user.Extension)
	if err != nil || !isAdmin {
		writeJSONError(w, http.StatusForbidden, "You must be a group admin to delete the group.")
		return
	}

	// Get the participants before deleting them
	participants, _ := GetParticipants(body.ConversationID)

	err = DeleteGroup(body.ConversationID, user.Extension)
	if err != nil {
		writeInternalError(w, "Could not delete the group", err)
		return
	}

	deletedPayload, _ := json.Marshal(map[string]interface{}{
		"event": "group_deleted",
		"data": map[string]interface{}{
			"conversation_id": body.ConversationID,
		},
	})

	for _, ext := range participants {
		s.hub.SendToExtension(ext, deletedPayload)
	}

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
	})
}

// internalRequestAllowed verifies a direct local loopback request and, when a
// server secret key is configured, the X-AiPBX-Internal HMAC token.
func (s *Server) internalRequestAllowed(r *http.Request) bool {
	if !localInternalRequest(r) {
		return false
	}
	if s.cfg != nil && s.cfg.SecretKey != "" {
		tok := r.Header.Get("X-AiPBX-Internal")
		if !hmac.Equal([]byte(tok), []byte(internalToken(s.cfg.SecretKey))) {
			return false
		}
	}
	return true
}

// internalNotifyEvents: what the PBX may push to the apps over this endpoint.
var internalNotifyEvents = map[string]bool{"voicemail": true}

// POST /api/internal/notify (Internal only): {"extension": "1001", "event": "voicemail", "data": {...}}
// sends {"event": ..., "data": ...} to every connected device of the extension
// (bin/voicemail_notify.php: a new voicemail while the app is connected).
func (s *Server) HandleInternalNotify(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost || !s.internalRequestAllowed(r) {
		writeJSONError(w, http.StatusForbidden, "Access denied.")
		return
	}
	var req struct {
		Extension string                 `json:"extension"`
		Event     string                 `json:"event"`
		Data      map[string]interface{} `json:"data"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 16*1024)).Decode(&req); err != nil ||
		req.Extension == "" || !internalNotifyEvents[req.Event] {
		writeJSONError(w, http.StatusBadRequest, "invalid request")
		return
	}
	msg, _ := json.Marshal(map[string]interface{}{"event": req.Event, "data": req.Data})
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":   true,
		"delivered": s.hub.SendToExtension(req.Extension, msg),
	})
}

// GET /api/internal/presence (Internal only)
func (s *Server) HandleInternalPresence(w http.ResponseWriter, r *http.Request) {
	// The Apache reverse proxy connects from 127.0.0.1 too: looking only at
	// the address opened this endpoint to anyone without a session through
	// /chat/api/internal/presence (the list of online extensions). A request
	// via the proxy carries X-Forwarded-For; PHP (api/mobile/contacts.php)
	// connects directly with X-AiPBX-Internal.
	if !s.internalRequestAllowed(r) {
		writeJSONError(w, http.StatusForbidden, "Access denied.")
		return
	}
	exts := s.hub.GetOnlineExtensions()
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"online":  exts,
	})
}
