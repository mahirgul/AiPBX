package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"strconv"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

const (
	writeWait      = 10 * time.Second
	pongWait       = 60 * time.Second
	pingPeriod     = (pongWait * 9) / 10
	maxMessageSize = 512 * 1024 // 512 KB for socket control frames
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  1024,
	WriteBufferSize: 1024,
	CheckOrigin: func(r *http.Request) bool {
		return true // Auth is checked via Bearer token before upgrade
	},
}

type Client struct {
	hub  *Hub
	conn *websocket.Conn
	user *User
	send chan []byte
	// active: the user is actually using this device (app in foreground /
	// portal tab visible). An extension is online while any client is active.
	// Guarded by hub.mu.
	active bool
}

type Hub struct {
	clients    map[string]map[*Client]bool // ext -> set of active clients
	broadcast  chan []byte
	register   chan *Client
	unregister chan *Client
	mu         sync.RWMutex
	// For upload signature verification (uploads.go); set by main.go.
	secretKey string
	// When each extension last went offline (in memory, reset on restart).
	lastSeen map[string]time.Time
}

func NewHub() *Hub {
	return &Hub{
		clients:    make(map[string]map[*Client]bool),
		broadcast:  make(chan []byte),
		register:   make(chan *Client),
		unregister: make(chan *Client),
		lastSeen:   make(map[string]time.Time),
	}
}

// activeLocked reports whether ext has an active client; caller holds h.mu.
func (h *Hub) activeLocked(ext string) bool {
	for c := range h.clients[ext] {
		if c.active {
			return true
		}
	}
	return false
}

func (h *Hub) lastSeenLocked(ext string) string {
	if t, ok := h.lastSeen[ext]; ok {
		return t.Format(time.RFC3339)
	}
	return ""
}

// SetActive changes a client's active state and announces presence changes.
func (h *Hub) SetActive(c *Client, active bool) {
	h.mu.Lock()
	ext := c.user.Extension
	if _, ok := h.clients[ext][c]; !ok {
		h.mu.Unlock()
		return
	}
	before := h.activeLocked(ext)
	c.active = active
	after := h.activeLocked(ext)
	if before && !after {
		h.lastSeen[ext] = time.Now()
	}
	seen := h.lastSeenLocked(ext)
	h.mu.Unlock()
	if before != after {
		h.broadcastPresence(ext, after, seen)
	}
}

// deliverPending marks everything sent to ext so far as delivered (ext just
// connected) and updates the senders' receipts.
func (h *Hub) deliverPending(ext string) {
	convs, err := MarkAllDelivered(ext)
	if err != nil {
		log.Printf("[Hub] MarkAllDelivered(%s): %v", ext, err)
		return
	}
	for _, convID := range convs {
		h.PushReceipts(convID)
	}
}

// PushReceipts sends every participant of convID up to which message id the
// others have received and read ("receipts" event).
func (h *Hub) PushReceipts(convID int) {
	participants, err := GetParticipants(convID)
	if err != nil {
		return
	}
	for _, ext := range participants {
		readUpto, deliveredUpto, err := GetReceipts(convID, ext)
		if err != nil {
			continue
		}
		msg, _ := json.Marshal(map[string]interface{}{
			"event":           "receipts",
			"conversation_id": convID,
			"read_upto":       readUpto,
			"delivered_upto":  deliveredUpto,
		})
		h.SendToExtension(ext, msg)
	}
}

func (h *Hub) Run() {
	for {
		select {
		case client := <-h.register:
			h.mu.Lock()
			ext := client.user.Extension
			before := h.activeLocked(ext)
			if _, ok := h.clients[ext]; !ok {
				h.clients[ext] = make(map[*Client]bool)
			}
			h.clients[ext][client] = true
			after := h.activeLocked(ext)

			var onlineExts []string
			seenMap := map[string]string{}
			for e := range h.clients {
				if e != ext && h.activeLocked(e) {
					onlineExts = append(onlineExts, e)
				}
			}
			for e := range h.lastSeen {
				if e != ext && !h.activeLocked(e) {
					seenMap[e] = h.lastSeenLocked(e)
				}
			}
			h.mu.Unlock()

			log.Printf("[WS] Client connected: %s (%s) [Total devices for ext: %d]",
				client.user.FullName, ext, len(h.clients[ext]))

			if !before && after {
				h.broadcastPresence(ext, true, "")
			}

			// Sent even when the list is empty: the client uses the snapshot as
			// the full list and clears the old "online" entries it holds.
			if onlineExts == nil {
				onlineExts = []string{}
			}
			snapshotMsg, _ := json.Marshal(map[string]interface{}{
				"event":      "presence_snapshot",
				"extensions": onlineExts,
				"last_seen":  seenMap,
			})
			select {
			case client.send <- snapshotMsg:
			default:
			}
			go h.deliverPending(ext)

		case client := <-h.unregister:
			h.mu.Lock()
			ext := client.user.Extension
			if clients, ok := h.clients[ext]; ok {
				if _, ok := clients[client]; ok {
					before := h.activeLocked(ext)
					delete(clients, client)
					close(client.send)
					if len(clients) == 0 {
						delete(h.clients, ext)
						log.Printf("[WS] All devices disconnected for ext: %s", ext)
					}
					if before && !h.activeLocked(ext) {
						h.lastSeen[ext] = time.Now()
						seen := h.lastSeenLocked(ext)
						h.mu.Unlock()
						h.broadcastPresence(ext, false, seen)
						continue
					}
				}
			}
			h.mu.Unlock()
		}
	}
}

