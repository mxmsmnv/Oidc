# Changelog

## 1.1.1

- Added OIDC `nonce` support and validation.
- Added RS256 `id_token` verification through provider JWKS, including `iss`, `aud`, expiry, and `nonce` checks.
- Added safe fallback to UserInfo when `id_token` claims cannot be verified and a UserInfo endpoint is configured.
- Added explicit provider error handling for OAuth callback errors.
- Improved HTTP error reporting for token exchange and hardened token request headers.
- Hardened HTML/JS escaping in rendered login buttons and the module configuration UI.
- Updated built-in provider documentation to include Yandex.
- Documented Oidc as the lightweight standalone OAuth/OIDC counterpart to Access.

## 1.1.0

- Initial public module release with built-in OAuth/OIDC providers, custom discovery-based providers, auto-registration, silent mode, and hookable identity/login/registration flow.
