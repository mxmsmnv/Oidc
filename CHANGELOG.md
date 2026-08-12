# Changelog

## 1.2.1

- Added an optional failure redirect that converts expected callback failures into non-sensitive `oidc_error` reason codes instead of exposing framework error pages.
- Added redacted callback-failure logging with provider, public reason code, and exception class only; tokens, provider responses, email addresses, and exception messages are not logged.
- Kept the legacy exception behavior when no failure redirect is configured.

## 1.2.0

- Made OIDC authentication fail closed unless a signed `id_token` contains valid `iss`, `aud`, `exp`, `sub`, and `nonce` claims; added `azp` validation for multiple audiences.
- Replaced provider-wide state slots with one-time, state-scoped transactions with a 10-minute TTL, replay protection, cleanup, and parallel-tab support.
- Validated `state` before handling provider error callbacks and changed authorization/login redirects to temporary HTTP statuses.
- Added private runtime provider credentials through `$config->oidcProviders` and an `oidcRequireRuntimeCredentials` mode that prevents database credentials from enabling providers.
- Required explicit issuer allow-lists for discovery, same-origin discovered endpoints by default, public HTTPS endpoint resolution, and short connection timeouts.
- Tightened RS256 JWK selection to require `kid`, signing use, matching algorithm and verify operations; refreshes JWKS once when an unknown key ID indicates rotation.
- Fixed verified-email handling for generic OIDC providers and preserved validated ID-token claims when UserInfo supplements them.
- Hardened local return URL validation against encoded separators, backslashes, control characters, and external redirects.
- Added standalone security regression tests for token tampering, required claims, audience/`azp`, nonce, expiry, state replay, parallel flows, error callbacks, JWK policy, and return URLs.
- Kept the module settings form renderable in CLI and non-HTTPS maintenance contexts while retaining strict HTTPS enforcement in the real authorization flow.

## 1.1.3

- Fixed the OIDC settings grid so each row's column widths add up correctly and no longer shift fields out of alignment.

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
