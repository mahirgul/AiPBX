package main

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/disintegration/imaging"
)

type Server struct {
	cfg *Config
	hub *Hub
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
			writeJSONError(w, http.StatusUnauthorized, "Oturum tokeni bulunamadı.")
			return
		}

		user, err := ValidateBearerToken(token, s.cfg.SecretKey)
		if err != nil {
			writeJSONError(w, http.StatusUnauthorized, "Geçersiz oturum: "+err.Error())
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
		writeJSONError(w, http.StatusInternalServerError, "Kişiler alınamadı: "+err.Error())
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
		writeJSONError(w, http.StatusInternalServerError, "Konuşmalar alınamadı: "+err.Error())
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
	var body struct {
		TargetExtension string `json:"target_extension"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.TargetExtension == "" {
		writeJSONError(w, http.StatusBadRequest, "target_extension zorunludur.")
		return
	}

	targetExt := strings.TrimSpace(body.TargetExtension)
	if targetExt == user.Extension {
		writeJSONError(w, http.StatusBadRequest, "Kendinizle sohbet başlatamazsınız.")
		return
	}

	conv, err := GetOrCreateDirectConversation(user.Extension, targetExt, user.Extension)
	if err != nil {
		writeJSONError(w, http.StatusInternalServerError, "Sohbet oluşturulamadı: "+err.Error())
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
		writeJSONError(w, http.StatusBadRequest, "Geçersiz conversation_id.")
		return
	}

	// CH-1: Yetkilendirme kontrolü (IDOR önleme)
	isPart, err := IsParticipant(convID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "Bu sohbete erişim yetkiniz yok.")
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
		writeJSONError(w, http.StatusInternalServerError, "Mesajlar alınamadı: "+err.Error())
		return
	}

	for i := range messages {
		messages[i].IsMe = messages[i].SenderExt == user.Extension
	}

	// Otomatik okundu yap
	_ = MarkConversationAsRead(convID, user.Extension, 0)

	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success":  true,
		"messages": messages,
	})
}

// POST /chat/api/messages
func (s *Server) HandleSendMessage(w http.ResponseWriter, r *http.Request, user *User) {
	var in InMessage
	if err := json.NewDecoder(r.Body).Decode(&in); err != nil {
		writeJSONError(w, http.StatusBadRequest, "Geçersiz istek gövdesi.")
		return
	}

	convID := in.ConversationID
	if convID <= 0 && in.TargetExt != "" {
		conv, err := GetOrCreateDirectConversation(user.Extension, in.TargetExt, user.Extension)
		if err != nil {
			writeJSONError(w, http.StatusInternalServerError, "Sohbet başlatılamadı: "+err.Error())
			return
		}
		convID = conv.ID
	}

	if convID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "conversation_id veya target_ext zorunludur.")
		return
	}

	// CH-2: Yetkilendirme kontrolü (IDOR önleme)
	isPart, err := IsParticipant(convID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "Bu sohbete mesaj gönderme yetkiniz yok.")
		return
	}

	// CH-4: attachment_url ön eki denetimi (XSS önleme)
	if in.AttachmentURL != "" {
		if !strings.HasPrefix(in.AttachmentURL, "/chat/media/images/") &&
			!strings.HasPrefix(in.AttachmentURL, "/chat/media/docs/") &&
			!strings.HasPrefix(in.AttachmentURL, "/chat/media/thumbs/") &&
			!strings.HasPrefix(in.AttachmentURL, "/media/images/") &&
			!strings.HasPrefix(in.AttachmentURL, "/media/docs/") &&
			!strings.HasPrefix(in.AttachmentURL, "/media/thumbs/") {
			writeJSONError(w, http.StatusBadRequest, "Geçersiz attachment_url formatı.")
			return
		}
	}

	msgType := in.MsgType
	if msgType == "" {
		msgType = "text"
	}

	textMsg := in.Message
	if textMsg == "" && in.Content != "" {
		textMsg = in.Content
	}

	saved, err := SaveMessage(convID, user.Extension, msgType, textMsg, in.AttachmentURL, in.FileName, in.FileSize, in.MimeType)
	if err != nil {
		writeJSONError(w, http.StatusInternalServerError, "Mesaj kaydedilemedi: "+err.Error())
		return
	}

	participants, _ := GetParticipants(convID)

	// WS ile canlı ilet
	saved.IsMe = true
	senderPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  saved,
	})
	s.hub.SendToExtension(user.Extension, senderPayload)

	saved.IsMe = false
	otherPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  saved,
	})

	for _, ext := range participants {
		if ext == user.Extension {
			continue
		}
		s.hub.SendToExtension(ext, otherPayload)

		// FCM Push
		bodyPreview := saved.Message
		if saved.MsgType == "image" {
			bodyPreview = "📷 [Fotoğraf]"
		} else if saved.MsgType == "file" {
			bodyPreview = "📎 [Dosya] " + saved.FileName
		}
		if len(bodyPreview) > 100 {
			bodyPreview = bodyPreview[:97] + "..."
		}

		senderTitle := user.FullName
		if senderTitle == "" {
			senderTitle = "Dahili " + user.Extension
		}

		TriggerFcmPush(ext, senderTitle, bodyPreview, "new_message", map[string]string{
			"conversation_id": strconv.Itoa(convID),
			"sender_ext":      user.Extension,
			"sender_name":     senderTitle,
			"msg_id":          strconv.FormatInt(saved.ID, 10),
			"msg_type":        saved.MsgType,
		})
	}

	saved.IsMe = true
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": saved,
	})
}

// POST /chat/api/read
func (s *Server) HandleMarkRead(w http.ResponseWriter, r *http.Request, user *User) {
	var body struct {
		ConversationID int   `json:"conversation_id"`
		LastMessageID  int64 `json:"last_message_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.ConversationID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Geçersiz conversation_id.")
		return
	}

	// CH-1: Katılımcı doğrulaması
	isPart, err := IsParticipant(body.ConversationID, user.Extension)
	if err != nil || !isPart {
		writeJSONError(w, http.StatusForbidden, "Bu sohbete erişim yetkiniz yok.")
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
		writeJSONError(w, http.StatusBadRequest, "Dosya boyutu çok büyük (Max 25 MB).")
		return
	}

	file, handler, err := r.FormFile("file")
	if err != nil {
		writeJSONError(w, http.StatusBadRequest, "Yüklenecek dosya bulunamadı: "+err.Error())
		return
	}
	defer file.Close()

	origName := handler.Filename
	ext := strings.ToLower(filepath.Ext(origName))
	fileSize := handler.Size

	// CH-5: Uzantı beyaz liste denetimi
	if !allowedUploadExts[ext] {
		writeJSONError(w, http.StatusBadRequest, "Desteklenmeyen dosya uzantısı.")
		return
	}

	// CH-8: İçerik MIME tespiti (ilk 512 bayt)
	buf := make([]byte, 512)
	n, _ := file.Read(buf)
	if n == 0 {
		writeJSONError(w, http.StatusBadRequest, "Yüklenen dosya boş.")
		return
	}
	detectedMime := http.DetectContentType(buf[:n])
	if seeker, ok := file.(io.Seeker); ok {
		_, _ = seeker.Seek(0, io.SeekStart)
	}

	lowerMime := strings.ToLower(detectedMime)
	if strings.Contains(lowerMime, "html") || strings.Contains(lowerMime, "xml") ||
		strings.Contains(lowerMime, "javascript") || strings.Contains(lowerMime, "x-sh") ||
		strings.Contains(lowerMime, "executable") {
		writeJSONError(w, http.StatusBadRequest, "Güvenlik nedeniyle bu dosya türü yüklenemez.")
		return
	}

	isImage := ext == ".jpg" || ext == ".jpeg" || ext == ".png" || ext == ".webp" || ext == ".gif"
	if isImage && !strings.HasPrefix(lowerMime, "image/") {
		writeJSONError(w, http.StatusBadRequest, "Geçersiz görsel içeriği.")
		return
	}

	randBytes := make([]byte, 8)
	_, _ = rand.Read(randBytes)
	uniqueBase := fmt.Sprintf("%d_%s", time.Now().Unix(), hex.EncodeToString(randBytes))
	savedFileName := uniqueBase + ext

	var subDir string
	var publicURL string
	var thumbURL string

	if isImage {
		subDir = "images"
		publicURL = "/chat/media/images/" + savedFileName

		// Hedef dizini doğrula
		targetDir := filepath.Join(s.cfg.UploadDir, subDir)
		_ = os.MkdirAll(targetDir, 0755)
		dstPath := filepath.Join(targetDir, savedFileName)

		dst, err := os.Create(dstPath)
		if err != nil {
			writeJSONError(w, http.StatusInternalServerError, "Dosya diske yazılamadı: "+err.Error())
			return
		}
		if _, err := io.Copy(dst, file); err != nil {
			dst.Close()
			writeJSONError(w, http.StatusInternalServerError, "Dosya kopyalama hatası: "+err.Error())
			return
		}
		dst.Close()

		// Thumbnail oluştur (max 300x300)
		thumbDir := filepath.Join(s.cfg.UploadDir, "thumbs")
		_ = os.MkdirAll(thumbDir, 0755)
		thumbFileName := uniqueBase + "_thumb" + ext
		thumbPath := filepath.Join(thumbDir, thumbFileName)

		img, err := imaging.Open(dstPath)
		if err == nil {
			thumbImg := imaging.Fit(img, 300, 300, imaging.Lanczos)
			if err := imaging.Save(thumbImg, thumbPath); err == nil {
				thumbURL = "/chat/media/thumbs/" + thumbFileName
			}
		}
		if thumbURL == "" {
			thumbURL = publicURL
		}
	} else {
		subDir = "docs"
		publicURL = "/chat/media/docs/" + savedFileName

		targetDir := filepath.Join(s.cfg.UploadDir, subDir)
		_ = os.MkdirAll(targetDir, 0755)
		dstPath := filepath.Join(targetDir, savedFileName)

		dst, err := os.Create(dstPath)
		if err != nil {
			writeJSONError(w, http.StatusInternalServerError, "Belge diske yazılamadı: "+err.Error())
			return
		}
		if _, err := io.Copy(dst, file); err != nil {
			dst.Close()
			writeJSONError(w, http.StatusInternalServerError, "Belge kopyalama hatası: "+err.Error())
			return
		}
		dst.Close()
	}

	msgType := "file"
	if isImage {
		msgType = "image"
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

	fullPath := filepath.Join(s.cfg.UploadDir, cleanPath)
	if _, err := os.Stat(fullPath); os.IsNotExist(err) {
		http.NotFound(w, r)
		return
	}

	// CH-3 & T-10: Yetkilendirme kontrolü (Fail-closed & thumbnail desteği)
	filename := filepath.Base(cleanPath)
	lookupName := filename
	if strings.Contains(lookupName, "_thumb.") {
		lookupName = strings.Replace(lookupName, "_thumb.", ".", 1)
	}

	convID, err := GetConversationIDForAttachment(lookupName)
	if err != nil || convID <= 0 {
		http.Error(w, "Forbidden: Dosya sohbet kaydıyla eşleşmedi veya yetkisiz", http.StatusForbidden)
		return
	}

	isPart, err := IsParticipant(convID, user.Extension)
	if err != nil || !isPart {
		http.Error(w, "Forbidden: Bu medyaya erişim yetkiniz yok", http.StatusForbidden)
		return
	}

	ext := strings.ToLower(filepath.Ext(fullPath))
	isImage := ext == ".jpg" || ext == ".jpeg" || ext == ".png" || ext == ".webp" || ext == ".gif"

	if !isImage {
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", filename))
	} else {
		w.Header().Set("Content-Disposition", "inline")
	}

	http.ServeFile(w, r, fullPath)
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
		http.Error(w, "Unauthorized: "+err.Error(), http.StatusUnauthorized)
		return
	}

	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("[WS] Upgrade failed for ext %s: %v", user.Extension, err)
		return
	}

	client := &Client{
		hub:  s.hub,
		conn: conn,
		user: user,
		send: make(chan []byte, 256),
	}

	client.hub.register <- client

	go client.writePump()
	go client.readPump()
}