func (h *Hub) IsOnline(ext string) bool {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return h.activeLocked(ext)
}

func (h *Hub) GetOnlineExtensions() []string {
	h.mu.RLock()
	defer h.mu.RUnlock()
	var list []string
	for ext := range h.clients {
		if h.activeLocked(ext) {
			list = append(list, ext)
		}
	}
	return list
}

func (h *Hub) broadcastPresence(ext string, isOnline bool, lastSeen string) {
	payload := map[string]interface{}{
		"event":     "presence",
		"extension": ext,
		"is_online": isOnline,
	}
	if lastSeen != "" {
		payload["last_seen"] = lastSeen
	}
	msg, _ := json.Marshal(payload)
	h.BroadcastToAll(msg)
}

func (h *Hub) BroadcastToAll(msg []byte) {
	h.mu.RLock()
	defer h.mu.RUnlock()
	for _, devMap := range h.clients {
		for c := range devMap {
			select {
			case c.send <- msg:
			default:
			}
		}
	}
}

func (h *Hub) SendToExtension(ext string, msg []byte) bool {
	h.mu.RLock()
	defer h.mu.RUnlock()
	clients, ok := h.clients[ext]
	if !ok || len(clients) == 0 {
		return false
	}
	for c := range clients {
		select {
		case c.send <- msg:
		default:
		}
	}
	return true
}

func (h *Hub) BroadcastToConversation(convID int, msg []byte, excludeExt string) {
	participants, err := GetParticipants(convID)
	if err != nil {
		log.Printf("[Hub] Error fetching participants for conv %d: %v", convID, err)
		return
	}

	for _, ext := range participants {
		if excludeExt != "" && ext == excludeExt {
			continue
		}
		h.SendToExtension(ext, msg)
	}
}

type InMessage struct {
	Action         string `json:"action"`
	ConversationID int    `json:"conversation_id"`
	MsgType        string `json:"msg_type"` // text, image, file, audio
	Message        string `json:"message"`
	Content        string `json:"content"`
	AttachmentURL  string `json:"attachment_url"`
	FileName       string `json:"file_name"`
	FileSize       int    `json:"file_size"`
	MimeType       string `json:"mime_type"`
	LastMessageID  int64  `json:"last_message_id"`
	IsTyping       bool   `json:"is_typing"`
	TargetExt      string `json:"target_ext"`
	Active         *bool  `json:"active"`
}

func (c *Client) readPump() {
	defer func() {
		c.hub.unregister <- c
		c.conn.Close()
	}()

	c.conn.SetReadLimit(maxMessageSize)
	_ = c.conn.SetReadDeadline(time.Now().Add(pongWait))
	c.conn.SetPongHandler(func(string) error {
		_ = c.conn.SetReadDeadline(time.Now().Add(pongWait))
		return nil
	})

	for {
		_, message, err := c.conn.ReadMessage()
		if err != nil {
			if websocket.IsUnexpectedCloseError(err, websocket.CloseGoingAway, websocket.CloseAbnormalClosure) {
				log.Printf("[WS] Read error for %s: %v", c.user.Extension, err)
			}
			break
		}

		var in InMessage
		if err := json.Unmarshal(message, &in); err != nil {
			log.Printf("[WS] Invalid JSON from %s: %v", c.user.Extension, err)
			continue
		}

		switch in.Action {
		case "ping":
			c.sendPong()

		case "send_message":
			c.handleSendMessage(&in)

		case "typing":
			c.handleTyping(&in)

		case "mark_read":
			c.handleMarkRead(&in)

		case "set_active":
			if in.Active != nil {
				c.hub.SetActive(c, *in.Active)
			}
		}
	}
}

func (c *Client) sendPong() {
	pong, _ := json.Marshal(map[string]string{"event": "pong"})
	select {
	case c.send <- pong:
	default:
	}
}

