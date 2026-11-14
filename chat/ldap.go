package main

// Read-only LDAP phonebook for desk phones (Yealink, Grandstream, Fanvil, Snom...).
// The entries are read from MySQL on every search: the active extensions (fax
// lines left out) and the shared phonebook (phonebook_contacts). Nothing is
// copied, so a phone always sees the directory as it is now.
//
// The portal turns it on and sets the password (sys_settings ldap_server_*);
// the service reads them every 15 s and starts or stops the listeners.

import (
	"context"
	"crypto/subtle"
	"crypto/tls"
	"database/sql"
	"errors"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode"

	ber "github.com/go-asn1-ber/asn1-ber"
	"github.com/go-ldap/ldap/v3"
	"github.com/go-sql-driver/mysql"
	"github.com/jimlambrt/gldap"
)

const (
	ldapBaseDN     = "ou=phonebook,dc=aipbx"
	ldapRootDN     = "dc=aipbx"
	ldapBindDN     = "cn=phonebook,dc=aipbx"
	ldapMaxResults = 500
)

// ldapEntry is one person or company contact as a phone sees it.
type ldapEntry struct {
	DN    string
	Attrs map[string][]string // lower-case attribute name -> values
}

// ldapAttrNames gives the spelling phones expect for each attribute.
var ldapAttrNames = map[string]string{
	"objectclass": "objectClass", "cn": "cn", "displayname": "displayName", "givenname": "givenName",
	"sn": "sn", "telephonenumber": "telephoneNumber", "mobile": "mobile", "homephone": "homePhone",
	"mail": "mail", "o": "o", "uid": "uid",
	"namingcontexts": "namingContexts", "supportedldapversion": "supportedLDAPVersion",
	"supportedextension": "supportedExtension",
}

var ldapPhoneAttrs = map[string]bool{"telephonenumber": true, "mobile": true, "homephone": true}

func newLdapEntry(uid, display, first, last, company, work, mobile, other, mail string) ldapEntry {
	display = strings.TrimSpace(display)
	if display == "" {
		display = strings.TrimSpace(first + " " + last)
	}
	if display == "" {
		display = company
	}
	if display == "" {
		display = work
	}
	if last == "" {
		last = display
	}
	a := map[string][]string{
		"objectclass": {"top", "person", "organizationalPerson", "inetOrgPerson"},
		"uid":         {uid},
		"cn":          {display},
		"displayname": {display},
		"sn":          {last},
	}
	for k, v := range map[string]string{"givenname": first, "o": company, "telephonenumber": work, "mobile": mobile, "homephone": other, "mail": mail} {
		if v = strings.TrimSpace(v); v != "" {
			a[k] = []string{v}
		}
	}
	return ldapEntry{DN: "uid=" + ldap.EscapeDN(uid) + "," + ldapBaseDN, Attrs: a}
}

// loadLdapEntries reads the directory: extensions first, then the contacts.
func loadLdapEntries(d *sql.DB) ([]ldapEntry, error) {
	var out []ldapEntry
	rows, err := d.Query(`SELECT extension, full_name, COALESCE(email, '') FROM sys_users
		WHERE extension IS NOT NULL AND extension != '' AND is_active = 1
		  AND (extension_type IS NULL OR extension_type != 'fax') ORDER BY extension`)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var ext, name, mail string
		if rows.Scan(&ext, &name, &mail) == nil {
			out = append(out, newLdapEntry("ext-"+ext, name, "", "", "", ext, "", "", mail))
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return nil, err
	}
	rows.Close()

	rows, err = d.Query(`SELECT id, display_name, first_name, last_name, company, phone_work, phone_mobile, phone_other, email
		FROM phonebook_contacts ORDER BY display_name LIMIT 20000`)
	if err != nil {
		var me *mysql.MySQLError
		if !errors.As(err, &me) || me.Number != 1146 {
			log.Printf("[LDAP] phonebook_contacts query failed: %v", err)
		}
		return out, nil // table not there yet (older schema): extensions only
	}
	defer rows.Close()
	for rows.Next() {
		var id int64
		var f [8]sql.NullString
		if rows.Scan(&id, &f[0], &f[1], &f[2], &f[3], &f[4], &f[5], &f[6], &f[7]) == nil {
			out = append(out, newLdapEntry("c-"+strconv.FormatInt(id, 10), f[0].String, f[1].String, f[2].String,
				f[3].String, f[4].String, f[5].String, f[6].String, f[7].String))
		}
	}
	if err := rows.Err(); err != nil {
		log.Printf("[LDAP] phonebook_contacts rows error: %v", err)
	}
	return out, nil
}

