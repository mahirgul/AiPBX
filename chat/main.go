package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {
	log.Println("[AI-PBX Chat] Starting Chat & Media Service...")

	cfg, err := LoadConfig()
	if err != nil {
		log.Fatalf("[Config] Failed to load config: %v", err)
	}

	if err := InitDB(cfg); err != nil {
		log.Fatalf("[DB] Failed to connect: %v", err)
	}

	_ = os.MkdirAll(cfg.UploadDir+"/images", 0755)
	_ = os.MkdirAll(cfg.UploadDir+"/thumbs", 0755)
	_ = os.MkdirAll(cfg.UploadDir+"/docs", 0755)
	_ = os.MkdirAll(cfg.UploadDir+"/avatars", 0755)

	hub := NewHub()
	go hub.Run()

	server := NewServer(cfg, hub)

	mux := http.NewServeMux()

	registerRoutes := func(prefix string) {
		mux.HandleFunc(prefix+"/ws", server.HandleWS)

		mux.HandleFunc(prefix+"/api/me", server.authMiddleware(server.HandleMe))
		mux.HandleFunc(prefix+"/api/contacts", server.authMiddleware(server.HandleContacts))
		mux.HandleFunc(prefix+"/api/conversations", server.authMiddleware(server.HandleConversations))
		mux.HandleFunc(prefix+"/api/conversations/direct", server.authMiddleware(server.HandleCreateDirectConversation))
		mux.HandleFunc(prefix+"/api/conversations/group", server.authMiddleware(func(w http.ResponseWriter, r *http.Request, user *User) {
			if r.Method == http.MethodPost {
				server.HandleCreateGroup(w, r, user)
			} else if r.Method == http.MethodGet {
				server.HandleGetGroupDetails(w, r, user)
			} else {
				writeJSONError(w, http.StatusMethodNotAllowed, "Geçersiz istek metodu.")
			}
		}))
		mux.HandleFunc(prefix+"/api/conversations/group/update", server.authMiddleware(server.HandleUpdateGroup))
		mux.HandleFunc(prefix+"/api/conversations/group/members/add", server.authMiddleware(server.HandleAddGroupMembers))
		mux.HandleFunc(prefix+"/api/conversations/group/members/remove", server.authMiddleware(server.HandleRemoveGroupMember))
		mux.HandleFunc(prefix+"/api/conversations/group/members/role", server.authMiddleware(server.HandleUpdateGroupMemberRole))
		mux.HandleFunc(prefix+"/api/conversations/group/leave", server.authMiddleware(server.HandleLeaveGroup))
		mux.HandleFunc(prefix+"/api/conversations/group/delete", server.authMiddleware(server.HandleDeleteGroup))

		mux.HandleFunc(prefix+"/api/messages", server.authMiddleware(func(w http.ResponseWriter, r *http.Request, user *User) {
			if r.Method == http.MethodPost {
				server.HandleSendMessage(w, r, user)
			} else {
				server.HandleGetMessages(w, r, user)
			}
		}))
		mux.HandleFunc(prefix+"/api/read", server.authMiddleware(server.HandleMarkRead))
		mux.HandleFunc(prefix+"/api/upload", server.authMiddleware(server.HandleUpload))
		mux.HandleFunc(prefix+"/media/", server.authMiddleware(server.HandleMedia))
		mux.HandleFunc(prefix+"/health", func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"status":"ok","service":"aipbx-chat"}`))
		})
	}

	// Register with and without /chat prefix for seamless reverse proxying
	registerRoutes("")
	registerRoutes("/chat")

	httpServer := &http.Server{
		Addr:         "127.0.0.1:" + cfg.Port,
		Handler:      mux,
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 30 * time.Second,
	}

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)

	go func() {
		log.Printf("[AI-PBX Chat] Listening on 127.0.0.1:%s", cfg.Port)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("[Server] Listen error: %v", err)
		}
	}()

	<-stop
	log.Println("[AI-PBX Chat] Shutting down gracefully...")

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_ = httpServer.Shutdown(ctx)
	if db != nil {
		_ = db.Close()
	}
	log.Println("[AI-PBX Chat] Service stopped.")
}
