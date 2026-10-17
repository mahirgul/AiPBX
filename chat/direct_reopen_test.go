package main

import (
	"errors"
	"testing"
)

// Reopening a direct conversation checks the same membership as creating
// it: a user who was deactivated or converted to fax since stays
// unreachable instead of sliding back in through the old conversation.
func TestDirectReopenRejectsInactiveUser(t *testing.T) {
	a, b := testExtensions(t)

	conv, err := GetOrCreateDirectConversation(a, b, a)
	if err != nil {
		t.Fatalf("create direct conversation: %v", err)
	}
	defer removeTestConversation(conv.ID)

	var origActive bool
	var origRole string
	if err := db.QueryRow("SELECT is_active, role FROM sys_users WHERE extension = ?", b).Scan(&origActive, &origRole); err != nil {
		t.Fatalf("read user state: %v", err)
	}
	defer db.Exec("UPDATE sys_users SET is_active = ?, role = ? WHERE extension = ?", origActive, origRole, b)

	for _, tc := range []struct {
		name   string
		active bool
		role   string
	}{
		{"deactivated", false, origRole},
		{"fax-only", true, "fax_user"},
	} {
		if _, err := db.Exec("UPDATE sys_users SET is_active = ?, role = ? WHERE extension = ?", tc.active, tc.role, b); err != nil {
			t.Fatalf("%s: update user: %v", tc.name, err)
		}
		if _, err := GetOrCreateDirectConversation(a, b, a); !errors.Is(err, errNotChatUser) {
			t.Fatalf("%s: reopening with an ineligible user must fail with errNotChatUser, got %v", tc.name, err)
		}
	}
}
