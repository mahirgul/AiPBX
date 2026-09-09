package main

import (
	"encoding/json"
	"log"
	"os/exec"
)

func TriggerFcmPush(toExt, title, body, action string, extra map[string]string) {
	go func() {
		extraJSON, err := json.Marshal(extra)
		if err != nil {
			extraJSON = []byte("{}")
		}

		cmd := exec.Command("php", "/var/www/html/bin/send_chat_push.php", toExt, title, body, action, string(extraJSON))
		out, err := cmd.CombinedOutput()
		if err != nil {
			log.Printf("[FCM] Push trigger error for ext %s: %v, out: %s", toExt, err, string(out))
		} else {
			log.Printf("[FCM] Push sent for ext %s: %s", toExt, string(out))
		}
	}()
}
