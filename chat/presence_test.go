package main

import (
	"encoding/json"
	"testing"
)

func newTestClient(h *Hub, ext string, active bool) *Client {
	c := &Client{hub: h, user: &User{Extension: ext}, send: make(chan []byte, 16), active: active}
	h.mu.Lock()
	if h.clients[ext] == nil {
		h.clients[ext] = make(map[*Client]bool)
	}
	h.clients[ext][c] = true
	h.mu.Unlock()
	return c
}

func presenceEvents(c *Client) []map[string]interface{} {
	var out []map[string]interface{}
	for {
		select {
		case m := <-c.send:
			var v map[string]interface{}
			_ = json.Unmarshal(m, &v)
			if v["event"] == "presence" {
				out = append(out, v)
			}
		default:
			return out
		}
	}
}

func TestPassiveClientIsNotOnline(t *testing.T) {
	h := NewHub()
	watcher := newTestClient(h, "1000", true)
	bg := newTestClient(h, "2000", false)

	if h.IsOnline("2000") {
		t.Fatal("a passive (background) connection must not count as online")
	}

	h.SetActive(bg, true)
	ev := presenceEvents(watcher)
	if !h.IsOnline("2000") || len(ev) != 1 || ev[0]["is_online"] != true {
		t.Fatalf("becoming active should announce online once, got %v", ev)
	}

	h.SetActive(bg, false)
	ev = presenceEvents(watcher)
	if h.IsOnline("2000") || len(ev) != 1 || ev[0]["is_online"] != false || ev[0]["last_seen"] == nil {
		t.Fatalf("going to background should announce offline with last_seen, got %v", ev)
	}
}

func TestOnlineWhileAnyDeviceActive(t *testing.T) {
	h := NewHub()
	watcher := newTestClient(h, "1000", true)
	web := newTestClient(h, "2000", true)
	phone := newTestClient(h, "2000", false)

	h.SetActive(phone, true)
	h.SetActive(phone, false)
	if ev := presenceEvents(watcher); len(ev) != 0 {
		t.Fatalf("another active device keeps the user online, got %v", ev)
	}
	h.SetActive(web, false)
	if ev := presenceEvents(watcher); len(ev) != 1 || ev[0]["is_online"] != false {
		t.Fatalf("last active device going away should announce offline, got %v", ev)
	}
}

func TestMessageStatus(t *testing.T) {
	cases := []struct {
		id, read, delivered int64
		want                string
	}{
		{10, 0, 0, "sent"},
		{10, 0, 10, "delivered"},
		{10, 10, 10, "read"},
		{11, 10, 10, "sent"},
		{9, 5, 12, "delivered"},
	}
	for _, c := range cases {
		if got := MessageStatus(c.id, c.read, c.delivered); got != c.want {
			t.Errorf("MessageStatus(%d,%d,%d) = %s, want %s", c.id, c.read, c.delivered, got, c.want)
		}
	}
}