// ---- filters ----

func digitsOnly(s string) string {
	var b strings.Builder
	for _, r := range s {
		if r >= '0' && r <= '9' {
			b.WriteRune(r)
		}
	}
	return b.String()
}

// sameNumber: "+90 532 123 45 67" and "05321234567" are the same number.
func sameNumber(a, b string) bool {
	a, b = digitsOnly(a), digitsOnly(b)
	if a == "" || b == "" {
		return false
	}
	if a == b {
		return true
	}
	if len(a) >= 10 && len(b) >= 10 {
		return a[len(a)-10:] == b[len(b)-10:]
	}
	return false
}

func foldValue(attr, v string) string {
	if ldapPhoneAttrs[attr] {
		return digitsOnly(v)
	}
	return strings.ToLower(strings.TrimSpace(v))
}

func packetString(p *ber.Packet) string {
	if s, ok := p.Value.(string); ok {
		return s
	}
	if p.Data != nil {
		return p.Data.String()
	}
	return ""
}

// ldapMatch evaluates a compiled filter (go-ldap CompileFilter) against an entry.
func ldapMatch(f *ber.Packet, e ldapEntry) bool {
	switch f.Tag {
	case ldap.FilterAnd:
		for _, c := range f.Children {
			if !ldapMatch(c, e) {
				return false
			}
		}
		return true
	case ldap.FilterOr:
		for _, c := range f.Children {
			if ldapMatch(c, e) {
				return true
			}
		}
		return false
	case ldap.FilterNot:
		return len(f.Children) == 1 && !ldapMatch(f.Children[0], e)
	case ldap.FilterPresent:
		return len(e.Attrs[strings.ToLower(packetString(f))]) > 0
	case ldap.FilterEqualityMatch, ldap.FilterApproxMatch, ldap.FilterGreaterOrEqual, ldap.FilterLessOrEqual:
		if len(f.Children) != 2 {
			return false
		}
		attr := strings.ToLower(packetString(f.Children[0]))
		want := packetString(f.Children[1])
		for _, v := range e.Attrs[attr] {
			switch {
			case f.Tag == ldap.FilterGreaterOrEqual && foldValue(attr, v) >= foldValue(attr, want),
				f.Tag == ldap.FilterLessOrEqual && foldValue(attr, v) <= foldValue(attr, want):
				return true
			case f.Tag == ldap.FilterEqualityMatch || f.Tag == ldap.FilterApproxMatch:
				if ldapPhoneAttrs[attr] && sameNumber(v, want) || foldValue(attr, v) == foldValue(attr, want) {
					return true
				}
			}
		}
		return false
	case ldap.FilterSubstrings:
		if len(f.Children) != 2 {
			return false
		}
		attr := strings.ToLower(packetString(f.Children[0]))
		if ldapPhoneAttrs[attr] && !hasDigits(f.Children[1].Children) {
			return false // a name typed into the search is no number
		}
		for _, v := range e.Attrs[attr] {
			if substringMatch(foldValue(attr, v), attr, f.Children[1].Children) {
				return true
			}
		}
		return false
	}
	return false // extensible match and anything unknown
}

func hasDigits(parts []*ber.Packet) bool {
	for _, p := range parts {
		if digitsOnly(packetString(p)) != "" {
			return true
		}
	}
	return false
}

