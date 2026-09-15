package main

import (
	"database/sql"
	"fmt"
	"log"
	"strings"
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
	ID              int           `json:"id"`
	Type            string        `json:"type"`
	DirectKey       string        `json:"direct_key,omitempty"`
	Title           string        `json:"title,omitempty"`
	AvatarURL       string        `json:"avatar_url,omitempty"`
	Description     string        `json:"description,omitempty"`
	CreatedBy       string        `json:"created_by"`
	LastMessageText string        `json:"last_message_text,omitempty"`
	LastMessageAt   *string       `json:"last_message_at,omitempty"`
	CreatedAt       string        `json:"created_at"`
	UnreadCount     int           `json:"unread_count"`
	TargetExt       string        `json:"target_ext,omitempty"`
	TargetName      string        `json:"target_name,omitempty"`
	TargetOnline    bool          `json:"target_online,omitempty"`
	MemberCount     int           `json:"member_count,omitempty"`
	OnlineCount     int           `json:"online_count,omitempty"`
	MyRole          string        `json:"my_role,omitempty"`
	Participants    []Participant `json:"participants,omitempty"`
}

type Participant struct {
	Extension string `json:"extension"`
	FullName  string `json:"full_name"`
	Role      string `json:"role"`
	IsOnline  bool   `json:"is_online"`
	JoinedAt  string `json:"joined_at"`
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
	SystemEvent    string `json:"system_event,omitempty"`
	SystemMeta     string `json:"system_meta,omitempty"`
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
		SELECT c.id, c.type, COALESCE(c.direct_key, ''), COALESCE(c.title, ''),
		       COALESCE(c.avatar_url, ''), COALESCE(c.description, ''), c.created_by,
		       COALESCE(c.last_message_text, ''), c.last_message_at, c.created_at,
		       (SELECT COUNT(*) FROM chat_messages m WHERE m.conversation_id = c.id AND m.id > p.last_read_message_id AND m.sender_ext != p.extension) AS unread_count,
		       CASE WHEN c.type = 'group' THEN '' ELSE COALESCE((SELECT p2.extension FROM chat_participants p2 WHERE p2.conversation_id = c.id AND p2.extension != p.extension AND p2.left_at IS NULL LIMIT 1), '') END AS target_ext,
		       CASE WHEN c.type = 'group' THEN '' ELSE COALESCE((SELECT COALESCE(u.full_name, u.username, p2.extension) FROM chat_participants p2 JOIN sys_users u ON u.extension = p2.extension WHERE p2.conversation_id = c.id AND p2.extension != p.extension AND p2.left_at IS NULL LIMIT 1), '') END AS target_name,
		       (SELECT COUNT(*) FROM chat_participants cp WHERE cp.conversation_id = c.id AND cp.left_at IS NULL) AS member_count,
		       COALESCE(p.role, 'member') AS my_role
		FROM chat_conversations c
		JOIN chat_participants p ON c.id = p.conversation_id
		WHERE p.extension = ? AND p.left_at IS NULL AND c.is_deleted = 0
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
			&cv.ID, &cv.Type, &cv.DirectKey, &cv.Title,
			&cv.AvatarURL, &cv.Description, &cv.CreatedBy,
			&cv.LastMessageText, &lastMsgAt, &createdAt,
			&cv.UnreadCount, &cv.TargetExt, &cv.TargetName,
			&cv.MemberCount, &cv.MyRole,
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
		WHERE direct_key = ? AND is_deleted = 0
		LIMIT 1
	`, directKey).Scan(&cv.ID, &cv.Type, &cv.DirectKey, &cv.Title, &cv.CreatedBy,
		&cv.LastMessageText, &lastMsgAt, &createdAt)

	if err == nil {
		// Ensure both participants are active (left_at is cleared)
		_, _ = db.Exec("UPDATE chat_participants SET left_at = NULL WHERE conversation_id = ? AND extension IN (?, ?)", cv.ID, ext1, ext2)

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
		cv.MemberCount = 2
		cv.MyRole = "member"
		return &cv, nil
	}

	// Not found, create in transaction
	tx, err := db.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	res, err := tx.Exec(`
		INSERT INTO chat_conversations (type, direct_key, created_by, created_at, updated_at, is_deleted)
		VALUES ('direct', ?, ?, NOW(), NOW(), 0)
	`, directKey, creatorExt)
	if err != nil {
		return nil, err
	}

	convID, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}

	_, err = tx.Exec(`
		INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'member', NOW()), (?, ?, 'member', NOW())
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
	cv.MemberCount = 2
	cv.MyRole = "member"

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
			       COALESCE(m.file_name, ''), m.file_size, COALESCE(m.mime_type, ''),
			       COALESCE(m.system_event, ''), COALESCE(m.system_meta, ''), m.created_at
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
			       COALESCE(m.file_name, ''), m.file_size, COALESCE(m.mime_type, ''),
			       COALESCE(m.system_event, ''), COALESCE(m.system_meta, ''), m.created_at
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
			&m.FileName, &m.FileSize, &m.MimeType,
			&m.SystemEvent, &m.SystemMeta, &createdAt,
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
	rows, err := db.Query("SELECT extension FROM chat_participants WHERE conversation_id = ? AND left_at IS NULL", convID)
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

