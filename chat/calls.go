package main

import (
	"crypto/rand"
	"database/sql"
	"encoding/json"
	"fmt"
	"math/big"
	"net/http"
	"strconv"
	"time"
)

// A conference call started from a group chat. The chat service opens the call
// and hands its dial address to the members; Asterisk lets a caller in only
// after the PBX has checked that the caller is a member (api/chat_call_check.php),
// and tells us when the last one left.

// chatCallStaleSeconds: an open call nobody closed (Asterisk restarted) is not
// joined after this; the PBX uses the same limit (ChatCallService::STALE_SECONDS).
const chatCallStaleSeconds = 21600

// chatCallDialPrefix: the apps call "cc<room>" (ChatCallService::DIAL_PREFIX).
const chatCallDialPrefix = "cc"

type ChatCall struct {
	ID             int64  `json:"id"`
	ConversationID int    `json:"conversation_id"`
	Video          bool   `json:"video"`
	StartedBy      string `json:"started_by"`
	Dial           string `json:"dial,omitempty"`
	room           string
	startedAt      time.Time
	peak           int
}

// newChatRoom: 12 random digits. The room is the secret part of the dial address.
func newChatRoom() (string, error) {
	max := new(big.Int).Exp(big.NewInt(10), big.NewInt(12), nil)
	n, err := rand.Int(rand.Reader, max)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf("%012d", n), nil
}

// OpenChatCall returns the open call of the conversation, or starts one.
// created says whether this request started it.
func OpenChatCall(convID int, ext string, video bool) (call *ChatCall, created bool, err error) {
	tx, err := db.Begin()
	if err != nil {
		return nil, false, err
	}
	defer tx.Rollback()

	// The conversation row is the lock: two members pressing at once get one call.
	var convType string
	if err = tx.QueryRow(`SELECT type FROM chat_conversations WHERE id = ? AND is_deleted = 0 FOR UPDATE`, convID).Scan(&convType); err != nil {
		return nil, false, userError("Conversation not found.")
	}
	if convType != "group" {
		return nil, false, userError("Conference calls are for group chats.")
	}
	if ok, perr := isParticipantQ(tx, convID, ext); perr != nil || !ok {
		return nil, false, userError("You are not an active member of this group.")
	}

	if _, err = tx.Exec(`UPDATE chat_calls SET ended_at = NOW() WHERE conversation_id = ? AND ended_at IS NULL
		AND started_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)`, convID, chatCallStaleSeconds); err != nil {
		return nil, false, err
	}

	c := &ChatCall{ConversationID: convID}
	var v int
	err = tx.QueryRow(`SELECT id, room, video, started_by FROM chat_calls WHERE conversation_id = ? AND ended_at IS NULL
		ORDER BY id DESC LIMIT 1`, convID).Scan(&c.ID, &c.room, &v, &c.StartedBy)
	if err == nil {
		c.Video = v != 0
		c.Dial = chatCallDialPrefix + c.room
		return c, false, tx.Commit()
	}
	if err != sql.ErrNoRows {
		return nil, false, err
	}

	if c.room, err = newChatRoom(); err != nil {
		return nil, false, err
	}
	res, err := tx.Exec(`INSERT INTO chat_calls (conversation_id, room, video, started_by, started_at) VALUES (?, ?, ?, ?, NOW())`,
		convID, c.room, video, ext)
	if err != nil {
		return nil, false, err
	}
	c.ID, _ = res.LastInsertId()
	c.Video, c.StartedBy, c.Dial = video, ext, chatCallDialPrefix+c.room
	return c, true, tx.Commit()
}

// JoinChatCall: the dial address of an open call, for the "Join" of its chat card.
func JoinChatCall(callID int64, ext string) (*ChatCall, error) {
	c := &ChatCall{ID: callID}
	var v int
	err := db.QueryRow(`SELECT conversation_id, room, video, started_by FROM chat_calls
		WHERE id = ? AND ended_at IS NULL AND started_at > DATE_SUB(NOW(), INTERVAL ? SECOND)`,
		callID, chatCallStaleSeconds).Scan(&c.ConversationID, &c.room, &v, &c.StartedBy)
	if err != nil {
		return nil, userError("This call has ended.")
	}
	if ok, perr := IsParticipant(c.ConversationID, ext); perr != nil || !ok {
		return nil, userError("You are not an active member of this group.")
	}
	c.Video = v != 0
	c.Dial = chatCallDialPrefix + c.room
	return c, nil
}

