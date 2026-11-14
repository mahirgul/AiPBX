package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"

	"github.com/go-ldap/ldap/v3"
)

func testEntries() []ldapEntry {
	return []ldapEntry{
		newLdapEntry("ext-1001", "Ayşe Yılmaz", "", "", "", "1001", "", "", "ayse@example.com"),
		newLdapEntry("ext-1002", "Mehmet Demir", "", "", "", "1002", "", "", ""),
		newLdapEntry("c-1", "", "Kemal", "Kaya", "Acme Ltd", "+90 212 555 12 34", "0532 111 22 33", "", ""),
	}
}

func matchNames(t *testing.T, filter string) []string {
	t.Helper()
	f, err := ldap.CompileFilter(filter)
	if err != nil {
		t.Fatalf("%s: %v", filter, err)
	}
	var out []string
	for _, e := range testEntries() {
		if ldapMatch(f, e) {
			out = append(out, e.Attrs["cn"][0])
		}
	}
	return out
}

func TestLdapFiltersPhonesSend(t *testing.T) {
	cases := map[string]int{
		"(objectClass=*)": 3,
		"(cn=ayşe*)":      1,
		"(|(cn=*demir*)(telephoneNumber=*demir*))": 1,
		"(&(cn=*a*)(!(o=acme ltd)))":               1,
		"(telephoneNumber=1001)":                   1,
		"(telephoneNumber=02125551234)":            1, // same number, other spelling
		"(mobile=+905321112233)":                   1,
		"(|(telephoneNumber=*555*)(mobile=*555*))": 1,
		"(mail=AYSE@example.com)":                  1,
		"(givenName=*)":                            1,
		"(cn=nobody)":                              0,
	}
	for filter, want := range cases {
		if got := matchNames(t, filter); len(got) != want {
			t.Errorf("%s: want %d, got %v", filter, want, got)
		}
	}
}

func TestContactWithoutDisplayNameIsNamedFromItsParts(t *testing.T) {
	e := testEntries()[2]
	if e.Attrs["cn"][0] != "Kemal Kaya" || e.Attrs["sn"][0] != "Kaya" || e.Attrs["o"][0] != "Acme Ltd" {
		t.Fatalf("got %v", e.Attrs)
	}
}

func TestSameNumberComparesTheLastTenDigits(t *testing.T) {
	for _, c := range []struct {
		a, b string
		want bool
	}{
		{"+90 532 111 22 33", "05321112233", true},
		{"1001", "1001", true},
		{"1001", "11001", false},
		{"", "", false},
	} {
		if sameNumber(c.a, c.b) != c.want {
			t.Errorf("%q vs %q: want %v", c.a, c.b, c.want)
		}
	}
}

func TestOnlyThePhonebookUserOrAnonymousWhenAllowed(t *testing.T) {
	s := ldapSettings{Enabled: true, Password: "s3cret"}
	for _, c := range []struct {
		dn, pw string
		want   bool
	}{
		{"cn=phonebook,dc=aipbx", "s3cret", true},
		{"CN=Phonebook, DC=aipbx", "s3cret", true},
		{"phonebook", "s3cret", true},
		{"cn=phonebook,dc=aipbx", "wrong", false},
		{"cn=admin,dc=aipbx", "s3cret", false},
		{"", "", false},
	} {
		if bindAllowed(s, c.dn, c.pw) != c.want {
			t.Errorf("%q/%q: want %v", c.dn, c.pw, c.want)
		}
	}
	if bindAllowed(ldapSettings{Password: ""}, "phonebook", "") {
		t.Error("an empty password must never bind")
	}
	if !bindAllowed(ldapSettings{Anonymous: true}, "", "") {
		t.Error("anonymous bind refused while allowed")
	}
}

func freePort(t *testing.T) string {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer l.Close()
	return strconv.Itoa(l.Addr().(*net.TCPAddr).Port)
}

func writeTestCert(t *testing.T, dir string) *x509.CertPool {
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "pbx.test"},
		DNSNames: []string{"pbx.test"}, IPAddresses: []net.IP{net.ParseIP("127.0.0.1")},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	kb, _ := x509.MarshalECPrivateKey(key)
	_ = os.WriteFile(filepath.Join(dir, "ldap.crt"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o600)
	_ = os.WriteFile(filepath.Join(dir, "ldap.key"), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}), 0o600)
	cert, _ := x509.ParseCertificate(der)
	pool := x509.NewCertPool()
	pool.AddCert(cert)
	return pool
}

