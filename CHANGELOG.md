# Changelog

## 1.1.2

- Changed account matching to use stable provider identity links (`provider + issuer + subject`) instead of email-only matching.
- Blocked silent first-login linking to existing local accounts by default; existing users must login first to link a provider, or admins must explicitly enable legacy email fallback linking.
- Blocked OIDC login for superusers by default and added an optional role allow-list for OIDC login.
- Changed OIDC `id_token` handling to fail closed when a returned token cannot be verified.
- Added JWKS/issuer metadata for built-in OIDC providers that were missing it.
- Added explicit email-verification policy metadata for providers without a standard verified-email claim.
- Enabled S256 PKCE for built-in providers and generic OIDC providers.
- Set explicit TLS peer and host verification on cURL token, UserInfo, JWKS, and discovery requests.

## 1.1.1

- Added OIDC `nonce` support and validation.
- Added RS256 `id_token` verification through provider JWKS, including `iss`, `aud`, expiry, and `nonce` checks.
- Added safe fallback to UserInfo when no `id_token` is returned and a UserInfo endpoint is configured.
- Added explicit provider error handling for OAuth callback errors.
- Improved HTTP error reporting for token exchange and hardened token request headers.
- Hardened HTML/JS escaping in rendered login buttons and the module configuration UI.
- Updated built-in provider documentation to include Yandex.
- Documented Oidc as the lightweight standalone OAuth/OIDC counterpart to Access.

## 1.1.0

- Initial public module release with built-in OAuth/OIDC providers, custom discovery-based providers, auto-registration, silent mode, and hookable identity/login/registration flow.