// IsParticipant verifies whether the given extension belongs to the conversation (CH-1, CH-2, CH-G6)
func IsParticipant(convID int, ext string) (bool, error) {
	if convID <= 0 || ext == "" || db == nil {
		return false, nil
	}
	var exists int
	err := db.QueryRow("SELECT 1 FROM chat_participants WHERE conversation_id = ? AND extension = ? AND left_at IS NULL LIMIT 1", convID, ext).Scan(&exists)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, nil
}

// GetConversationIDForAttachment finds the conversation that owns this attachment (CH-3, CH-G5)
func GetConversationIDForAttachment(filenameOrURL string) (int, error) {
	if filenameOrURL == "" || db == nil {
		return 0, nil
	}
	var convID int
	err := db.QueryRow("SELECT conversation_id FROM chat_messages WHERE attachment_url LIKE ? LIMIT 1", "%"+filenameOrURL+"%").Scan(&convID)
	if err == nil {
		return convID, nil
	}
	// Check group avatar (CH-G5)
	err = db.QueryRow("SELECT id FROM chat_conversations WHERE avatar_url LIKE ? AND is_deleted = 0 LIMIT 1", "%"+filenameOrURL+"%").Scan(&convID)
	if err == nil {
		return convID, nil
	}
	return 0, nil
}

func GetConversationByID(convID int) (*Conversation, error) {
	if convID <= 0 || db == nil {
		return nil, fmt.Errorf("Geçersiz konuşma ID")
	}
	var cv Conversation
	var lastMsgAt sql.NullTime
	var createdAt time.Time
	err := db.QueryRow(`
		SELECT id, type, COALESCE(direct_key, ''), COALESCE(title, ''), COALESCE(avatar_url, ''),
		       COALESCE(description, ''), created_by, COALESCE(last_message_text, ''),
		       last_message_at, created_at,
		       (SELECT COUNT(*) FROM chat_participants cp WHERE cp.conversation_id = chat_conversations.id AND cp.left_at IS NULL) AS member_count
		FROM chat_conversations
		WHERE id = ? AND is_deleted = 0
		LIMIT 1
	`, convID).Scan(&cv.ID, &cv.Type, &cv.DirectKey, &cv.Title, &cv.AvatarURL,
		&cv.Description, &cv.CreatedBy, &cv.LastMessageText, &lastMsgAt, &createdAt, &cv.MemberCount)
	if err != nil {
		return nil, err
	}
	cv.CreatedAt = createdAt.Format("2006-01-02 15:04:05")
	if lastMsgAt.Valid {
		tStr := lastMsgAt.Time.Format("2006-01-02 15:04:05")
		cv.LastMessageAt = &tStr
	}
	return &cv, nil
}

