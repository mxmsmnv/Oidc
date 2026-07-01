# Oidc

Oidc adds lightweight OAuth 2.0 and OpenID Connect login to ProcessWire: social sign-in, company SSO, callback handling, auto-registration, silent mode and hookable identity flow.

![Oidc](assets/Oidc.png)

It is made for sites that need external authentication without a full account-management suite: client portals, intranets, member areas, editorial tools, SaaS dashboards and private ProcessWire projects.

**Repository:** [github.com/mxmsmnv/Oidc](https://github.com/mxmsmnv/Oidc)  
**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## What Oidc Does

- Adds OAuth 2.0 / OpenID Connect login buttons to ProcessWire templates.
- Supports Google, GitHub, LinkedIn, Microsoft, Yandex and Yahoo out of the box.
- Supports custom OIDC providers through discovery URLs.
- Works with Okta, Auth0, Keycloak, authentik, Azure AD, Dex and similar providers.
- Handles OAuth callbacks automatically on frontend pages.
- Verifies OIDC `id_token` claims with nonce, issuer, audience, expiry and RS256/JWKS checks when available.
- Falls back to UserInfo for providers that expose identity through API endpoints.
- Auto-registers new ProcessWire users or lets hooks take over registration.
- Preserves return URLs through the login flow.
- Includes silent mode for SSO-only sites and intranets.
- Provides hook points for identity resolution, login, registration and provider definitions.

## Provider Setup

Oidc includes a provider table in the module settings. Fill the Client ID and Client Secret for any built-in provider to enable it.

For standard OpenID Connect providers, configure one custom provider with:

- provider ID;
- display name;
- client ID;
- client secret;
- discovery URL.

The module reads the provider's `/.well-known/openid-configuration` document and uses the discovered authorization, token, UserInfo and JWKS endpoints.

## Public Integration

Oidc is an autoload module. Any frontend page where `?oidc=<provider>` appears in the URL is handled automatically before template output.

Render login buttons anywhere in a template:

```php
$oidc = $modules->get('Oidc');
echo $oidc->renderButtons();
```

The page that renders the buttons is also the callback page. Register that URL in each provider's OAuth application settings.

## Installation

1. Copy the `Oidc` folder into `/site/modules/`.
2. In ProcessWire Admin, refresh modules.
3. Install `Oidc`.
4. Open the module settings and configure the callback URL.
5. Add one or more providers and render the login buttons in your template.

## Documentation

See [DOCUMENTATION.md](DOCUMENTATION.md) for setup, configuration, provider notes, hooks and template integration examples.

See [CHANGELOG.md](CHANGELOG.md) for the release notes.

## Author

Maxim Semenov  
[smnv.org](https://smnv.org)  
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

MIT