func substringMatch(v, attr string, parts []*ber.Packet) bool {
	pos := 0
	for _, p := range parts {
		s := foldValue(attr, packetString(p))
		if s == "" {
			continue
		}
		switch p.Tag {
		case ldap.FilterSubstringsInitial:
			if !strings.HasPrefix(v, s) {
				return false
			}
			pos = len(s)
		case ldap.FilterSubstringsAny:
			i := strings.Index(v[pos:], s)
			if i < 0 {
				return false
			}
			pos += i + len(s)
		case ldap.FilterSubstringsFinal:
			if len(v)-len(s) < pos || !strings.HasSuffix(v, s) {
				return false
			}
		}
	}
	return true
}

// inScope: the entries are all one level below ldapBaseDN.
func inScope(base string, scope gldap.Scope) bool {
	b := strings.ToLower(strings.ReplaceAll(strings.TrimSpace(base), " ", ""))
	switch b {
	case ldapBaseDN:
		return scope != gldap.BaseObject
	case ldapRootDN, "":
		return scope == gldap.WholeSubtree
	}
	return false
}

// ---- server ----

type ldapSettings struct {
	Enabled   bool
	Password  string
	Anonymous bool
}

func loadLdapSettings(d *sql.DB, envKey string) ldapSettings {
	s := ldapSettings{}
	rows, err := d.Query(`SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN
		('ldap_server_enabled', 'ldap_server_password', 'ldap_server_anonymous')`)
	if err != nil {
		return s
	}
	defer rows.Close()
	for rows.Next() {
		var k, v string
		if rows.Scan(&k, &v) != nil {
			continue
		}
		switch k {
		case "ldap_server_enabled":
			s.Enabled = v == "1"
		case "ldap_server_anonymous":
			s.Anonymous = v == "1"
		case "ldap_server_password":
			s.Password = openSecret(v, settingsKey(envKey))
		}
	}
	return s
}

// bindAllowed: the phonebook user with the right password, or nobody at all
// when anonymous reading is on.
func bindAllowed(s ldapSettings, dn, password string) bool {
	user := strings.ToLower(strings.ReplaceAll(strings.TrimSpace(dn), " ", ""))
	if user == "" && password == "" {
		return s.Anonymous
	}
	if user != ldapBindDN && user != "phonebook" && user != "cn=phonebook" {
		return false
	}
	return s.Password != "" && subtle.ConstantTimeCompare([]byte(password), []byte(s.Password)) == 1
}

type LdapDirectory struct {
	load     func() ([]ldapEntry, error) // the directory as it is now
	envKey   string
	mu       sync.Mutex
	settings ldapSettings
	bound    map[string]bool // "<listener>:<connection id>" -> bound
	servers  []*gldap.Server
}

func (l *LdapDirectory) current() ldapSettings {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.settings
}