// CreateGroupConversation creates a new group chat and seeds group_created system message
func CreateGroupConversation(title, creatorExt, avatarURL, description string, members []string) (*Conversation, *Message, error) {
	title = strings.TrimSpace(title)
	title = strings.ReplaceAll(title, "\r", "")
	title = strings.ReplaceAll(title, "\n", "")
	if title == "" {
		return nil, nil, fmt.Errorf("Grup adı boş olamaz")
	}
	if len(title) > 100 {
		title = title[:100]
	}
	description = strings.TrimSpace(description)
	if len(description) > 255 {
		description = description[:255]
	}

	// Clean and deduplicate members
	memberMap := make(map[string]bool)
	memberMap[creatorExt] = true
	var cleanMembers []string
	for _, m := range members {
		m = strings.TrimSpace(m)
		if m != "" && m != creatorExt && !memberMap[m] {
			memberMap[m] = true
			cleanMembers = append(cleanMembers, m)
		}
	}

	// CH-G3: En fazla 256 üye
	if len(memberMap) > 256 {
		return nil, nil, fmt.Errorf("Grup üye sayısı en fazla 256 olabilir")
	}

	// CH-G2: sys_users doğrulaması
	for m := range memberMap {
		u, err := GetUserByExt(m)
		if err != nil || u == nil || !u.IsActive || u.Role == "fax_user" {
			return nil, nil, fmt.Errorf("Geçersiz veya pasif kullanıcı dahili numarası: %s", m)
		}
	}

	tx, err := db.Begin()
	if err != nil {
		return nil, nil, err
	}
	defer tx.Rollback()

	res, err := tx.Exec(`
		INSERT INTO chat_conversations (type, title, avatar_url, description, created_by, created_at, updated_at, is_deleted)
		VALUES ('group', ?, ?, ?, ?, NOW(), NOW(), 0)
	`, title, avatarURL, description, creatorExt)
	if err != nil {
		return nil, nil, err
	}
	convID64, err := res.LastInsertId()
	if err != nil {
		return nil, nil, err
	}
	convID := int(convID64)

	// Oluşturan admin rolüyle eklenir
	_, err = tx.Exec(`
		INSERT INTO chat_participants (conversation_id, extension, role, joined_at)
		VALUES (?, ?, 'admin', NOW())
	`, convID, creatorExt)
	if err != nil {
		return nil, nil, err
	}

	// Diğer üyeler eklenir
	for _, m := range cleanMembers {
		_, err = tx.Exec(`
			INSERT INTO chat_participants (conversation_id, extension, role, added_by, joined_at)
			VALUES (?, ?, 'member', ?, NOW())
		`, convID, m, creatorExt)
		if err != nil {
			return nil, nil, err
		}
	}

	// Sistem mesajı kaydet
	creatorName := creatorExt
	if u, _ := GetUserByExt(creatorExt); u != nil {
		creatorName = u.FullName
	}
	sysMsgText := fmt.Sprintf("%s \"%s\" grubunu oluşturdu", creatorName, title)
	sysMeta := fmt.Sprintf(`{"actor":"%s","value":"%s"}`, creatorExt, title)

	sRes, err := tx.Exec(`
		INSERT INTO chat_messages (conversation_id, sender_ext, msg_type, message, system_event, system_meta, created_at)
		VALUES (?, ?, 'system', ?, 'group_created', ?, NOW())
	`, convID, creatorExt, sysMsgText, sysMeta)
	if err != nil {
		return nil, nil, err
	}
	sysMsgID, _ := sRes.LastInsertId()

	_, err = tx.Exec(`
		UPDATE chat_conversations
		SET last_message_text = ?, last_message_at = NOW()
		WHERE id = ?
	`, sysMsgText, convID)
	if err != nil {
		return nil, nil, err
	}

	// Oluşturan sistem mesajını okundu işaretler
	_, _ = tx.Exec(`
		UPDATE chat_participants
		SET last_read_message_id = ?
		WHERE conversation_id = ? AND extension = ?
	`, sysMsgID, convID, creatorExt)

	if err := tx.Commit(); err != nil {
		return nil, nil, err
	}

	nowStr := time.Now().Format("2006-01-02 15:04:05")
	sysMsg := &Message{
		ID:             sysMsgID,
		ConversationID: convID,
		SenderExt:      creatorExt,
		SenderName:     creatorName,
		MsgType:        "system",
		Message:        sysMsgText,
		SystemEvent:    "group_created",
		SystemMeta:     sysMeta,
		CreatedAt:      nowStr,
	}
	return &Conversation{
		ID:              convID,
		Type:            "group",
		Title:           title,
		AvatarURL:       avatarURL,
		Description:     description,
		CreatedBy:       creatorExt,
		LastMessageText: sysMsgText,
		LastMessageAt:   &nowStr,
		CreatedAt:       nowStr,
		UnreadCount:     0,
		MemberCount:     len(memberMap),
		MyRole:          "admin",
	}, sysMsg, nil
}