func (c *Client) handleSendMessage(in *InMessage) {
	convID := in.ConversationID

	// If no conversation ID was provided but target_ext was, get or create it
	if convID <= 0 && in.TargetExt != "" {
		conv, err := GetOrCreateDirectConversation(c.user.Extension, in.TargetExt, c.user.Extension)
		if err != nil {
			log.Printf("[WS] Failed to get/create conv between %s and %s: %v", c.user.Extension, in.TargetExt, err)
			return
		}
		convID = conv.ID
	}

	if convID <= 0 {
		return
	}

	// CH-2: participant check (prevents IDOR)
	isPart, err := IsParticipant(convID, c.user.Extension)
	if err != nil || !isPart {
		log.Printf("[WS] Send blocked: Ext %s is not participant in conv %d", c.user.Extension, convID)
		return
	}

	// CH-4: attachment_url may only be one of our own media paths and the sender's own upload
	if in.AttachmentURL != "" {
		if !validMediaURL(in.AttachmentURL) || !attachmentOwnedBy(c.hub.secretKey, in.AttachmentURL, c.user.Extension) {
			log.Printf("[WS] Rejected attachment_url from %s: %s", c.user.Extension, in.AttachmentURL)
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

	saved, err := SaveMessage(convID, c.user.Extension, msgType, textMsg, in.AttachmentURL, in.FileName, in.FileSize, in.MimeType)
	if err != nil {
		log.Printf("[WS] SaveMessage error: %v", err)
		return
	}

	participants, _ := GetParticipants(convID)

	// Send to sender (flagged as is_me=true)
	saved.IsMe = true
	saved.Status = "sent"
	senderPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  saved,
	})
	c.hub.SendToExtension(c.user.Extension, senderPayload)

	// Send to others (flagged as is_me=false)
	saved.IsMe = false
	saved.Status = ""
	otherPayload, _ := json.Marshal(map[string]interface{}{
		"event": "new_message",
		"data":  saved,
	})

	conv, _ := GetConversationByID(convID)
	isGroup := conv != nil && conv.Type == "group"

	for _, ext := range participants {
		if ext == c.user.Extension {
			continue
		}

		if c.hub.SendToExtension(ext, otherPayload) {
			_ = MarkDelivered(convID, ext, saved.ID)
		}

		// Trigger FCM push notification for recipient
		// Even if delivered to web, mobile app might be in background
		bodyPreview := saved.Message
		if saved.MsgType == "image" {
			bodyPreview = "📷 [Fotoğraf]"
		} else if saved.MsgType == "file" {
			bodyPreview = "📎 [Dosya] " + saved.FileName
		}
		if len(bodyPreview) > 100 {
			bodyPreview = bodyPreview[:97] + "..."
		}

		senderTitle := c.user.FullName
		if senderTitle == "" {
			senderTitle = "Dahili " + c.user.Extension
		}

		pushTitle := senderTitle
		pushBody := bodyPreview
		extra := map[string]string{
			"conversation_id":   strconv.Itoa(convID),
			"sender_ext":        c.user.Extension,
			"sender_name":       senderTitle,
			"msg_id":            strconv.FormatInt(saved.ID, 10),
			"msg_type":          saved.MsgType,
			"conversation_type": "direct",
		}

		if isGroup {
			pushTitle = conv.Title
			pushBody = fmt.Sprintf("%s: %s", senderTitle, bodyPreview)
			extra["conversation_type"] = "group"
			extra["group_title"] = conv.Title
		}

		TriggerFcmPush(ext, pushTitle, pushBody, "new_message", extra)
	}
	c.hub.PushReceipts(convID)
}

func (c *Client) handleTyping(in *InMessage) {
	if in.ConversationID <= 0 {
		return
	}
	participants, _ := GetParticipants(in.ConversationID)
	out, _ := json.Marshal(map[string]interface{}{
		"event":           "typing",
		"conversation_id": in.ConversationID,
		"from_ext":        c.user.Extension,
		"from_name":       c.user.FullName,
		"is_typing":       in.IsTyping,
	})
	for _, ext := range participants {
		if ext != c.user.Extension {
			c.hub.SendToExtension(ext, out)
		}
	}
}

func (c *Client) handleMarkRead(in *InMessage) {
	if in.ConversationID <= 0 {
		return
	}
	isPart, _ := IsParticipant(in.ConversationID, c.user.Extension)
	if !isPart {
		return
	}
	_ = MarkConversationAsRead(in.ConversationID, c.user.Extension, in.LastMessageID)

	participants, _ := GetParticipants(in.ConversationID)
	out, _ := json.Marshal(map[string]interface{}{
		"event":           "messages_read",
		"conversation_id": in.ConversationID,
		"reader_ext":      c.user.Extension,
		"last_message_id": in.LastMessageID,
	})
	for _, ext := range participants {
		if ext != c.user.Extension {
			c.hub.SendToExtension(ext, out)
		}
	}
	c.hub.PushReceipts(in.ConversationID)
}

func (c *Client) writePump() {
	ticker := time.NewTicker(pingPeriod)
	defer func() {
		ticker.Stop()
		c.conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.send:
			_ = c.conn.SetWriteDeadline(time.Now().Add(writeWait))
			if !ok {
				_ = c.conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}

			w, err := c.conn.NextWriter(websocket.TextMessage)
			if err != nil {
				return
			}
			_, _ = w.Write(message)

			// Add queued messages to the current websocket frame
			n := len(c.send)
			for i := 0; i < n; i++ {
				_, _ = w.Write([]byte{'\n'})
				_, _ = w.Write(<-c.send)
			}

			if err := w.Close(); err != nil {
				return
			}

		case <-ticker.C:
			_ = c.conn.SetWriteDeadline(time.Now().Add(writeWait))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
