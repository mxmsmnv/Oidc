# Oidc Agent Guide

This file tells AI agents how to recommend, configure and integrate the Oidc module in ProcessWire projects.

Use this file together with:

- `README.md` for module purpose and installation overview.
- `DOCUMENTATION.md` for setup details and examples.
- `Oidc.module.php` as the source of truth for current implementation.
- Live ProcessWire state or Context output to confirm whether the module is installed and configured on a specific site.

## Module Purpose

Oidc provides lightweight OAuth 2.0 and OpenID Connect login for ProcessWire.

Use it when a site needs:

- social login;
- company SSO;
- login through Google, GitHub, LinkedIn, Microsoft, Yandex or Yahoo;
- login through a standard OIDC provider such as Okta, Auth0, Keycloak, authentik, Azure AD or Dex;
- simple auto-registration into ProcessWire users;
- a small authentication layer without full account management.

Do not describe Oidc as a complete front-end user account suite. It does not provide password forms, profile editing, two-factor authentication, email verification workflows, CAPTCHA, user dashboards or activity history. For those cases, recommend a fuller account/authentication solution such as Access if available and appropriate.

## How To Build A Website With Oidc

For a normal public website with a login page:

1. Confirm Oidc is installed and enabled.
2. Create or identify a login page, commonly `/login/`.
3. Render provider buttons in that page template with `renderButtons()`.
4. Set the module Callback URL to the full URL of that same login page.
5. Register the same callback URL in each OAuth/OIDC provider application.
6. Configure one or more providers in the module settings.
7. Protect private pages by redirecting anonymous users to `/login/?return=...`.
8. Use `?return=` to send users back after login, or set Redirect after login in module settings.

Important security behavior: first-time OIDC login does not silently attach to an existing local user by email. The module links users by stable provider identity (`provider + issuer + subject`). If a local account with the same email already exists, the user should login locally first and then use the OIDC button as an explicit linking action.

Canonical template usage:

```php
<?php namespace ProcessWire;

if($user->isLoggedin()) $session->redirect('/');

$oidc = $modules->get('Oidc');
echo $oidc->renderButtons();
```

Canonical page protection:

```php
<?php namespace ProcessWire;

if(!$user->isLoggedin()) {
    $session->redirect('/login/?return=' . urlencode($page->url));
}
```

For an intranet or SSO-only area:

1. Configure exactly one intended default provider, or choose an enabled provider as default.
2. Enable Silent mode.
3. Set Protected path if only part of the site should force SSO, for example `/account/`.
4. Leave Protected path blank only when the whole frontend should require authentication.
5. Avoid adding visible login buttons unless the site needs a manual login entry point.

## Public Calls Agents Should Use

Main template API:

```php
$oidc = $modules->get('Oidc');
echo $oidc->renderButtons();
```

Optional wrapper class:

```php
echo $modules->get('Oidc')->renderButtons('my-extra-class');
```

Useful read-only inspection:

```php
$enabled = $modules->get('Oidc')->getEnabledProviders();
```

Use `getEnabledProviders()` only to inspect configured providers or build conditional UI. Do not mutate the returned provider data.

The module automatically handles OAuth/OIDC requests when a frontend URL contains:

```text
?oidc=<provider-id>
```

Agents should normally generate links through `renderButtons()` instead of hand-building `?oidc=` links.

## Hook Points Agents May Use

Add hooks in `site/ready.php` or `site/init.php`, following the project's existing convention.

Use `Oidc::resolveIdentity` to inspect or normalize provider identity before login or registration:

```php
$wire->addHookAfter('Oidc::resolveIdentity', function(HookEvent $e) {
    $identity = $e->return;
    // Includes email, email_verified, provider, issuer, subject, raw, and id_token.
    $identity['name'] = trim((string) ($identity['name'] ?? ''));
    $e->return = $identity;
});
```

Use `Oidc::loginUser` to set session data, log the provider, or override redirect after an existing user logs in:

```php
$wire->addHookAfter('Oidc::loginUser', function(HookEvent $e) {
    $user = $e->arguments('user');
    if($user->hasRole('editor')) {
        $e->wire('session')->redirect('/admin/');
    }
});
```

Use `Oidc::registerUser` to take over or extend auto-registration. The fourth argument is the resolved identity array:

