package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

var db *sql.DB

type User struct {
	ID        int    `json:"id"`
	Username  string `json:"username"`
	Extension string `json:"extension"`
	FullName  string `json:"full_name"`
	Role      string `json:"role"`
	IsActive  bool   `json:"is_active"`
}

type Conversation struct {
	ID              int     `json:"id"`
	Type            string  `json:"type"`
	DirectKey       string  `json:"direct_key,omitempty"`
	Title           string  `json:"title,omitempty"`
	CreatedBy       string  `json:"created_by"`
	LastMessageText string  `json:"last_message_text,omitempty"`
	LastMessageAt   *string `json:"last_message_at,omitempty"`
	CreatedAt       string  `json:"created_at"`
	UnreadCount     int     `json:"unread_count"`
	TargetExt       string  `json:"target_ext,omitempty"`
	TargetName      string  `json:"target_name,omitempty"`
	TargetOnline    bool    `json:"target_online,omitempty"`
}

type Message struct {
	ID             int64  `json:"id"`
	ConversationID int    `json:"conversation_id"`
	SenderExt      string `json:"sender_ext"`
	SenderName     string `json:"sender_name"`
	MsgType        string `json:"msg_type"`
	Message        string `json:"message"`
	AttachmentURL  string `json:"attachment_url,omitempty"`
	FileName       string `json:"file_name,omitempty"`
	FileSize       int    `json:"file_size,omitempty"`
	MimeType       string `json:"mime_type,omitempty"`
	CreatedAt      string `json:"created_at"`
	IsMe           bool   `json:"is_me,omitempty"`
}

type Contact struct {
	Extension   string `json:"extension"`
	FullName    string `json:"full_name"`
	Role        string `json:"role"`
	IsOnline    bool   `json:"is_online"`
	UnreadCount int    `json:"unread_count"`
}

func InitDB(cfg *Config) error {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:3306)/%s?charset=utf8mb4&parseTime=true&loc=Local",
		cfg.DBUser, cfg.DBPass, cfg.DBHost, cfg.DBName)

	var err error
	db, err = sql.Open("mysql", dsn)
	if err != nil {
		return err
	}

	db.SetMaxOpenConns(25)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(5 * time.Minute)

	if err := db.Ping(); err != nil {
		return fmt.Errorf("DB ping failed: %w", err)
	}

	log.Println("[DB] Connected to MariaDB successfully")
	return nil
}

func GetUserByID(id int) (*User, error) {
	row := db.QueryRow("SELECT id, username, COALESCE(extension, ''), COALESCE(full_name, ''), role, is_active FROM sys_users WHERE id = ? AND is_active = 1 LIMIT 1", id)
	var u User
	var active int
	err := row.Scan(&u.ID, &u.Username, &u.Extension, &u.FullName, &u.Role, &active)
	if err != nil {
		return nil, err
	}
	u.IsActive = active == 1
	if u.FullName == "" {
		u.FullName = u.Username
	}
	return &u, nil
}

func GetUserByExt(ext string) (*User, error) {
	row := db.QueryRow("SELECT id, username, COALESCE(extension, ''), COALESCE(full_name, ''), role, is_active FROM sys_users WHERE extension = ? AND is_active = 1 LIMIT 1", ext)
	var u User
	var active int
	err := row.Scan(&u.ID, &u.Username, &u.Extension, &u.FullName, &u.Role, &active)
	if err != nil {
		return nil, err
	}
	u.IsActive = active == 1
	if u.FullName == "" {
		u.FullName = u.Username
	}
	return &u, nil
}

func GetContacts(currentExt string) ([]Contact, error) {
	query := `
		SELECT u.extension, COALESCE(u.full_name, u.username), u.role,
		       COALESCE((
		           SELECT COUNT(m.id) 
		           FROM chat_participants p 
		           JOIN chat_conversations c ON c.id = p.conversation_id AND c.type = 'direct'
		           JOIN chat_participants p2 ON p2.conversation_id = c.id AND p2.extension = u.extension
		           JOIN chat_messages m ON m.conversation_id = c.id AND m.id > p.last_read_message_id AND m.sender_ext = u.extension
		           WHERE p.extension = ?
		       ), 0) AS unread_count
		FROM sys_users u
		WHERE u.is_active = 1 AND u.extension IS NOT NULL AND u.extension != '' AND u.extension != ? AND u.role != 'fax_user'
		ORDER BY (u.extension REGEXP '^[0-9]+$') DESC, CAST(u.extension AS UNSIGNED) ASC, u.extension ASC
	`
	rows, err := db.Query(query, currentExt, currentExt)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var contacts []Contact
	for rows.Next() {
		var c Contact
		if err := rows.Scan(&c.Extension, &c.FullName, &c.Role, &c.UnreadCount); err == nil {
			contacts = append(contacts, c)
		}
	}
	return contacts, nil
}

