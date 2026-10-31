# Corporate Single Sign-On: OpenID Connect (OIDC) & SAML 2.0 (Plugins)

> **Plugins (`oidc-auth` & `saml-auth`)**: Built-in official plugins for enterprise identity integration. Enable them on demand under **Integrations → Plugins** (`/plugins`). See [Plugin system & developer guide](plugin-development.md).

AiPBX includes native enterprise Single Sign-On (SSO) support through two official plugins:
1. **OpenID Connect (`oidc-auth`)**: Standard OAuth 2.0 / OIDC Core 1.0 authentication profile.
2. **SAML 2.0 (`saml-auth`)**: Enterprise SAML 2.0 Web Browser SSO profile (Service Provider - SP role) with cryptographic XML signature verification.

Both plugins seamlessly integrate with the web login portal, support mobile client authentication via app deep-links (`aipbx://auth`), and offer **Just-In-Time (JIT) user auto-provisioning** with automatic role assignment.

---

## Supported Identity Providers (IdP)

Out-of-the-box compatibility has been verified with major enterprise identity and access management (IAM) platforms:
- **Microsoft Entra ID** (formerly Azure Active Directory — Enterprise Applications & App Registrations)
- **Okta** (OIDC Web Apps & SAML 2.0 Applications)
- **Keycloak** (Open Source Identity and Access Management)
- **Authentik** & **Authelia**
- **Active Directory Federation Services (ADFS)** (Windows Server)
- **Google Workspace** (Cloud Identity & Google SAML Apps)
- **PingFederate** & **OneLogin**

---

## 1. OpenID Connect (OIDC) Plugin (`oidc-auth`)

The OIDC plugin connects AiPBX to any OpenID Provider (OP) implementing discovery and token exchange.

### Key Features
* **Dynamic OpenID Discovery:** Automatically resolves authorization, token, userinfo, and jwks endpoints directly from the provider's discovery URL (e.g. `https://login.microsoftonline.com/{tenant-id}/v2.0/.well-known/openid-configuration`). Discovery metadata is cached in persistent KV storage (`sys_plugin_kv`).
* **Admin Discovery Tester:** The plugin detail page (`/plugins/detail?slug=oidc-auth`) includes an interactive validation tool to test discovery endpoints and display available token claims in real time.
* **Security & Token Exchange:** Enforces cryptographically random `state` and `nonce` parameters against Cross-Site Request Forgery (CSRF) and replay attacks. Client secrets are encrypted at rest using AiPBX's `SecretBox`.

### Configuration
| Setting Key | Description | Example / Default |
|---|---|---|
| `issuer_url` | OpenID Provider base URL or discovery endpoint | `https://auth.company.com/realms/corp` |
| `client_id` | OAuth 2.0 Client ID assigned by your provider | `aipbx-client` |
| `client_secret` | OAuth 2.0 Client Secret (encrypted at rest) | `••••••••` |
| `redirect_uri` | Callback URL registered in your identity provider | `https://pbx.company.com/auth/oidc/callback` |
| `scopes` | OAuth scopes to request | `openid profile email` |
| `default_role` | Fallback role for newly provisioned users | `user` (`admin`, `cc_manager`, `cc_agent`) |
| `role_claim_path` | Token claim path used to resolve roles | `roles` or `groups` |
| `allowed_domains` | Comma-separated email domain allowlist | `company.com, subsidiaries.com` |

---

## 2. SAML 2.0 Web Browser SSO Plugin (`saml-auth`)

The SAML plugin operates as an enterprise **Service Provider (SP)**, receiving and validating SAML 2.0 Assertions sent via HTTP-POST binding.

### Key Features
* **SP Metadata Endpoint:** AiPBX publishes standard SAML EntityDescriptor XML at `https://<server>/auth/saml/metadata`.
* **Assertion Consumer Service (ACS):** Inbound assertion reception at `https://<server>/auth/saml/acs`.
* **One-Click IdP Metadata XML Importer:** Administrators can paste or upload the federation metadata XML exported from Microsoft Entra ID, Okta, Keycloak, or ADFS. The parser extracts Entity ID, SingleSignOnService URL, and X.509 public signing certificates automatically.
* **Cryptographic XML-DSig Verification:** Validates SHA-256 and SHA-1 digital signatures using the IdP's public certificate, canonicalizing enveloped XML elements (`C14N`).
* **Replay & Temporal Integrity:** Validates assertion conditions, audience restrictions, destination endpoints, and `NotBefore` / `NotOnOrAfter` timestamps with a 120-second clock skew tolerance.

### Configuration
| Setting Key | Description | Example / Default |
|---|---|---|
| `sp_entity_id` | Unique Service Provider Entity ID (defaults to server URL) | `https://pbx.company.com` |
| `idp_entity_id` | Issuer / Entity ID of the Identity Provider | `https://sts.windows.net/{tenant-id}/` |
| `idp_sso_url` | IdP SingleSignOnService HTTP-POST endpoint | `https://login.microsoftonline.com/.../saml2` |
| `idp_x509_cert` | X.509 Public Certificate of the IdP (PEM format) | `-----BEGIN CERTIFICATE-----...` |
| `nameid_format` | SAML NameID Policy Format | `emailAddress` (`persistent`, `transient`) |
| `default_role` | Default system role assigned to new users | `user` |
| `role_attribute` | SAML attribute name mapping to AiPBX roles | `http://schemas.microsoft.com/ws/2008/06/identity/claims/role` |
| `allowed_domains` | Email domain restrictions | `company.com` |

---

## Just-In-Time (JIT) Auto-Provisioning & Role Mapping

When a user signs in through corporate SSO for the first time:
1. **Identity Extraction:** Username and email are extracted from identity claims (`preferred_username`, `email`, or SAML `NameID`).
2. **Domain Verification:** If `allowed_domains` is configured, only matching corporate email domains are accepted.
3. **Collision Avoidance:** If a matching username or email already exists in `sys_users`, the account is securely linked; otherwise a new user row is created with a strong random password hash.
4. **Role Assignment:** If a role claim is present in the token or assertion, it is mapped to system roles (`admin`, `cc_manager`, `cc_agent`, `user`). If no mapping matches, it falls back to the configured `default_role`.

---

## Mobile Client Deep-Linking

The Android and iOS apps support corporate SSO out of the box. Clicking the SSO login button on a mobile client opens the organization's authentication portal in a secure web tab. Upon successful authentication, AiPBX issues a single-use authorization code and redirects back to the mobile app via deep-linking:
```
aipbx://auth?success=1&code=<auth_token>
```
The mobile application exchanges the code for a local session token, enabling corporate login without storing passwords on the mobile device.

---

## Security & Best Practices
* **Keep Certificates Current:** IdP signing certificates typically expire every 1–3 years. When updating certificates in Microsoft Entra ID or Okta, update the certificate in AiPBX before the old certificate expires to prevent sign-in interruptions.
* **Restricting Access by Group:** For strict access control, assign the AiPBX enterprise application only to designated groups within your corporate IdP directory.
* **Audit Logging:** Every successful and failed SSO attempt is recorded in the security audit log (`sys_audit_log`) with client IP address, User-Agent, and detailed diagnostic error messages.