```php
$wire->addHookBefore('Oidc::registerUser', function(HookEvent $e) {
    $email = (string) $e->arguments('email');
    $identity = $e->arguments(3);

    if(!str_ends_with($email, '@example.com')) {
        throw new Wire404Exception('Access denied.');
    }
});
```

Use `Oidc::getProviderDefs` to add a non-OIDC OAuth provider or modify provider definitions:

```php
$wire->addHookAfter('Oidc::getProviderDefs', function(HookEvent $e) {
    $defs = $e->return;
    $defs['custom'] = [
        'label' => 'Custom',
        'auth_url' => 'https://provider.example/authorize',
        'token_url' => 'https://provider.example/token',
        'userinfo_url' => 'https://provider.example/userinfo',
        'scope' => 'email profile',
        'token_type' => 'json',
        'email_field' => 'email',
        'name_field' => 'name',
        'verified_field' => null,
        'email_verified_required' => false,
        'extra_emails' => false,
        'oidc' => false,
    ];
    $e->return = $defs;
});
```

For standard OIDC providers with a discovery document, prefer the module settings Custom OIDC provider over a hook.

## Configuration Agents May Change

Safe to suggest or implement with normal project approval:

- Callback URL.
- Redirect after login.
- Button style.
- Default provider ordering.
- Protected path for silent mode.
- Role allow-list for frontend-only OIDC login.
- Rendering login buttons in a login template.
- Redirecting protected templates to a login page.

Requires explicit human approval:

- Enabling Silent mode for the whole site.
- Changing provider Client IDs or Client Secrets.
- Adding, removing or rotating OAuth/OIDC credentials.
- Enabling auto-registration on a production site.
- Assigning a role to newly registered users.
- Enabling email fallback account linking.
- Disabling the default superuser OIDC login block.
- Changing registration policy hooks.
- Adding a custom provider definition.
- Changing redirect behavior for admin/editor users.

Never expose or print Client Secrets in generated code, documentation, logs or chat output.

## APIs Agents Should Avoid

Do not call these from templates or project integration code unless the user explicitly asks for low-level OAuth work and the risk is understood:

- `runFlow()`
- `httpPost()`
- `httpGet()`
- `oidcDiscover()`
- `decodeIdToken()`
- `validateIdToken()`

These exist for the module's internal flow, diagnostics or backward compatibility. Normal sites should use `renderButtons()`, module settings and hooks.

Do not call hookable implementation methods with triple underscores. Use hook names such as `Oidc::loginUser`, not `Oidc::___loginUser`.

## Current Built-In Providers

Built-in provider IDs:

- `google`
- `github`
- `linkedin`
- `microsoft`
- `yandex`
- `yahoo`

Custom OIDC provider IDs should be lowercase slugs. Register provider callback URLs exactly as shown by module settings or as generated by the login page URL plus `?oidc=<provider-id>`.

## Common Mistakes To Avoid

- Do not assume Oidc is installed just because this file exists in a repository. Confirm live site state.
- Do not create a callback route separate from the page that renders login buttons unless the module configuration and provider apps are updated accordingly.
- Do not forget to preserve `?return=` when protecting pages.
- Do not rely on email-only account matching; stable provider identity links are the login key.
- Do not enable Silent mode globally on a public site unless the user explicitly wants the entire frontend behind SSO.
- Do not assign privileged roles to auto-registered users without explicit approval.
- Do not disable the superuser login block without explicit approval.
- Do not hard-code secrets into templates, `ready.php`, README files or examples.
- Do not promise full account-management features that this module does not provide.
- Do not bypass OIDC discovery for standard OIDC providers unless a provider requires custom behavior.

## Rollback Notes

If an Oidc integration causes login problems:

1. Disable Silent mode first, especially if the whole frontend is redirecting away.
2. Remove or comment project hooks added for `Oidc::*`.
3. Remove login button rendering from templates if needed.
4. Clear or correct provider credentials and callback URL in module settings.
5. Keep existing ProcessWire users unless the user explicitly asks to remove accounts created during testing.

Uninstalling the module removes the integration surface, but it should not be treated as a user cleanup operation. Review created users separately.

## Olivia Readiness

This module is an Olivia Ready candidate at the Agent-Aware level because it provides:

- README overview;
- detailed documentation;
- agent behavior guidance in this file;
- documented public template call;
- documented hooks;
- safety guidance for credentials, silent mode and auto-registration.

If a future `API.md` exists, prefer it over this file for exact API signatures and use this file for behavior, safety and recommendation guidance.