func GetConversations(ext string) ([]Conversation, error) {
	query := `
		SELECT c.id, c.type, COALESCE(c.direct_key, ''), COALESCE(c.title, ''), c.created_by,
		       COALESCE(c.last_message_text, ''), c.last_message_at, c.created_at,
		       (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.id > p.last_read_message_id AND m.sender_ext != p.extension) AS unread_count,
		       COALESCE((SELECT p2.extension FROM chat_participants p2 WHERE p2.conversation_id = c.id AND p2.extension != p.extension LIMIT 1), '') AS target_ext,
		       COALESCE((SELECT COALESCE(u.full_name, u.username, p2.extension) FROM chat_participants p2 JOIN sys_users u ON u.extension = p2.extension WHERE p2.conversation_id = c.id AND p2.extension != p.extension LIMIT 1), '') AS target_name
		FROM chat_conversations c
		JOIN chat_participants p ON c.id = p.conversation_id
		WHERE p.extension = ?
		ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
	`
	rows, err := db.Query(query, ext)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var convs []Conversation
	for rows.Next() {
		var cv Conversation
		var lastMsgAt sql.NullTime
		var createdAt time.Time
		err := rows.Scan(
			&cv.ID, &cv.Type, &cv.DirectKey, &cv.Title, &cv.CreatedBy,
			&cv.LastMessageText, &lastMsgAt, &createdAt,
			&cv.UnreadCount, &cv.TargetExt, &cv.TargetName,
		)
		if err == nil {
			cv.CreatedAt = createdAt.Format("2006-01-02 15:04:05")
			if lastMsgAt.Valid {
				tStr := lastMsgAt.Time.Format("2006-01-02 15:04:05")
				cv.LastMessageAt = &tStr
			}
			convs = append(convs, cv)
		}
	}
	return convs, nil
}

func GetOrCreateDirectConversation(ext1, ext2, creatorExt string) (*Conversation, error) {
	if ext1 == ext2 {
		return nil, fmt.Errorf("cannot create conversation with oneself")
	}

	directKey := ext1 + ":" + ext2
	if ext1 > ext2 {
		directKey = ext2 + ":" + ext1
	}

	// Try find existing
	var cv Conversation
	var lastMsgAt sql.NullTime
	var createdAt time.Time
	err := db.QueryRow(`
		SELECT id, type, direct_key, COALESCE(title, ''), created_by,
		       COALESCE(last_message_text, ''), last_message_at, created_at
		FROM chat_conversations
		WHERE direct_key = ?
		LIMIT 1
	`, directKey).Scan(&cv.ID, &cv.Type, &cv.DirectKey, &cv.Title, &cv.CreatedBy,
		&cv.LastMessageText, &lastMsgAt, &createdAt)

	if err == nil {
		cv.CreatedAt = createdAt.Format("2006-01-02 15:04:05")
		if lastMsgAt.Valid {
			tStr := lastMsgAt.Time.Format("2006-01-02 15:04:05")
			cv.LastMessageAt = &tStr
		}
		targetExt := ext2
		if creatorExt == ext2 {
			targetExt = ext1
		}
		cv.TargetExt = targetExt
		if targetUser, _ := GetUserByExt(targetExt); targetUser != nil {
			cv.TargetName = targetUser.FullName
		}
		return &cv, nil
	}

	// Not found, create in transaction
	tx, err := db.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	res, err := tx.Exec(`
		INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at)
		VALUES ('direct', ?, ?, NOW(), NOW())
	`, directKey, creatorExt)
	if err != nil {
		return nil, err
	}

	convID, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}

	_, err = tx.Exec(`
		INSERT INTO chat_participants (conversation_id, extension, joined_at)
		VALUES (?, ?, NOW()), (?, ?, NOW())
	`, convID, ext1, convID, ext2)
	if err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, err
	}

	cv.ID = int(convID)
	cv.Type = "direct"
	cv.DirectKey = directKey
	cv.CreatedBy = creatorExt
	cv.CreatedAt = time.Now().Format("2006-01-02 15:04:05")

	targetExt := ext2
	if creatorExt == ext2 {
		targetExt = ext1
	}
	cv.TargetExt = targetExt
	if targetUser, _ := GetUserByExt(targetExt); targetUser != nil {
		cv.TargetName = targetUser.FullName
	}

	return &cv, nil
}

