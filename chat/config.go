package main

import (
	"bufio"
	"os"
	"strings"
)

type Config struct {
	Port         string
	DBHost       string
	DBName       string
	DBUser       string
	DBPass       string
	SecretKey    string
	UploadDir    string
	PortalDomain string
}

func LoadConfig() (*Config, error) {
	cfg := &Config{
		Port:         "8086",
		DBHost:       "localhost",
		DBName:       "asterisk",
		DBUser:       "aipbx_portal",
		DBPass:       "",
		SecretKey:    "",
		UploadDir:    "/var/lib/aipbx/chat_files",
		PortalDomain: "localhost",
	}

	var chatJwtSecret, turnSecret string

	envFile := "/etc/ai-pbx.env"
	f, err := os.Open(envFile)
	if err == nil {
		defer f.Close()
		scanner := bufio.NewScanner(f)
		for scanner.Scan() {
			line := strings.TrimSpace(scanner.Text())
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 {
				k := strings.TrimSpace(parts[0])
				v := strings.Trim(strings.TrimSpace(parts[1]), "\"'")
				switch k {
				case "DB_HOST":
					cfg.DBHost = v
				case "DB_NAME":
					cfg.DBName = v
				case "DB_USER":
					cfg.DBUser = v
				case "DB_PASS":
					cfg.DBPass = v
				case "CHAT_JWT_SECRET":
					if v != "" {
						chatJwtSecret = v
					}
				case "TURN_SECRET":
					if v != "" {
						turnSecret = v
					}
				case "PORTAL_DOMAIN":
					cfg.PortalDomain = v
				case "CHAT_PORT":
					cfg.Port = v
				case "CHAT_UPLOAD_DIR":
					cfg.UploadDir = v
				}
			}
		}
	}

	if chatJwtSecret != "" {
		cfg.SecretKey = chatJwtSecret
	} else if turnSecret != "" {
		cfg.SecretKey = turnSecret
	} else {
		cfg.SecretKey = cfg.DBPass
	}

	if envPort := os.Getenv("CHAT_PORT"); envPort != "" {
		cfg.Port = envPort
	}

	return cfg, nil
}
