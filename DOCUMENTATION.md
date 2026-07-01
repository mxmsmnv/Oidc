# Oidc Documentation

Lightweight OAuth 2.0 / OpenID Connect login module for ProcessWire. Standalone, zero dependencies.

![Oidc](assets/Oidc.png)

Built-in providers: **Google, GitHub, LinkedIn, Microsoft, Yandex, Yahoo**
Generic OIDC: **Okta, Auth0, Keycloak, authentik, Azure AD**, and any provider with a discovery document.

**Repository:** [github.com/mxmsmnv/Oidc](https://github.com/mxmsmnv/Oidc)

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

---

## Positioning

Oidc is the lightweight, standalone SSO-login module for projects that only need OAuth 2.0 / OpenID Connect authentication.

It is intentionally a lite counterpart to **Access**: it keeps the provider flow, login buttons, callback handling, auto-registration, silent mode, and hooks, but leaves out the full account-management layer such as password forms, profile editing, two-factor authentication, activity history, CAPTCHA, and email workflows.

Choose **Oidc** when the site only needs social login or company SSO. Choose **Access** when the site needs a complete front-end authentication suite.

---

## Installation

1. Copy the `Oidc` folder into `/site/modules/`.
2. Install in **Modules → Refresh → Install**.
3. Configure in **Modules → Oidc**.

---

## Quick start

The module is `autoload`. Any page where `?oidc=<provider>` appears in the URL is intercepted automatically before template output — no manual `handle()` call needed.

Render login buttons anywhere in your template:

```php
$oidc = $modules->get('Oidc');
echo $oidc->renderButtons();
```

The page that renders the buttons is also the callback page. Set its full URL in **Settings → Callback URL** and register the same URL in each provider's OAuth application settings.

---

## Integration examples

### Minimal login page (`/login/`)

Create a page with template `login`, add to `site/templates/login.php`:

```php
<?php namespace ProcessWire;

if($user->isLoggedin()) $session->redirect('/');

$oidc = $modules->get('Oidc');
?>
<!DOCTYPE html>
<html>
<head><title>Login</title></head>
<body>
  <h1>Sign in</h1>
  <?= $oidc->renderButtons() ?>
</body>
</html>
```

In module settings set **Callback URL** to `https://yoursite.com/login/` and **Redirect after login** to `/account/`.

---

### Protecting a page — redirect to login with return URL

In `site/templates/account.php`:

```php
<?php namespace ProcessWire;

if(!$user->isLoggedin()) {
    $session->redirect('/login/?return=' . urlencode($page->url));
}
```

Or centrally in `site/ready.php` for a whole template group:

```php
if(!$user->isLoggedin() && in_array($page->template->name, ['account', 'orders', 'profile'])) {
    $session->redirect('/login/?return=' . urlencode($page->url));
}
```

The `?return=` parameter is passed automatically through the OAuth flow and honoured after login.

---

### Silent mode — SSO intranet

For apps where every user must authenticate via a single company IdP (Okta, Keycloak, etc.):

1. Enable **Silent mode** in Settings
2. Set **Default provider** to your custom OIDC provider
3. Configure the custom OIDC provider with your issuer Discovery URL

Every unauthenticated request on any frontend page is immediately redirected to the IdP — no login page or buttons needed.

---

### Showing the logged-in user and a logout link

```php
<?php namespace ProcessWire;

if($user->isLoggedin()): ?>
  <span>Signed in as <?= $sanitizer->entities($user->name) ?></span>
  <a href="<?= $config->urls->root ?>?logout=1">Sign out</a>
<?php else: ?>
  <?= $modules->get('Oidc')->renderButtons() ?>
<?php endif; ?>
```

Handle logout in `site/ready.php`:

```php
if($input->get('logout')) {
    $session->logout();
    $session->redirect('/');
}
```

---

### Custom redirect after login based on role

In `site/ready.php`:

```php
$wire->addHookAfter('Oidc::loginUser', function(HookEvent $e) {
    $user = $e->arguments('user');
    if($user->hasRole('editor')) {
        $e->wire('session')->redirect('/admin/');
    } else {
        $e->wire('session')->redirect('/account/');
    }
});
```

---

### Setting a session flag and logging the provider

In `site/ready.php`:

```php
$wire->addHookAfter('Oidc::loginUser', function(HookEvent $e) {
    $session  = $e->wire('session');
    $user     = $e->arguments('user');
    $provider = $e->arguments('provider');

    $session->set('oidc_login_time', time());
    $e->wire('log')->save('oidc', "Login via {$provider}: {$user->email}");
});
```

---

### Blocking registration from unknown domains

Disable **Auto-register new users** in Settings, then:

```php
$wire->addHookBefore('Oidc::registerUser', function(HookEvent $e) {
    $email = $e->arguments('email');

    if(!str_ends_with($email, '@yourcompany.com')) {
        throw new Wire404Exception('Access denied.');
    }
    // Otherwise fall through — module creates the account normally
});
```

Or redirect to a waiting-list page and skip account creation entirely:

```php
$wire->addHookBefore('Oidc::registerUser', function(HookEvent $e) {
    $email = $e->arguments('email');
    $e->wire('session')->redirect('/waitlist/?email=' . urlencode($email));
    $e->replace = true;
});
```

---

### Adding a custom provider (Discord)

In `site/ready.php`:

```php
$wire->addHookAfter('Oidc::getProviderDefs', function(HookEvent $e) {
    $defs = $e->return;

    $defs['discord'] = [
        'label'          => 'Discord',
        'svg'            => '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057c.002.022.015.043.032.054a19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03z"/></svg>',
        'bg_color'       => '#5865F2',
        'text_color'     => '#ffffff',
        'auth_url'       => 'https://discord.com/oauth2/authorize',
        'token_url'      => 'https://discord.com/api/oauth2/token',
        'userinfo_url'   => 'https://discord.com/api/users/@me',
        'scope'          => 'identify email',
        'token_type'     => 'json',
        'email_field'    => 'email',
        'name_field'     => 'username',
        'verified_field' => 'verified',
        'extra_emails'   => false,
        'oidc'           => false,
    ];

    $e->return = $defs;
});
```

After adding the hook, **Discord** appears in the Providers table in module settings. Enter Client ID and Secret there.

---

### Custom OIDC provider via Discovery URL

No hook needed for standard OIDC providers. In **Modules → Oidc → Custom OIDC provider**:

| Field | Value |
|---|---|
| ID (slug) | `okta` |
| Display name | `Okta` |
| Client ID | your app client ID |
| Client Secret | your app client secret |
| Discovery URL | `https://your-org.okta.com` |

The module appends `/.well-known/openid-configuration` and auto-fetches all endpoints. The same approach works for:

| IdP | Discovery URL |
|---|---|
| Auth0 | `https://your-tenant.auth0.com` |
| Keycloak | `https://keycloak.yourcompany.com/realms/your-realm` |
| authentik | `https://authentik.yourcompany.com/application/o/your-app` |
| Azure AD (single-tenant) | `https://login.microsoftonline.com/{tenant-id}/v2.0` |
| Dex | `https://dex.yourcompany.com` |

---

## Module settings

| Setting | Description |
|---|---|
| **Callback URL** | Fixed URL registered with providers. Leave blank to use the current page URL at runtime (not recommended for production). |
| **Redirect after login** | Relative path to redirect after successful login, e.g. `/account/`. Falls back to `?return=` from URL, then `?oidc_login=1`. |
| **Silent mode** | When on, unauthenticated users are immediately redirected to the default provider with no buttons shown. |
| **Default provider** | Provider used for silent mode redirect. Also displayed first in `renderButtons()`. Falls back to the first configured provider. |
| **Button style** | `Full` — icon + provider name. `Icon` — icon only. |
| **Auto-register new users** | When off, unknown emails throw a 404. Use `registerUser` hook for custom logic. |
| **Role for new users** | Role name assigned on auto-registration. Leave blank for none. |

---

## Providers

### Built-in

Fill **Client ID** and **Client Secret** in the Providers table to enable a provider. The link icon shows the exact callback URL for that provider.

| Provider | Notes |
|---|---|
| Google | OIDC — uses verified `id_token` claims when available |
| GitHub | Fetches primary verified email from `/user/emails` endpoint |
| LinkedIn | OIDC — `openid profile email` scope |
| Microsoft | Falls back to `userPrincipalName` when `mail` is null |
| Yandex | OAuth 2.0 |
| Yahoo | OIDC |

### Custom OIDC provider

One custom provider configured directly in module settings. Suitable for Okta, Auth0, Keycloak, authentik, Azure AD (single-tenant), and any IdP that publishes a discovery document at `/.well-known/openid-configuration`.

---

## Redirect flow

1. User clicks a button → `?oidc=google` → module redirects to provider authorization URL with CSRF `state`, OIDC `nonce`, and optional PKCE
2. Provider redirects back to callback URL with `?code&state`
3. Module verifies state, handles provider errors, exchanges code for token, resolves identity
4. `resolveIdentity` hook fires — inspect or modify the identity
5. Module finds an existing user by email, or auto-registers a new one
6. `loginUser` or `registerUser` hook fires → redirect to destination

For OIDC providers with `jwks_uri`, `id_token` claims are accepted only after RS256 signature verification and standard claim checks (`iss`, `aud`, expiry, and `nonce`). If token claims cannot be verified and a UserInfo endpoint is available, the module falls back to UserInfo.

---

## Hooks

All hooks are registered on the `Oidc` class. Add them in `site/ready.php` or `site/init.php`.

### `Oidc::resolveIdentity`

Fires after identity is resolved from the provider, before the login/register decision. Hook `after` to inspect, modify, or block.

```php
$wire->addHookAfter('Oidc::resolveIdentity', function(HookEvent $e) {
    $identity = $e->return;
    // ['email' => '...', 'name' => '...', 'provider' => 'google', 'raw' => [...]]

    $identity['name'] = trim($identity['name']);

    $e->return = $identity;
});
```

### `Oidc::loginUser`

Fires after `forceLogin()` for an existing user. Default behaviour: redirect to `loginRedirect` setting or `?oidc_login=1`. Hook `after` to override.

```php
$wire->addHookAfter('Oidc::loginUser', function(HookEvent $e) {
    $user     = $e->arguments('user');
    $provider = $e->arguments('provider');

    $e->wire('session')->redirect('/dashboard/');
});
```

### `Oidc::registerUser`

Fires when no existing user matches the email and `autoRegister` is enabled. Hook `before` with `$e->replace = true` to take over completely, or hook `after` to modify the result.

```php
$wire->addHookBefore('Oidc::registerUser', function(HookEvent $e) {
    $email    = $e->arguments('email');
    $name     = $e->arguments('name');
    $provider = $e->arguments('provider');

    // ... your logic
    $e->replace = true;
});
```

### `Oidc::getProviderDefs`

Fires when the module loads provider definitions. Hook `after` to add new providers or modify existing ones.

```php
$wire->addHookAfter('Oidc::getProviderDefs', function(HookEvent $e) {
    $defs = $e->return;
    $defs['myprovider'] = [ /* ... */ ];
    $e->return = $defs;
});
```

---

## Session data

After a successful login the module sets:

| Key | Value |
|---|---|
| `$session->oidc_provider` | Provider ID string (`google`, `github`, etc.) |

---

## Provider definition keys

| Key | Type | Description |
|---|---|---|
| `label` | string | Display name |
| `svg` | string | SVG icon HTML (18×18) |
| `bg_color` | string\|null | Button background colour |
| `text_color` | string | Button text colour |
| `auth_url` | string | Authorization endpoint |
| `token_url` | string | Token endpoint |
| `userinfo_url` | string | UserInfo endpoint. If empty, `id_token` claims require a valid `jwks_uri`. |
| `jwks_uri` | string | JWKS endpoint used to verify OIDC `id_token` signatures |
| `issuer` | string | Expected OIDC issuer (`iss`) |
| `scope` | string | Space-separated OAuth scopes |
| `token_type` | `json`\|`form` | Token endpoint response format |
| `email_field` | string | Field name for email in userinfo response |
| `email_fallback` | string | Alternative field when `email_field` is null (Microsoft) |
| `name_field` | string\|array | Field(s) for display name — array values are joined with a space |
| `verified_field` | string\|null | Field that must be truthy; `null` = skip check |
| `extra_emails` | bool | Fetch emails from separate endpoint (GitHub) |
| `emails_url` | string | URL for extra emails endpoint |
| `pkce` | bool | Use PKCE code challenge (required for Twitter/X) |
| `graph_api` | bool | Pass token as `?access_token=` not Bearer (Facebook) |
| `oidc` | bool | Try verified `id_token` claims before fetching userinfo |

---

## Requirements

- ProcessWire 3.0.200+
- PHP 8.1+
- `curl` extension
- `openssl` extension

---

## License

MIT — [github.com/mxmsmnv/Oidc](https://github.com/mxmsmnv/Oidc)

© 2025 [Maxim Semenov](https://smnv.org)