func dialUntil(t *testing.T, dial func() (*ldap.Conn, error)) *ldap.Conn {
	t.Helper()
	for i := 0; i < 50; i++ {
		if c, err := dial(); err == nil {
			return c
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatal("LDAP server did not come up")
	return nil
}

func TestLdapServerAnswersAPhoneOverPlainStartTLSAndLDAPS(t *testing.T) {
	dir := t.TempDir()
	pool := writeTestCert(t, dir)
	plain, secure := freePort(t), freePort(t)
	t.Setenv("CREDENTIALS_DIRECTORY", dir)
	t.Setenv("LDAP_PORT", plain)
	t.Setenv("LDAPS_PORT", secure)

	l := &LdapDirectory{load: func() ([]ldapEntry, error) { return testEntries(), nil }, bound: map[string]bool{},
		settings: ldapSettings{Enabled: true, Password: "s3cret"}}
	l.start()
	defer l.stop()
	trust := &tls.Config{RootCAs: pool, ServerName: "pbx.test"} // the test's own certificate

	search := func(c *ldap.Conn, filter string) (*ldap.SearchResult, error) {
		return c.Search(ldap.NewSearchRequest(ldapBaseDN, ldap.ScopeSingleLevel, ldap.NeverDerefAliases, 0, 0, false,
			filter, []string{"cn", "telephoneNumber"}, nil))
	}

	c := dialUntil(t, func() (*ldap.Conn, error) { return ldap.DialURL("ldap://127.0.0.1:" + plain) })
	if _, err := search(c, "(objectClass=*)"); !ldap.IsErrorWithCode(err, ldap.LDAPResultInsufficientAccessRights) {
		t.Fatalf("search before bind: want refusal, got %v", err)
	}
	if err := c.Bind(ldapBindDN, "wrong"); err == nil {
		t.Fatal("wrong password accepted")
	}
	if err := c.Bind(ldapBindDN, "s3cret"); err != nil {
		t.Fatalf("bind: %v", err)
	}
	res, err := search(c, "(|(cn=*kaya*)(telephoneNumber=*kaya*))")
	if err != nil || len(res.Entries) != 1 || res.Entries[0].GetAttributeValue("telephoneNumber") != "+90 212 555 12 34" {
		t.Fatalf("search: %v %+v", err, res)
	}
	if res.Entries[0].GetAttributeValue("mobile") != "" {
		t.Fatal("attribute sent that was not asked for")
	}
	if err := c.Add(ldap.NewAddRequest("uid=x,"+ldapBaseDN, nil)); err == nil {
		t.Fatal("the phonebook is read-only, add was accepted")
	}
	c.Close()

	c = dialUntil(t, func() (*ldap.Conn, error) { return ldap.DialURL("ldap://127.0.0.1:" + plain) })
	if err := c.StartTLS(trust); err != nil {
		t.Fatalf("StartTLS: %v", err)
	}
	if err := c.Bind("phonebook", "s3cret"); err != nil {
		t.Fatalf("bind over StartTLS: %v", err)
	}
	if res, err := search(c, "(telephoneNumber=1002)"); err != nil || len(res.Entries) != 1 {
		t.Fatalf("search over StartTLS: %v", err)
	}
	c.Close()

	c = dialUntil(t, func() (*ldap.Conn, error) {
		return ldap.DialURL("ldaps://127.0.0.1:"+secure, ldap.DialWithTLSConfig(trust))
	})
	if err := c.Bind(ldapBindDN, "s3cret"); err != nil {
		t.Fatalf("bind over LDAPS: %v", err)
	}
	if res, err := search(c, "(objectClass=*)"); err != nil || len(res.Entries) != 3 {
		t.Fatalf("search over LDAPS: %v", err)
	}
	if _, err := c.Search(ldap.NewSearchRequest("dc=other", ldap.ScopeWholeSubtree, ldap.NeverDerefAliases, 0, 0, false,
		"(objectClass=*)", nil, nil)); !ldap.IsErrorWithCode(err, ldap.LDAPResultNoSuchObject) {
		t.Fatalf("other base: want no such object, got %v", err)
	}
	c.Close()
}

func TestFailedRebindClearsPriorAuth(t *testing.T) {
	plain := freePort(t)
	t.Setenv("CREDENTIALS_DIRECTORY", "")
	t.Setenv("LDAP_PORT", plain)

	l := &LdapDirectory{load: func() ([]ldapEntry, error) { return testEntries(), nil }, bound: map[string]bool{},
		settings: ldapSettings{Enabled: true, Password: "s3cret"}}
	l.start()
	defer l.stop()

	c := dialUntil(t, func() (*ldap.Conn, error) { return ldap.DialURL("ldap://127.0.0.1:" + plain) })
	defer c.Close()

	if err := c.Bind(ldapBindDN, "s3cret"); err != nil {
		t.Fatalf("initial bind: %v", err)
	}
	search := func() (*ldap.SearchResult, error) {
		return c.Search(ldap.NewSearchRequest(ldapBaseDN, ldap.ScopeSingleLevel, ldap.NeverDerefAliases, 0, 0, false,
			"(objectClass=*)", []string{"cn"}, nil))
	}
	if res, err := search(); err != nil || len(res.Entries) != 3 {
		t.Fatalf("search after valid bind: %v", err)
	}
	if err := c.Bind(ldapBindDN, "wrong"); err == nil {
		t.Fatal("wrong password accepted on re-bind")
	}
	if _, err := search(); !ldap.IsErrorWithCode(err, ldap.LDAPResultInsufficientAccessRights) {
		t.Fatalf("search after failed re-bind: want insufficient access rights, got %v", err)
	}
}

func TestLdapEntryEscapesDNSpecialChars(t *testing.T) {
	e := newLdapEntry(`ext-10,1+a\b`, "Test User", "", "", "", `10,1+a\b`, "", "", "")
	want := `uid=ext-10\,1\+a\\b,` + ldapBaseDN
	if e.DN != want {
		t.Fatalf("DN = %q, want %q", e.DN, want)
	}
}

func TestLdapServerAnswersRootDSE(t *testing.T) {
	dir := t.TempDir()
	_ = writeTestCert(t, dir)
	plain, secure := freePort(t), freePort(t)
	t.Setenv("CREDENTIALS_DIRECTORY", dir)
	t.Setenv("LDAP_PORT", plain)
	t.Setenv("LDAPS_PORT", secure)

	l := &LdapDirectory{load: func() ([]ldapEntry, error) { return testEntries(), nil }, bound: map[string]bool{},
		settings: ldapSettings{Enabled: true, Password: "s3cret"}}
	l.start()
	defer l.stop()

	c := dialUntil(t, func() (*ldap.Conn, error) { return ldap.DialURL("ldap://127.0.0.1:" + plain) })
	defer c.Close()

	res, err := c.Search(ldap.NewSearchRequest("", ldap.ScopeBaseObject, ldap.NeverDerefAliases, 0, 0, false,
		"(objectClass=*)", []string{"namingContexts", "supportedLDAPVersion", "supportedExtension"}, nil))
	if err != nil {
		t.Fatalf("RootDSE search: %v", err)
	}
	if len(res.Entries) != 1 || res.Entries[0].DN != "" {
		t.Fatalf("RootDSE entries = %+v, want 1 entry with empty DN", res.Entries)
	}
	e := res.Entries[0]
	if e.GetAttributeValue("namingContexts") != ldapBaseDN {
		t.Fatalf("namingContexts = %q, want %q", e.GetAttributeValue("namingContexts"), ldapBaseDN)
	}
	if e.GetAttributeValue("supportedLDAPVersion") != "3" {
		t.Fatalf("supportedLDAPVersion = %q, want 3", e.GetAttributeValue("supportedLDAPVersion"))
	}
	if e.GetAttributeValue("supportedExtension") != "1.3.6.1.4.1.1466.20037" {
		t.Fatalf("supportedExtension = %q, want StartTLS OID", e.GetAttributeValue("supportedExtension"))
	}
	// Directory entries still require bind.
	if _, err := c.Search(ldap.NewSearchRequest(ldapBaseDN, ldap.ScopeSingleLevel, ldap.NeverDerefAliases, 0, 0, false,
		"(objectClass=*)", nil, nil)); !ldap.IsErrorWithCode(err, ldap.LDAPResultInsufficientAccessRights) {
		t.Fatalf("directory search before bind: want refusal, got %v", err)
	}
}

func TestLdapStopDoesNotWaitForAnIdlePhone(t *testing.T) {
	p := freePort(t)
	t.Setenv("CREDENTIALS_DIRECTORY", "")
	t.Setenv("LDAP_PORT", p)
	l := &LdapDirectory{load: func() ([]ldapEntry, error) { return testEntries(), nil }, bound: map[string]bool{},
		settings: ldapSettings{Enabled: true, Password: "s3cret"}}
	l.start()
	c := dialUntil(t, func() (*ldap.Conn, error) { return ldap.DialURL("ldap://127.0.0.1:" + p) })
	defer c.Close()
	if err := c.Bind(ldapBindDN, "s3cret"); err != nil {
		t.Fatal(err)
	}
	t0 := time.Now()
	l.stop()
	if d := time.Since(t0); d > 5*time.Second {
		t.Fatalf("stop waited %v for an idle connection", d)
	}
	l.settings.Password = "s3cret" // still set; the sign-in must be gone anyway
	if _, err := c.Search(ldap.NewSearchRequest(ldapBaseDN, ldap.ScopeSingleLevel, ldap.NeverDerefAliases, 0, 0, false,
		"(objectClass=*)", nil, nil)); err == nil {
		t.Fatal("a connection left over after stop still reads the phonebook")
	}
}