func (l *LdapDirectory) handlers(name string, tlsCfg *tls.Config) (*gldap.Mux, error) {
	key := func(r *gldap.Request) string { return name + ":" + strconv.Itoa(r.ConnectionID()) }
	mux, err := gldap.NewMux()
	if err != nil {
		return nil, err
	}
	_ = mux.Bind(func(w *gldap.ResponseWriter, r *gldap.Request) {
		res := r.NewBindResponse(gldap.WithResponseCode(gldap.ResultInvalidCredentials))
		if m, err := r.GetSimpleBindMessage(); err == nil {
			if bindAllowed(l.current(), m.UserName, string(m.Password)) {
				l.mu.Lock()
				l.bound[key(r)] = true
				l.mu.Unlock()
				res.SetResultCode(gldap.ResultSuccess)
			} else {
				l.mu.Lock()
				delete(l.bound, key(r))
				l.mu.Unlock()
				log.Printf("[LDAP] failed bind for %q on %s", m.UserName, key(r))
			}
		} else {
			l.mu.Lock()
			delete(l.bound, key(r))
			l.mu.Unlock()
			log.Printf("[LDAP] failed bind on %s: %v", key(r), err)
		}
		_ = w.Write(res)
	})
	_ = mux.Search(func(w *gldap.ResponseWriter, r *gldap.Request) {
		res := r.NewSearchDoneResponse(gldap.WithResponseCode(gldap.ResultSuccess))
		defer func() { _ = w.Write(res) }()
		m, err := r.GetSearchMessage()
		if err != nil {
			res.SetResultCode(gldap.ResultProtocolError)
			return
		}
		writeEntry := func(e ldapEntry) error {
			out := r.NewSearchResponseEntry(e.DN)
			for attr, vals := range e.Attrs {
				if wantAttr(m.Attributes, attr) {
					if m.TypesOnly {
						vals = nil
					}
					attrName := ldapAttrNames[attr]
					if attrName == "" {
						attrName = attr
					}
					out.AddAttribute(attrName, vals)
				}
			}
			return w.Write(out)
		}
		if strings.TrimSpace(m.BaseDN) == "" && m.Scope == gldap.BaseObject {
			filter, err := ldap.CompileFilter(m.Filter)
			if err != nil {
				res.SetResultCode(gldap.ResultProtocolError)
				return
			}
			rootAttrs := map[string][]string{
				"objectclass":          {"top"},
				"namingcontexts":       {ldapBaseDN},
				"supportedldapversion": {"3"},
			}
			if tlsCfg != nil {
				rootAttrs["supportedextension"] = []string{string(gldap.ExtendedOperationStartTLS)}
			}
			rootDSE := ldapEntry{DN: "", Attrs: rootAttrs}
			if ldapMatch(filter, rootDSE) {
				_ = writeEntry(rootDSE)
			}
			return
		}
		l.mu.Lock()
		ok := l.bound[key(r)] || l.settings.Anonymous
		l.mu.Unlock()
		if !ok {
			res.SetResultCode(gldap.ResultInsufficientAccessRights)
			return
		}
		if !inScope(m.BaseDN, m.Scope) {
			res.SetResultCode(gldap.ResultNoSuchObject)
			return
		}
		filter, err := ldap.CompileFilter(m.Filter)
		if err != nil {
			res.SetResultCode(gldap.ResultProtocolError)
			return
		}
		entries, err := l.load()
		if err != nil {
			res.SetResultCode(gldap.ResultUnavailable)
			return
		}
		limit := ldapMaxResults
		if m.SizeLimit > 0 && int(m.SizeLimit) < limit {
			limit = int(m.SizeLimit)
		}
		sent := 0
		for _, e := range entries {
			if !ldapMatch(filter, e) {
				continue
			}
			if sent >= limit {
				res.SetResultCode(gldap.ResultSizeLimitExceeded)
				return
			}
			if writeEntry(e) != nil {
				return
			}
			sent++
		}
	})
	if tlsCfg != nil {
		_ = mux.ExtendedOperation(func(w *gldap.ResponseWriter, r *gldap.Request) {
			res := r.NewExtendedResponse(gldap.WithResponseCode(gldap.ResultSuccess))
			res.SetResponseName(gldap.ExtendedOperationStartTLS)
			if w.Write(res) == nil {
				_ = r.StartTLS(tlsCfg)
			}
		}, gldap.ExtendedOperationStartTLS)
	}
	// Read-only: writes are refused with the answer their request expects.
	refuse := func(app int) gldap.HandlerFunc {
		return func(w *gldap.ResponseWriter, r *gldap.Request) {
			_ = w.Write(r.NewResponse(gldap.WithResponseCode(gldap.ResultUnwillingToPerform), gldap.WithApplicationCode(app)))
		}
	}
	_ = mux.Add(refuse(gldap.ApplicationAddResponse))
	_ = mux.Modify(refuse(gldap.ApplicationModifyResponse))
	_ = mux.Delete(refuse(gldap.ApplicationDelResponse))
	_ = mux.DefaultRoute(func(w *gldap.ResponseWriter, r *gldap.Request) {
		_ = w.Write(r.NewResponse(gldap.WithResponseCode(gldap.ResultUnwillingToPerform)))
	})
	return mux, nil
}