// GetGroupDetails returns conversation metadata and active participant list
func GetGroupDetails(convID int, requesterExt string) (*Conversation, error) {
	conv, err := GetConversationByID(convID)
	if err != nil {
		return nil, err
	}
	if conv.Type != "group" {
		return nil, fmt.Errorf("Bu sohbet bir grup değildir")
	}

	rows, err := db.Query(`
		SELECT p.extension, COALESCE(u.full_name, u.username, p.extension) AS full_name,
		       COALESCE(p.role, 'member') AS role, p.joined_at
		FROM chat_participants p
		LEFT JOIN sys_users u ON u.extension = p.extension
		WHERE p.conversation_id = ? AND p.left_at IS NULL
		ORDER BY (p.role = 'admin') DESC, p.joined_at ASC
	`, convID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var parts []Participant
	for rows.Next() {
		var pt Participant
		var joinedAt time.Time
		if err := rows.Scan(&pt.Extension, &pt.FullName, &pt.Role, &joinedAt); err == nil {
			pt.JoinedAt = joinedAt.Format("2006-01-02 15:04:05")
			if pt.Extension == requesterExt {
				conv.MyRole = pt.Role
			}
			parts = append(parts, pt)
		}
	}
	conv.Participants = parts
	conv.MemberCount = len(parts)

	return conv, nil
}

// AddGroupMembers adds new members to a group
func AddGroupMembers(convID int, actorExt string, exts []string) ([]string, *Message, error) {
	isAdmin, err := IsGroupAdmin(convID, actorExt)
	if err != nil || !isAdmin {
		return nil, nil, fmt.Errorf("Grup üyesi eklemek için yönetici olmalısınız")
	}

	var currentCount int
	_ = db.QueryRow("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ? AND left_at IS NULL", convID).Scan(&currentCount)

	var validExts []string
	for _, e := range exts {
		e = strings.TrimSpace(e)
		if e == "" {
			continue
		}
		u, err := GetUserByExt(e)
		if err != nil || u == nil || !u.IsActive || u.Role == "fax_user" {
			continue
		}
		var activeExists int
		_ = db.QueryRow("SELECT 1 FROM chat_participants WHERE conversation_id = ? AND extension = ? AND left_at IS NULL", convID, e).Scan(&activeExists)
		if activeExists == 1 {
			continue
		}
		validExts = append(validExts, e)
	}

	if len(validExts) == 0 {
		return nil, nil, fmt.Errorf("Eklenebilecek geçerli yeni kullanıcı bulunamadı")
	}

	if currentCount+len(validExts) > 256 {
		return nil, nil, fmt.Errorf("Grup üye sayısı en fazla 256 olabilir")
	}

	tx, err := db.Begin()
	if err != nil {
		return nil, nil, err
	}
	defer tx.Rollback()

	var added []string
	for _, e := range validExts {
		_, err = tx.Exec(`
			INSERT INTO chat_participants (conversation_id, extension, role, added_by, joined_at, left_at)
			VALUES (?, ?, 'member', ?, NOW(), NULL)
			ON DUPLICATE KEY UPDATE left_at = NULL, role = 'member', added_by = VALUES(added_by), joined_at = NOW()
		`, convID, e, actorExt)
		if err == nil {
			added = append(added, e)
		}
	}

	if len(added) == 0 {
		return nil, nil, fmt.Errorf("Üyeler eklenemedi")
	}

	actorName := actorExt
	if u, _ := GetUserByExt(actorExt); u != nil {
		actorName = u.FullName
	}

	var targetNames []string
	for _, ae := range added {
		nm := ae
		if u, _ := GetUserByExt(ae); u != nil {
			nm = u.FullName
		}
		targetNames = append(targetNames, nm)
	}

	sysMsgText := fmt.Sprintf("%s, %s kullanıcısını gruba ekledi", actorName, strings.Join(targetNames, ", "))
	sysMeta := fmt.Sprintf(`{"actor":"%s","target":"%s"}`, actorExt, strings.Join(added, ","))

	sRes, err := tx.Exec(`
		INSERT INTO chat_messages (conversation_id, sender_ext, msg_type, message, system_event, system_meta, created_at)
		VALUES (?, ?, 'system', ?, 'member_added', ?, NOW())
	`, convID, actorExt, sysMsgText, sysMeta)
	if err != nil {
		return nil, nil, err
	}
	sysMsgID, _ := sRes.LastInsertId()

	_, _ = tx.Exec(`
		UPDATE chat_conversations
		SET last_message_text = ?, last_message_at = NOW(), updated_at = NOW()
		WHERE id = ?
	`, sysMsgText, convID)

	if err := tx.Commit(); err != nil {
		return nil, nil, err
	}

	sysMsg := &Message{
		ID:             sysMsgID,
		ConversationID: convID,
		SenderExt:      actorExt,
		SenderName:     actorName,
		MsgType:        "system",
		Message:        sysMsgText,
		SystemEvent:    "member_added",
		SystemMeta:     sysMeta,
		CreatedAt:      time.Now().Format("2006-01-02 15:04:05"),
	}

	return added, sysMsg, nil
}

// RemoveGroupMember removes a member from group (soft removal via left_at)
func RemoveGroupMember(convID int, actorExt, targetExt string) (*Message, error) {
	isAdmin, err := IsGroupAdmin(convID, actorExt)
	if err != nil || !isAdmin {
		return nil, fmt.Errorf("Gruptan üye çıkarmak için yönetici olmalısınız")
	}
	if actorExt == targetExt {
		return nil, fmt.Errorf("Kendi hesabınızı gruptan çıkaramazsınız, gruptan ayrıl seçeneğini kullanın")
	}

	res, err := db.Exec(`
		UPDATE chat_participants
		SET left_at = NOW()
		WHERE conversation_id = ? AND extension = ? AND left_at IS NULL
	`, convID, targetExt)
	if err != nil {
		return nil, err
	}
	rowsAff, _ := res.RowsAffected()
	if rowsAff == 0 {
		return nil, fmt.Errorf("Belirtilen kullanıcı grupta aktif değil")
	}

	actorName := actorExt
	if u, _ := GetUserByExt(actorExt); u != nil {
		actorName = u.FullName
	}
	targetName := targetExt
	if u, _ := GetUserByExt(targetExt); u != nil {
		targetName = u.FullName
	}

	sysMsgText := fmt.Sprintf("%s, %s kullanıcısını gruptan çıkardı", actorName, targetName)
	sysMeta := fmt.Sprintf(`{"actor":"%s","target":"%s"}`, actorExt, targetExt)
	sysMsg, err := SaveSystemMessage(convID, "member_removed", actorExt, targetExt, sysMsgText, sysMeta)
	if err != nil {
		return nil, err
	}

	return sysMsg, nil
}

// LeaveGroup handles a member voluntarily leaving a group
func LeaveGroup(convID int, ext string) (*Message, error) {
	isPart, err := IsParticipant(convID, ext)
	if err != nil || !isPart {
		return nil, fmt.Errorf("Bu grubun aktif bir üyesi değilsiniz")
	}

	_, err = db.Exec(`
		UPDATE chat_participants
		SET left_at = NOW()
		WHERE conversation_id = ? AND extension = ? AND left_at IS NULL
	`, convID, ext)
	if err != nil {
		return nil, err
	}

	var remainingCount int
	_ = db.QueryRow("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ? AND left_at IS NULL", convID).Scan(&remainingCount)

	if remainingCount == 0 {
		_, _ = db.Exec("UPDATE chat_conversations SET is_deleted = 1, updated_at = NOW() WHERE id = ?", convID)
		return nil, nil
	}

	// Son admin ayrılırsa en eski üyeyi admin yap
	var adminCount int
	_ = db.QueryRow("SELECT COUNT(*) FROM chat_participants WHERE conversation_id = ? AND role = 'admin' AND left_at IS NULL", convID).Scan(&adminCount)
	if adminCount == 0 {
		_, _ = db.Exec(`
			UPDATE chat_participants
			SET role = 'admin'
			WHERE conversation_id = ? AND left_at IS NULL
			ORDER BY joined_at ASC
			LIMIT 1
		`, convID)
	}

	userName := ext
	if u, _ := GetUserByExt(ext); u != nil {
		userName = u.FullName
	}
	sysMsgText := fmt.Sprintf("%s gruptan ayrıldı", userName)
	sysMeta := fmt.Sprintf(`{"actor":"%s"}`, ext)
	sysMsg, _ := SaveSystemMessage(convID, "member_left", ext, "", sysMsgText, sysMeta)

	return sysMsg, nil
}

// UpdateGroupInfo updates group title, avatar, and description
func UpdateGroupInfo(convID int, actorExt, title, avatarURL, description string) (*Message, error) {
	isAdmin, err := IsGroupAdmin(convID, actorExt)
	if err != nil || !isAdmin {
		return nil, fmt.Errorf("Grup bilgilerini güncellemek için yönetici olmalısınız")
	}

	title = strings.TrimSpace(title)
	title = strings.ReplaceAll(title, "\r", "")
	title = strings.ReplaceAll(title, "\n", "")
	if title == "" {
		return nil, fmt.Errorf("Grup adı boş olamaz")
	}
	if len(title) > 100 {
		title = title[:100]
	}
	description = strings.TrimSpace(description)
	if len(description) > 255 {
		description = description[:255]
	}

	_, err = db.Exec(`
		UPDATE chat_conversations
		SET title = ?, avatar_url = ?, description = ?, updated_at = NOW()
		WHERE id = ? AND is_deleted = 0
	`, title, avatarURL, description, convID)
	if err != nil {
		return nil, err
	}

	actorName := actorExt
	if u, _ := GetUserByExt(actorExt); u != nil {
		actorName = u.FullName
	}
	sysMsgText := fmt.Sprintf("%s grup bilgilerini güncelledi", actorName)
	sysMeta := fmt.Sprintf(`{"actor":"%s","value":"%s"}`, actorExt, title)
	sysMsg, _ := SaveSystemMessage(convID, "group_updated", actorExt, "", sysMsgText, sysMeta)

	return sysMsg, nil
}

// UpdateGroupMemberRole updates an active participant's role (admin|member)
func UpdateGroupMemberRole(convID int, actorExt, targetExt, newRole string) error {
	isAdmin, err := IsGroupAdmin(convID, actorExt)
	if err != nil || !isAdmin {
		return fmt.Errorf("Grup yetkilerini değiştirmek için yönetici olmalısınız")
	}
	if newRole != "admin" && newRole != "member" {
		return fmt.Errorf("Geçersiz rol: %s", newRole)
	}

	res, err := db.Exec(`
		UPDATE chat_participants
		SET role = ?
		WHERE conversation_id = ? AND extension = ? AND left_at IS NULL
	`, newRole, convID, targetExt)
	if err != nil {
		return err
	}
	rowsAff, _ := res.RowsAffected()
	if rowsAff == 0 {
		return fmt.Errorf("Kullanıcı grupta aktif değil")
	}
	return nil
}

// DeleteGroup soft-deletes a group conversation
func DeleteGroup(convID int, actorExt string) error {
	isAdmin, err := IsGroupAdmin(convID, actorExt)
	if err != nil || !isAdmin {
		return fmt.Errorf("Grubu silmek için yönetici olmalısınız")
	}

	_, err = db.Exec("UPDATE chat_conversations SET is_deleted = 1, updated_at = NOW() WHERE id = ?", convID)
	return err
}

// IsGroupAdmin verifies whether the extension is an active group admin (CH-G1)
func IsGroupAdmin(convID int, ext string) (bool, error) {
	if convID <= 0 || ext == "" || db == nil {
		return false, nil
	}
	var exists int
	err := db.QueryRow(`
		SELECT 1 FROM chat_participants
		WHERE conversation_id = ? AND extension = ? AND role = 'admin' AND left_at IS NULL
		LIMIT 1
	`, convID, ext).Scan(&exists)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, nil
}

// SaveSystemMessage records a system event in the chat stream
func SaveSystemMessage(convID int, event, actorExt, targetExt, text, meta string) (*Message, error) {
	res, err := db.Exec(`
		INSERT INTO chat_messages (conversation_id, sender_ext, msg_type, message, system_event, system_meta, created_at)
		VALUES (?, ?, 'system', ?, ?, ?, NOW())
	`, convID, actorExt, text, event, meta)
	if err != nil {
		return nil, err
	}
	msgID, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}

	_, _ = db.Exec(`
		UPDATE chat_conversations
		SET last_message_text = ?, last_message_at = NOW(), updated_at = NOW()
		WHERE id = ?
	`, text, convID)

	actorName := actorExt
	if u, _ := GetUserByExt(actorExt); u != nil {
		actorName = u.FullName
	}

	return &Message{
		ID:             msgID,
		ConversationID: convID,
		SenderExt:      actorExt,
		SenderName:     actorName,
		MsgType:        "system",
		Message:        text,
		SystemEvent:    event,
		SystemMeta:     meta,
		CreatedAt:      time.Now().Format("2006-01-02 15:04:05"),
	}, nil
}

// GetGroupMemberExtensions returns active member extensions in conversation
func GetGroupMemberExtensions(convID int) ([]string, error) {
	return GetParticipants(convID)
}
