package main

import (
	"database/sql"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// Deleting a message: only the sender can delete their own message. The row
// stays (the conversation keeps its order and the receipts their ids), it is
// marked deleted, its text and attachment fields are cleared and the attached
// file is removed from disk. Every participant gets a "message_deleted" event.

// deleteWindowSettingKey: sys_settings key with how many minutes after
// sending a message can still be deleted; 0 or missing means always.
const deleteWindowSettingKey = "chat_delete_window_minutes"

// messageForDelete is what the delete rules look at.
type messageForDelete struct {
	ConversationID int
	SenderExt      string
	MsgType        string
	AttachmentURL  string
	CreatedAt      time.Time
	IsDeleted      bool
}

// checkDeleteAllowed applies the delete rules to a stored message. It returns
// nil when ext may delete it now, otherwise the error to answer with.
func checkDeleteAllowed(m *messageForDelete, ext string, windowMinutes int, now time.Time) *sendError {
	if m.MsgType == "system" {
		return &sendError{http.StatusForbidden, "System messages cannot be deleted."}
	}
	if m.SenderExt != ext {
		return &sendError{http.StatusForbidden, "You can only delete your own messages."}
	}
	if m.IsDeleted {
		return &sendError{http.StatusConflict, "The message is already deleted."}
	}
	if windowMinutes > 0 && now.Sub(m.CreatedAt) > time.Duration(windowMinutes)*time.Minute {
		return &sendError{http.StatusForbidden, "The time to delete this message has passed."}
	}
	return nil
}

// attachmentFilePaths maps a message attachment URL to the files on disk:
// the file itself and, for an image, its thumbnail. Anything that is not one
// of our message media paths gives no path, so a stored value can never make
// the service delete another file.
func attachmentFilePaths(uploadDir, url string) []string {
	if url == "" || !validMediaURL(url) {
		return nil
	}
	rel := strings.TrimPrefix(strings.TrimPrefix(url, "/chat"), "/media/")
	dir, name := path.Split(rel)
	dir = strings.TrimSuffix(dir, "/")
	if name == "" || name == "." || strings.ContainsAny(name, `/\`) {
		return nil
	}
	switch dir {
	case "images":
		ext := path.Ext(name)
		thumb := strings.TrimSuffix(name, ext) + "_thumb" + ext
		return []string{
			filepath.Join(uploadDir, "images", name),
			filepath.Join(uploadDir, "thumbs", thumb),
		}
	case "docs":
		return []string{filepath.Join(uploadDir, "docs", name)}
	}
	// Avatars and thumbnails are never message attachments.
	return nil
}

// GetDeleteWindowMinutes reads the admin setting; 0 = no time limit.
func GetDeleteWindowMinutes() int {
	if db == nil {
		return 0
	}
	var v string
	err := db.QueryRow("SELECT setting_value FROM sys_settings WHERE setting_key = ? LIMIT 1", deleteWindowSettingKey).Scan(&v)
	if err != nil {
		return 0
	}
	n, err := strconv.Atoi(strings.TrimSpace(v))
	if err != nil || n < 0 {
		return 0
	}
	return n
}

// DeleteMessage marks msgID deleted for ext and returns the conversation id,
// the time it was deleted and the attachment URL it had (its file is still on
// disk; the caller removes it when no other message uses it).
func DeleteMessage(msgID int64, ext string, windowMinutes int) (int, time.Time, string, *sendError) {
	if msgID <= 0 || ext == "" {
		return 0, time.Time{}, "", &sendError{http.StatusBadRequest, "Invalid message_id."}
	}
	if db == nil {
		return 0, time.Time{}, "", &sendError{http.StatusServiceUnavailable, "Database unavailable."}
	}

	tx, err := db.Begin()
	if err != nil {
		return 0, time.Time{}, "", &sendError{http.StatusInternalServerError, "The message could not be deleted."}
	}
	defer tx.Rollback()

	var m messageForDelete
	var deleted int
	err = tx.QueryRow(`
		SELECT conversation_id, sender_ext, msg_type, COALESCE(attachment_url, ''), created_at, is_deleted
		FROM chat_messages WHERE id = ? FOR UPDATE`, msgID).
		Scan(&m.ConversationID, &m.SenderExt, &m.MsgType, &m.AttachmentURL, &m.CreatedAt, &deleted)
	if err == sql.ErrNoRows {
		return 0, time.Time{}, "", &sendError{http.StatusNotFound, "Message not found."}
	}
	if err != nil {
		return 0, time.Time{}, "", &sendError{http.StatusInternalServerError, "The message could not be deleted."}
	}
	m.IsDeleted = deleted == 1

	// The same participant check as reading and sending: a member who left a
	// group (or a deleted group) can no longer change its history.
	if isPart, err := IsParticipant(m.ConversationID, ext); err != nil || !isPart {
		return 0, time.Time{}, "", &sendError{http.StatusForbidden, "You are not a participant of this conversation."}
	}
	if e := checkDeleteAllowed(&m, ext, windowMinutes, time.Now()); e != nil {
		return 0, time.Time{}, "", e
	}

	if _, err := tx.Exec(`
		UPDATE chat_messages
		SET is_deleted = 1, deleted_at = NOW(), message = '', attachment_url = NULL,
		    file_name = NULL, file_size = 0, mime_type = NULL
		WHERE id = ?`, msgID); err != nil {
		return 0, time.Time{}, "", &sendError{http.StatusInternalServerError, "The message could not be deleted."}
	}

	// The conversation list shows the last message: do not keep the deleted text there.
	var lastID int64
	_ = tx.QueryRow("SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE conversation_id = ?", m.ConversationID).Scan(&lastID)
	if lastID == msgID {
		_, _ = tx.Exec("UPDATE chat_conversations SET last_message_text = '', updated_at = NOW() WHERE id = ?", m.ConversationID)
	}

	if err := tx.Commit(); err != nil {
		return 0, time.Time{}, "", &sendError{http.StatusInternalServerError, "The message could not be deleted."}
	}
	return m.ConversationID, time.Now(), m.AttachmentURL, nil
}

// attachmentStillUsed: the same upload can be attached to more than one
// message (only by its uploader); its file stays while any of them is live.
func attachmentStillUsed(url string) bool {
	if url == "" || db == nil {
		return false
	}
	var one int
	err := db.QueryRow("SELECT 1 FROM chat_messages WHERE attachment_url = ? AND is_deleted = 0 LIMIT 1", url).Scan(&one)
	return err == nil
}

// removeAttachmentFiles deletes an attachment and its thumbnail from disk.
func removeAttachmentFiles(uploadDir, url string) {
	for _, p := range attachmentFilePaths(uploadDir, url) {
		if err := os.Remove(p); err != nil && !os.IsNotExist(err) {
			log.Printf("[Chat] Could not remove attachment file %s: %v", p, err)
		}
	}
}

// DeleteMessage is the one delete path behind POST /api/messages/delete and
// the "delete_message" WebSocket action.
func (h *Hub) DeleteMessage(user *User, msgID int64) (map[string]interface{}, *sendError) {
	convID, deletedAt, attachmentURL, e := DeleteMessage(msgID, user.Extension, GetDeleteWindowMinutes())
	if e != nil {
		return nil, e
	}

	if attachmentURL != "" && !attachmentStillUsed(attachmentURL) {
		removeAttachmentFiles(h.uploadDir, attachmentURL)
	}

	data := map[string]interface{}{
		"conversation_id": convID,
		"message_id":      msgID,
		"deleted_at":      fmtTime(deletedAt),
	}
	payload, _ := json.Marshal(map[string]interface{}{
		"event": "message_deleted",
		"data":  data,
	})
	// Everyone in the conversation, the sender's other devices included.
	h.BroadcastToConversation(convID, payload, "")
	return data, nil
}

func (c *Client) handleDeleteMessage(in *InMessage) {
	if _, err := c.hub.DeleteMessage(c.user, in.MessageID); err != nil {
		log.Printf("[WS] Delete from %s rejected: %s", c.user.Extension, err.msg)
	}
}

// POST /chat/api/messages/delete {"message_id": 123}
func (s *Server) HandleDeleteMessage(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}
	var body struct {
		MessageID int64 `json:"message_id"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.MessageID <= 0 {
		writeJSONError(w, http.StatusBadRequest, "Invalid message_id.")
		return
	}
	data, e := s.hub.DeleteMessage(user, body.MessageID)
	if e != nil {
		writeJSONError(w, e.status, e.msg)
		return
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"data":    data,
	})
}