// endedChatCall: the call of a room the PBX has just closed.
func endedChatCall(room string) (*ChatCall, int, error) {
	c := &ChatCall{room: room}
	var v int
	var ended sql.NullTime
	err := db.QueryRow(`SELECT id, conversation_id, video, started_by, started_at, ended_at, peak_parties FROM chat_calls WHERE room = ?`,
		room).Scan(&c.ID, &c.ConversationID, &v, &c.StartedBy, &c.startedAt, &ended, &c.peak)
	if err != nil || !ended.Valid {
		return nil, 0, userError("No ended call in this room.")
	}
	c.Video = v != 0
	seconds := int(ended.Time.Sub(c.startedAt).Seconds())
	if seconds < 0 {
		seconds = 0
	}
	return c, seconds, nil
}

func chatCallMeta(c *ChatCall, extra map[string]string) string {
	m := map[string]string{"call_id": strconv.FormatInt(c.ID, 10), "video": "0", "actor": c.StartedBy}
	if c.Video {
		m["video"] = "1"
	}
	for k, v := range extra {
		m[k] = v
	}
	return systemMeta(m)
}

// sendCallMessage writes the call's system message and sends it to every member;
// push tells the members whose app is in the background that a call started.
func (s *Server) sendCallMessage(c *ChatCall, event, text, meta string, push bool) {
	msg, err := SaveSystemMessage(c.ConversationID, event, c.StartedBy, "", text, meta)
	if err != nil || msg == nil {
		return
	}
	payload, _ := json.Marshal(map[string]interface{}{"event": "new_message", "data": msg})
	participants, _ := GetParticipants(c.ConversationID)
	conv, _ := GetConversationByID(c.ConversationID)
	for _, ext := range participants {
		s.hub.SendToExtension(ext, payload)
		if push && ext != c.StartedBy && conv != nil {
			video := "0"
			if c.Video {
				video = "1"
			}
			TriggerFcmPush(ext, conv.Title, msg.SenderName+": "+text, "new_message", map[string]string{
				"conversation_id":   strconv.Itoa(c.ConversationID),
				"sender_ext":        c.StartedBy,
				"sender_name":       msg.SenderName,
				"msg_id":            strconv.FormatInt(msg.ID, 10),
				"msg_type":          "system",
				"conversation_type": "group",
				"group_title":       conv.Title,
				"system_event":      event,
				"call_id":           strconv.FormatInt(c.ID, 10),
				"call_video":        video,
			})
		}
	}
}

// POST /chat/api/conversations/call
//
//	{"conversation_id": 12, "video": true}   start a call, or get the one that is open
//	{"call_id": 34}                          join that call (refused once it has ended)
//
// Answers {"success": true, "created": bool, "call": {"id", "conversation_id", "video", "started_by", "dial"}}.
func (s *Server) HandleCall(w http.ResponseWriter, r *http.Request, user *User) {
	if r.Method != http.MethodPost {
		writeJSONError(w, http.StatusMethodNotAllowed, "Invalid request method.")
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, 4*1024)
	var body struct {
		ConversationID int   `json:"conversation_id"`
		CallID         int64 `json:"call_id"`
		Video          bool  `json:"video"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || (body.ConversationID <= 0 && body.CallID <= 0) {
		writeJSONError(w, http.StatusBadRequest, "Invalid request parameters.")
		return
	}
	if db == nil {
		writeJSONError(w, http.StatusServiceUnavailable, "The chat database is not available.")
		return
	}

	if body.CallID > 0 {
		call, err := JoinChatCall(body.CallID, user.Extension)
		if err != nil {
			writeJSONError(w, http.StatusGone, err.Error())
			return
		}
		writeJSON(w, http.StatusOK, map[string]interface{}{"success": true, "created": false, "call": call})
		return
	}

	call, created, err := OpenChatCall(body.ConversationID, user.Extension, body.Video)
	if err != nil {
		writeActionError(w, "Could not start the call", err)
		return
	}
	if created {
		text := "started a voice call"
		if call.Video {
			text = "started a video call"
		}
		s.sendCallMessage(call, "call_started", text, chatCallMeta(call, nil), true)
	}
	writeJSON(w, http.StatusOK, map[string]interface{}{"success": true, "created": created, "call": call})
}

// POST /api/internal/call/ended (Internal only): {"room": "123456789012"}.
// The PBX closed the call when the last participant left; the group is told.
func (s *Server) HandleInternalCallEnded(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost || !s.internalRequestAllowed(r) {
		writeJSONError(w, http.StatusForbidden, "Access denied.")
		return
	}
	var req struct {
		Room string `json:"room"`
	}
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&req); err != nil || req.Room == "" || db == nil {
		writeJSONError(w, http.StatusBadRequest, "invalid request")
		return
	}
	call, seconds, err := endedChatCall(req.Room)
	if err != nil {
		writeJSONError(w, http.StatusNotFound, err.Error())
		return
	}
	s.sendCallMessage(call, "call_ended", "The call ended", chatCallMeta(call, map[string]string{
		"duration": strconv.Itoa(seconds),
		"parties":  strconv.Itoa(call.peak),
	}), false)
	writeJSON(w, http.StatusOK, map[string]interface{}{"success": true})
}