func wantAttr(requested []string, attr string) bool {
	if len(requested) == 0 {
		return true
	}
	for _, a := range requested {
		if a == "*" || strings.EqualFold(a, attr) {
			return true
		}
	}
	return false
}

// ldapTLS: the portal certificate, handed over by systemd (LoadCredential),
// so the service user never reads the key file itself.
func ldapTLS() *tls.Config {
	dir := os.Getenv("CREDENTIALS_DIRECTORY")
	if dir == "" {
		return nil
	}
	cert, err := tls.LoadX509KeyPair(filepath.Join(dir, "ldap.crt"), filepath.Join(dir, "ldap.key"))
	if err != nil {
		return nil
	}
	return &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}
}

func envPort(name, def string) string {
	if v := strings.TrimSpace(os.Getenv(name)); v != "" && strings.IndexFunc(v, func(r rune) bool { return !unicode.IsDigit(r) }) < 0 {
		return v
	}
	return def
}

func (l *LdapDirectory) start() {
	tlsCfg := ldapTLS()
	listen := []struct {
		name, addr string
		tls        bool
	}{{"ldap", ":" + envPort("LDAP_PORT", "389"), false}}
	if tlsCfg != nil {
		listen = append(listen, struct {
			name, addr string
			tls        bool
		}{"ldaps", ":" + envPort("LDAPS_PORT", "636"), true})
	}
	for _, ln := range listen {
		srv, err := gldap.NewServer(gldap.WithReadTimeout(60*time.Second), gldap.WithWriteTimeout(30*time.Second),
			gldap.WithOnClose(func(name string) gldap.OnCloseHandler {
				return func(id int) {
					l.mu.Lock()
					delete(l.bound, name+":"+strconv.Itoa(id))
					l.mu.Unlock()
				}
			}(ln.name)))
		if err != nil {
			log.Printf("[LDAP] %s: %v", ln.name, err)
			continue
		}
		startTLS := tlsCfg // StartTLS only on the plain port
		if ln.tls {
			startTLS = nil
		}
		mux, err := l.handlers(ln.name, startTLS)
		if err != nil {
			continue
		}
		_ = srv.Router(mux)
		var opts []gldap.Option
		if ln.tls {
			opts = append(opts, gldap.WithTLSConfig(tlsCfg))
		}
		go func(name, addr string) {
			if err := srv.Run(addr, opts...); err != nil {
				log.Printf("[LDAP] %s on %s stopped: %v", name, addr, err)
			}
		}(ln.name, ln.addr)
		l.servers = append(l.servers, srv)
		log.Printf("[LDAP] phonebook served on %s (%s)", ln.addr, ln.name)
	}
}

// stop closes the listeners. gldap's Stop also waits for open connections,
// and an idle phone keeps one until the 60 s read timeout: wait 2 s at most.
// Sign-ins are forgotten first, so a connection left over can read nothing.
func (l *LdapDirectory) stop() {
	l.mu.Lock()
	l.bound = map[string]bool{}
	l.mu.Unlock()
	done := make(chan struct{})
	go func(servers []*gldap.Server) {
		for _, s := range servers {
			_ = s.Stop()
		}
		close(done)
	}(l.servers)
	select {
	case <-done:
	case <-time.After(2 * time.Second):
	}
	l.servers = nil
	log.Printf("[LDAP] phonebook stopped")
}

// RunLdapDirectory follows the portal's switch until ctx is cancelled.
func RunLdapDirectory(ctx context.Context, d *sql.DB, envKey string) {
	l := &LdapDirectory{load: func() ([]ldapEntry, error) { return loadLdapEntries(d) }, envKey: envKey, bound: map[string]bool{}}
	syncState := func() {
		s := loadLdapSettings(d, envKey)
		l.mu.Lock()
		l.settings = s
		l.mu.Unlock()
		switch {
		case s.Enabled && l.servers == nil:
			l.start()
		case !s.Enabled && l.servers != nil:
			l.stop()
		}
	}
	syncState()
	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			if l.servers != nil {
				l.stop()
			}
			return
		case <-ticker.C:
			syncState()
		}
	}
}
