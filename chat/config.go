package main

import (
	"bufio"
	"fmt"
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
	// Timezone, the same TIMEZONE setting as the portal (e.g. Europe/Istanbul) —
	// message times are shown in this zone. The database always stores UTC.
	Timezone string
	// AIPBX_SETTINGS_KEY: decrypts credentials the portal stored (secretbox.go).
	SettingsKey string
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
		Timezone:     "Europe/Istanbul",
	}

	var chatJwtSecret string

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
				case "PORTAL_DOMAIN":
					cfg.PortalDomain = v
				case "CHAT_PORT":
					cfg.Port = v
				case "CHAT_UPLOAD_DIR":
					cfg.UploadDir = v
				case "AIPBX_SETTINGS_KEY":
					cfg.SettingsKey = v
				case "TIMEZONE":
					if v != "" {
						cfg.Timezone = v
					}
				}
			}
		}
	}

	if chatJwtSecret == "" {
		chatJwtSecret = strings.TrimSpace(os.Getenv("CHAT_JWT_SECRET"))
	}
	if len(chatJwtSecret) < 32 {
		// No fallback to TURN_SECRET or database password: CHAT_JWT_SECRET must be a dedicated secret >= 32 bytes.
		return nil, fmt.Errorf("CHAT_JWT_SECRET must be set in /etc/ai-pbx.env (at least 32 bytes)")
	}
	cfg.SecretKey = chatJwtSecret

	if envPort := os.Getenv("CHAT_PORT"); envPort != "" {
		cfg.Port = envPort
	}

	return cfg, nil
}