func GetMessages(convID int, limit int, beforeID int64) ([]Message, error) {
	if limit <= 0 || limit > 100 {
		limit = 50
	}

	var rows *sql.Rows
	var err error

	if beforeID > 0 {
		query := `
			SELECT m.id, m.conversation_id, m.sender_ext, COALESCE(u.full_name, u.username, m.sender_ext) AS sender_name,
			       m.msg_type, COALESCE(m.message, ''), COALESCE(m.attachment_url, ''),
			       COALESCE(m.file_name, ''), m.file_size, COALESCE(m.mime_type, ''), m.created_at
			FROM chat_messages m
			LEFT JOIN sys_users u ON u.extension = m.sender_ext
			WHERE m.conversation_id = ? AND m.id < ?
			ORDER BY m.id DESC
			LIMIT ?
		`
		rows, err = db.Query(query, convID, beforeID, limit)
	} else {
		query := `
			SELECT m.id, m.conversation_id, m.sender_ext, COALESCE(u.full_name, u.username, m.sender_ext) AS sender_name,
			       m.msg_type, COALESCE(m.message, ''), COALESCE(m.attachment_url, ''),
			       COALESCE(m.file_name, ''), m.file_size, COALESCE(m.mime_type, ''), m.created_at
			FROM chat_messages m
			LEFT JOIN sys_users u ON u.extension = m.sender_ext
			WHERE m.conversation_id = ?
			ORDER BY m.id DESC
			LIMIT ?
		`
		rows, err = db.Query(query, convID, limit)
	}

	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var list []Message
	for rows.Next() {
		var m Message
		var createdAt time.Time
		err := rows.Scan(
			&m.ID, &m.ConversationID, &m.SenderExt, &m.SenderName,
			&m.MsgType, &m.Message, &m.AttachmentURL,
			&m.FileName, &m.FileSize, &m.MimeType, &createdAt,
		)
		if err == nil {
			m.CreatedAt = createdAt.Format("2006-01-02 15:04:05")
			list = append(list, m)
		}
	}

	// Reverse to chronological order (oldest to newest)
	for i, j := 0, len(list)-1; i < j; i, j = i+1, j-1 {
		list[i], list[j] = list[j], list[i]
	}

	return list, nil
}

func SaveMessage(convID int, senderExt, msgType, message, attachmentURL, fileName string, fileSize int, mimeType string) (*Message, error) {
	tx, err := db.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	res, err := tx.Exec(`
		INSERT INTO chat_messages (conversation_id, sender_ext, msg_type, message, attachment_url, file_name, file_size, mime_type, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
	`, convID, senderExt, msgType, message, attachmentURL, fileName, fileSize, mimeType)
	if err != nil {
		return nil, err
	}

	msgID, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}

	lastPreview := message
	if msgType == "image" {
		lastPreview = "📷 [Fotoğraf]"
	} else if msgType == "file" {
		lastPreview = "📎 [Dosya] " + fileName
	} else if msgType == "audio" {
		lastPreview = "🎤 [Ses Kaydı]"
	}
	if len(lastPreview) > 250 {
		lastPreview = lastPreview[:247] + "..."
	}

	_, err = tx.Exec(`
		UPDATE chat_conversations
		SET last_message_text = ?, last_message_at = NOW(), updated_at = NOW()
		WHERE id = ?
	`, lastPreview, convID)
	if err != nil {
		return nil, err
	}

	// Sender automatically marks their own sent message as read
	_, _ = tx.Exec(`
		UPDATE chat_participants
		SET last_read_message_id = ?
		WHERE conversation_id = ? AND extension = ?
	`, msgID, convID, senderExt)

	if err := tx.Commit(); err != nil {
		return nil, err
	}

	senderName := senderExt
	if u, _ := GetUserByExt(senderExt); u != nil {
		senderName = u.FullName
	}

	return &Message{
		ID:             msgID,
		ConversationID: convID,
		SenderExt:      senderExt,
		SenderName:     senderName,
		MsgType:        msgType,
		Message:        message,
		AttachmentURL:  attachmentURL,
		FileName:       fileName,
		FileSize:       fileSize,
		MimeType:       mimeType,
		CreatedAt:      time.Now().Format("2006-01-02 15:04:05"),
	}, nil
}

func MarkConversationAsRead(convID int, ext string, lastMsgID int64) error {
	if lastMsgID <= 0 {
		// Get max message id in conversation
		_ = db.QueryRow("SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE conversation_id = ?", convID).Scan(&lastMsgID)
	}

	_, err := db.Exec(`
		UPDATE chat_participants
		SET last_read_message_id = GREATEST(last_read_message_id, ?)
		WHERE conversation_id = ? AND extension = ?
	`, lastMsgID, convID, ext)
	return err
}

func GetParticipants(convID int) ([]string, error) {
	rows, err := db.Query("SELECT extension FROM chat_participants WHERE conversation_id = ?", convID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var exts []string
	for rows.Next() {
		var ext string
		if err := rows.Scan(&ext); err == nil {
			exts = append(exts, ext)
		}
	}
	return exts, nil
}

// IsParticipant verifies whether the given extension belongs to the conversation (CH-1, CH-2)
func IsParticipant(convID int, ext string) (bool, error) {
	if convID <= 0 || ext == "" || db == nil {
		return false, nil
	}
	var exists int
	err := db.QueryRow("SELECT 1 FROM chat_participants WHERE conversation_id = ? AND extension = ? LIMIT 1", convID, ext).Scan(&exists)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, nil
}

// GetConversationIDForAttachment finds the conversation that owns this attachment (CH-3)
func GetConversationIDForAttachment(filenameOrURL string) (int, error) {
	if filenameOrURL == "" || db == nil {
		return 0, nil
	}
	var convID int
	err := db.QueryRow("SELECT conversation_id FROM chat_messages WHERE attachment_url LIKE ? LIMIT 1", "%"+filenameOrURL+"%").Scan(&convID)
	if err == sql.ErrNoRows {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}
	return convID, nil
}
