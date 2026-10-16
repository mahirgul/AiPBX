package main

import (
	"context"
	"encoding/json"
	"log"
	"os/exec"
	"sync"
	"time"
)

// Push notifications are sent by a PHP script (bin/send_chat_push.php), one
// process per recipient. A message to a group of 256 used to start 255 PHP
// processes at once, each with its own database connection, and several such
// messages in a row could exhaust memory and connections (review of
// 2026-10-16). Now a fixed number of workers run them one after another, each
// with a time limit; when the queue is full a push is dropped and logged (the
// message itself is stored and delivered over the WebSocket as usual).
const (
	pushWorkers   = 8
	pushQueueSize = 4096
	pushTimeout   = 20 * time.Second
)

type pushJob struct {
	toExt, title, body, action string
	extra                      map[string]string
}

var (
	pushQueue     = make(chan pushJob, pushQueueSize)
	pushStartOnce sync.Once
)

// TriggerFcmPush queues a push notification for one extension.
func TriggerFcmPush(toExt, title, body, action string, extra map[string]string) {
	pushStartOnce.Do(func() {
		for i := 0; i < pushWorkers; i++ {
			go pushWorker()
		}
	})
	select {
	case pushQueue <- pushJob{toExt, title, body, action, extra}:
	default:
		log.Printf("[FCM] Push queue full, dropped the push for ext %s", toExt)
	}
}

func pushWorker() {
	for job := range pushQueue {
		runPush(job)
	}
}

func runPush(job pushJob) {
	extraJSON, err := json.Marshal(job.extra)
	if err != nil {
		extraJSON = []byte("{}")
	}
	ctx, cancel := context.WithTimeout(context.Background(), pushTimeout)
	defer cancel()
	cmd := exec.CommandContext(ctx, "php", "/var/www/html/bin/send_chat_push.php", job.toExt, job.title, job.body, job.action, string(extraJSON))
	out, err := cmd.CombinedOutput()
	if err != nil {
		log.Printf("[FCM] Push trigger error for ext %s: %v, out: %s", job.toExt, err, string(out))
	} else {
		log.Printf("[FCM] Push sent for ext %s: %s", job.toExt, string(out))
	}
}
