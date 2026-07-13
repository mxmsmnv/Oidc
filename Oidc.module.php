<?php namespace ProcessWire;

/**
 * OIDC — lightweight OAuth 2.0 / OpenID Connect login for ProcessWire
 *
 * Standalone, zero dependencies. Lite counterpart to Access for projects
 * that only need social login or company SSO.
 *
 * Built-in providers: Google, GitHub, LinkedIn, Microsoft, Yandex, Yahoo
 * Generic OIDC: Okta, Auth0, Keycloak, authentik, Azure AD, etc.
 *
 * --- Usage ---
 *
 * The module is autoload. On any page where ?oidc=<provider> appears
 * in the URL it automatically handles the OAuth callback before output.
 * No need to call handle() manually.
 *
 * Render buttons anywhere in your template:
 *
 *   $oidc = $modules->get('Oidc');
 *   echo $oidc->renderButtons();
 *
 * The callback page must be fixed — configure it in module settings.
 * Set the same URL in your OAuth provider application settings.
 *
 * --- Hooks ---
 *
 *   // Intercept/modify resolved identity before login decision
 *   $wire->addHookAfter('Oidc::resolveIdentity', function(HookEvent $e) {
 *       $identity = $e->return; // ['email', 'name', 'provider', 'raw']
 *       $e->return = $identity;
 *   });
 *
 *   // After successful login — add redirect, session flags, etc.
 *   $wire->addHookAfter('Oidc::loginUser', function(HookEvent $e) {
 *       $user     = $e->arguments('user');
 *       $provider = $e->arguments('provider');
 *       $e->wire('session')->redirect('/dashboard/');
 *   });
 *
 *   // Replace built-in registration entirely
 *   $wire->addHookBefore('Oidc::registerUser', function(HookEvent $e) {
 *       $email    = $e->arguments('email');
 *       $name     = $e->arguments('name');
 *       $provider = $e->arguments('provider');
 *       // ... create user, redirect
 *       $e->replace = true;
 *   });
 *
 *   // Add or modify provider definitions
 *   $wire->addHookAfter('Oidc::getProviderDefs', function(HookEvent $e) {
 *       $defs = $e->return;
 *       $defs['discord'] = ['label' => 'Discord', 'auth_url' => '...', ...];
 *       $e->return = $defs;
 *   });
 *
 * @author    Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @copyright 2025 Maxim Semenov
 * @license   MIT
 * @link      https://github.com/mxmsmnv/Oidc
 *
 * @property array  $providers      Saved provider credentials
 * @property string $buttonStyle    Button style: full|icon
 * @property string $callbackUrl    Fixed callback URL registered with providers
 * @property string $loginRedirect  URL to redirect after login (empty = ?oidc_login=1)
 * @property bool   $autoRegister   Auto-register unknown users
 * @property string $newUserRole    Role name assigned to auto-registered users
 * @property array  $identityLinks  Provider subject links keyed by provider/issuer/sub
 * @property bool   $allowEmailAccountLink  Allow first OIDC login to attach to an existing email match
 * @property bool   $blockSuperuserLogin    Block OIDC login for superusers
 * @property string $allowedLoginRoles      Optional comma-separated role allow-list
 */
class Oidc extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'Oidc',
			'summary'  => 'OAuth 2.0 / OpenID Connect: Google, GitHub, LinkedIn, Microsoft, Yandex, Yahoo, and any OIDC-compatible provider.',
			'version'  => 112,
			'icon'     => 'key',
			'author'   => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'autoload' => true,  // intercepts ?oidc= on any page automatically
			'singular' => true,
		];
	}

	// -----------------------------------------------------------------
	// Defaults
	// -----------------------------------------------------------------

	public function __construct() {
		$this->set('providers',        []);
		$this->set('buttonStyle',      'full');
		$this->set('callbackUrl',      '');
		$this->set('loginRedirect',    '');
		$this->set('autoRegister',     true);
		$this->set('newUserRole',      '');
		$this->set('silentMode',       false);
		$this->set('silentPath',       '');
		$this->set('defaultProvider',  '');
		$this->set('identityLinks',    []);
		$this->set('allowEmailAccountLink', false);
		$this->set('blockSuperuserLogin',   true);
		$this->set('allowedLoginRoles',     '');
		parent::__construct();
	}

	// -----------------------------------------------------------------
	// Init — intercept ?oidc= before any template output
	// -----------------------------------------------------------------

	public function init(): void {
		// Page::render fires after the page is resolved but before the template outputs anything.
		$this->addHookBefore('Page::render', $this, 'maybeHandleCallback');
	}

	protected function maybeHandleCallback(HookEvent $e): void {
		$page = $this->wire('page');

		// Never intercept if page is not resolved yet or is an admin page
		if(!$page || !$page->id) return;
		if($page->template && $page->template->name === 'admin') return;

		$input = $this->wire('input');

		// Handle explicit ?oidc= callback
		$provider = (string) $input->get('oidc');
		if($provider) {
			$this->runFlow(trim($provider));
			return;
		}

		// Silent mode: redirect unauthenticated users straight to the default provider
		if($this->silentMode && !$this->wire('user')->isLoggedin()) {
			// If a protected path is set, only apply silent mode on matching pages
			$silentPath = trim((string) $this->silentPath);
			if($silentPath) {
				$currentUrl = rtrim($page->url, '/') . '/';
				$silentPath = '/' . ltrim(rtrim($silentPath, '/'), '/') . '/';
				if(strpos($currentUrl, $silentPath) !== 0) return;
			}
			$default = (string) $this->defaultProvider;
			$enabled = $this->getEnabledProviders();
			if(!$enabled) return;
			$pid = ($default && isset($enabled[$default])) ? $default : array_key_first($enabled);
			// Remember where user wanted to go, so we can redirect back after login
			$this->wire('session')->setFor($this, 'oidc_return', $page->url);
			$this->runFlow($pid);
		}
	}

	// -----------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------

	/**
	 * Render login buttons for all configured providers.
	 *
	 * @param string $extraClass  Additional CSS class on the wrapper element
	 * @return string HTML
	 */
	public function renderButtons(string $extraClass = ''): string {
		$enabled = $this->getEnabledProviders();
		if(!$enabled) return '';

		// Move defaultProvider to front
		$default = (string) $this->defaultProvider;
		if($default && isset($enabled[$default])) {
			$enabled = [$default => $enabled[$default]] + $enabled;
		}

		$rawBase = $this->resolveCallbackUrl();
		// Strip any existing ?oidc= or &oidc= so we never duplicate it
		$base  = preg_replace('/([?&])oidc=[^&]*(&|$)/', '$1', rtrim($rawBase, '?&'));
		$base  = rtrim($base, '?&');
		$style = $this->buttonStyle === 'icon' ? 'icon' : 'full';
		$out   = '';

		foreach($enabled as $id => $cfg) {
			// Preserve ?return= if present on the current page
			$returnParam = $this->wire('input')->get('return');
			$url  = $base . (strpos($base, '?') !== false ? '&' : '?') . 'oidc=' . urlencode($id);
			if($returnParam) $url .= '&return=' . urlencode($returnParam);

			$classId = $this->wire('sanitizer')->name($id);
			$urlEsc  = $this->h($url);
			$label = $this->h($cfg['label']);
			$svg   = $cfg['svg'] ?? '';
			$bgColor = !empty($cfg['bg_color']) ? $this->safeCssColor((string) $cfg['bg_color']) : '';
			$textColor = !empty($cfg['text_color']) ? ($this->safeCssColor((string) $cfg['text_color']) ?: '#fff') : '#fff';
			$bg = $bgColor ? " style='background:{$this->h($bgColor)};color:{$this->h($textColor)}'" : '';

			if($style === 'icon') {
				$out .= "<a href='{$urlEsc}' class='OIDCBtn OIDCBtn--{$classId} OIDCBtn--icon' title='Continue with {$label}'{$bg}>{$svg}</a>";
			} else {
				$out .= "<a href='{$urlEsc}' class='OIDCBtn OIDCBtn--{$classId}'{$bg}>{$svg}<span>Continue with {$label}</span></a>";
			}
		}

		$wrapClass = $this->h(trim('OIDCButtons' . ($style === 'icon' ? ' OIDCButtons--icons' : '') . ' ' . $extraClass));
		return "<div class='{$wrapClass}'>{$out}</div>";
	}

	/**
	 * Return enabled providers — those with client_id + client_secret filled in.
	 * Merges built-in defs with saved credentials; runs OIDC discovery if configured.
	 *
	 * @return array [id => merged cfg]
	 */
	public function getEnabledProviders(): array {
		$defs  = $this->getProviderDefs();   // goes through hook system
		$saved = is_array($this->providers) ? $this->providers : [];
		$out   = [];

		foreach($saved as $row) {
			$id     = trim($row['id'] ?? '');
			$cid    = trim($row['client_id'] ?? '');
			$secret = trim($row['client_secret'] ?? '');
			if(!$id || !$cid || !$secret) continue;

			$def = $defs[$id] ?? $this->genericDef($row);

			// OIDC auto-discovery overrides endpoints
			$discoveryUrl = trim($row['discovery_url'] ?? '');
			if($discoveryUrl) {
				$disc = $this->oidcDiscover($discoveryUrl);
				if($disc) {
					$def['auth_url']     = $disc['authorization_endpoint'] ?? $def['auth_url'];
					$def['token_url']    = $disc['token_endpoint']         ?? $def['token_url'];
					$def['userinfo_url'] = $disc['userinfo_endpoint']      ?? ($def['userinfo_url'] ?? '');
					$def['jwks_uri']     = $disc['jwks_uri']               ?? '';
					$def['issuer']       = $disc['issuer']                 ?? '';
					$def['scope']        = $row['scope'] ?? $def['scope']  ?? 'openid email profile';
					$def['oidc']         = true;
				}
			}

			$out[$id] = array_merge($def, [
				'client_id'     => $cid,
				'client_secret' => $secret,
			]);
		}

		return $out;
	}

	// -----------------------------------------------------------------
	// Core OAuth 2.0 / OIDC flow
	// -----------------------------------------------------------------

	public function runFlow(string $rawProviderId): void {
		$sanitizer  = $this->wire('sanitizer');
		$providerId = strtolower($sanitizer->name($rawProviderId));
		$providers  = $this->getEnabledProviders();

		if(!isset($providers[$providerId])) {
			throw new Wire404Exception("OIDC: unknown or unconfigured provider '{$providerId}'");
		}

		$cfg         = $providers[$providerId];
		$input       = $this->wire('input');
		$session     = $this->wire('session');
		$stateKey    = 'oidc_state_' . $providerId;
		$nonceKey    = 'oidc_nonce_' . $providerId;
		$callbackUrl = $this->resolveCallbackUrl();
		// Strip any existing ?oidc= or &oidc= from callbackUrl before appending
		$callbackBase = preg_replace('/([?&])oidc=[^&]*(&|$)/', '$1', rtrim($callbackUrl, '?&'));
		$callbackBase = rtrim($callbackBase, '?&');
		$redirect     = $callbackBase
			. (strpos($callbackBase, '?') !== false ? '&' : '?')
			. 'oidc=' . $providerId;

		// ---- Step 1: redirect to provider ----
		if($input->get('error')) {
			$error = (string) $input->get('error');
			$description = (string) $input->get('error_description');
			throw new WireException('OIDC: provider returned error ' . $error . ($description ? " ({$description})" : ''));
		}

		if(!$input->get('code')) {
			$state = bin2hex(random_bytes(16));
			$session->setFor($this, $stateKey, $state);

			// Preserve ?return= so we can redirect back after login
			$returnUrl = (string) ($input->get('return') ?? '');
			if($returnUrl) $session->setFor($this, 'oidc_return', $returnUrl);

			$params = [
				'client_id'     => $cfg['client_id'],
				'redirect_uri'  => $redirect,
				'response_type' => 'code',
				'scope'         => $cfg['scope'],
				'state'         => $state,
			];

			if(!empty($cfg['oidc'])) {
				$nonce = bin2hex(random_bytes(16));
				$session->setFor($this, $nonceKey, $nonce);
				$params['nonce'] = $nonce;
			}

			if(!empty($cfg['pkce'])) {
				$verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
				$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
				$session->setFor($this, 'oidc_pkce_' . $providerId, $verifier);
				$params['code_challenge']        = $challenge;
				$params['code_challenge_method'] = 'S256';
			}

			$session->redirect($cfg['auth_url'] . '?' . http_build_query($params));
		}

		// ---- Step 2: verify state ----
		$returnedState = (string) $input->get('state');
		$storedState   = (string) $session->getFor($this, $stateKey);
		$session->removeFor($this, $stateKey);

		if(!$returnedState || !$storedState || !hash_equals($storedState, $returnedState)) {
			throw new WireException('OIDC: state mismatch — possible CSRF attempt');
		}

		// ---- Step 3: exchange code for token ----
		$tokenParams = [
			'code'          => (string) $input->get('code'),
			'client_id'     => $cfg['client_id'],
			'client_secret' => $cfg['client_secret'],
			'redirect_uri'  => $redirect,
			'grant_type'    => 'authorization_code',
		];

		if(!empty($cfg['pkce'])) {
			$pkceKey = 'oidc_pkce_' . $providerId;
			$tokenParams['code_verifier'] = (string) $session->getFor($this, $pkceKey);
			$session->removeFor($this, $pkceKey);
		}

		$tokenResp = $this->httpPost($cfg['token_url'], $tokenParams, $cfg['token_type'] ?? 'json');

		if(empty($tokenResp['access_token'])) {
			$detail = $this->wire('config')->debug ? (' — ' . json_encode($tokenResp)) : '';
			throw new WireException("OIDC: token exchange failed for {$cfg['label']}{$detail}");
		}

		$accessToken = $tokenResp['access_token'];

		// ---- Step 4: resolve user info ----
		$userInfo = [];

		// OIDC: validate id_token claims first (avoids an extra HTTP call)
		$tokenClaims = [];
		if(!empty($cfg['oidc'])) {
			$nonce = (string) $session->getFor($this, $nonceKey);
			$session->removeFor($this, $nonceKey);
			if(!empty($tokenResp['id_token'])) {
				if(empty($cfg['jwks_uri'])) {
					throw new WireException("OIDC: cannot verify id_token for {$cfg['label']} because jwks_uri is missing");
				}
				$tokenClaims = $this->validateIdToken($tokenResp['id_token'], $cfg, $nonce);
				if(!$tokenClaims) {
					throw new WireException("OIDC: id_token validation failed for {$cfg['label']}");
				}
				$userInfo = $tokenClaims;
			}
		}

		// Facebook: token as query param, not Bearer
		if(!empty($cfg['graph_api'])) {
			$sep      = strpos($cfg['userinfo_url'], '?') !== false ? '&' : '?';
			$userInfo = $this->httpGet($cfg['userinfo_url'] . $sep . 'access_token=' . urlencode($accessToken), '');
		} elseif(!empty($cfg['userinfo_url'])) {
			$emailField = $cfg['email_field'] ?? 'email';
			if(empty($userInfo[$emailField])) {
				$userInfo = $this->httpGet($cfg['userinfo_url'], $accessToken);
			}
		}

		// Twitter/X: unwrap nested 'data' key
		if(!empty($cfg['pkce']) && isset($userInfo['data']) && is_array($userInfo['data'])) {
			$userInfo = $userInfo['data'];
		}

		// Verified email check. Providers that cannot prove email verification must
		// opt out explicitly; email is never used as the default account link key.
		$emailVerified = false;
		$verifiedField = $cfg['verified_field'] ?? null;
		if($verifiedField) {
			$emailVerified = $this->truthy($userInfo[$verifiedField] ?? null);
		}
		if($verifiedField && !$emailVerified) {
			throw new WireException("OIDC: unverified email from {$cfg['label']}");
		}

		// ---- Resolve email ----
		$email = '';
		if(!empty($cfg['extra_emails']) && !empty($cfg['emails_url'])) {
			// GitHub: fetch primary verified email from separate endpoint
			foreach($this->httpGet($cfg['emails_url'], $accessToken) as $entry) {
				if($this->truthy($entry['primary'] ?? null) && $this->truthy($entry['verified'] ?? null)) {
					$email = $entry['email'];
					$emailVerified = true;
					break;
				}
			}
		} else {
			$emailField = $cfg['email_field'] ?? 'email';
			$email = $userInfo[$emailField] ?? '';
			// Microsoft: 'mail' can be null, fall back to userPrincipalName
			if(!$email && !empty($cfg['email_fallback'])) {
				$email = $userInfo[$cfg['email_fallback']] ?? '';
			}
		}

		$email = $this->wire('sanitizer')->email($email);
		if(!$email) {
			throw new WireException("OIDC: could not retrieve a valid email from {$cfg['label']}");
		}
		if(($cfg['email_verified_required'] ?? true) && !$emailVerified) {
			throw new WireException("OIDC: {$cfg['label']} did not provide a verified email claim");
		}

		// ---- Resolve display name ----
		$nameField = $cfg['name_field'] ?? 'name';
		if(is_array($nameField)) {
			$parts = [];
			foreach($nameField as $nf) {
				if(!empty($userInfo[$nf])) $parts[] = $userInfo[$nf];
			}
			$name = implode(' ', $parts);
		} else {
			$name = $userInfo[$nameField] ?? '';
		}

		$issuer = (string) ($tokenClaims['iss'] ?? $userInfo['iss'] ?? $cfg['issuer'] ?? $providerId);
		$subject = (string) ($tokenClaims['sub'] ?? $userInfo['sub'] ?? $userInfo['id'] ?? $userInfo['login'] ?? '');
		if($subject === '') {
			throw new WireException("OIDC: could not retrieve a stable subject identifier from {$cfg['label']}");
		}

		// ---- Step 5: hookable identity resolution ----
		$identity = $this->resolveIdentity([
			'email'          => $email,
			'email_verified' => $emailVerified,
			'name'           => (string) $name,
			'provider'       => $providerId,
			'issuer'         => $issuer,
			'subject'        => $subject,
			'raw'            => $userInfo,
			'id_token'       => $tokenClaims,
		]);

		if(empty($identity['email'])) {
			throw new WireException('OIDC: identity resolution returned empty email');
		}
		if(empty($identity['subject'])) {
			throw new WireException('OIDC: identity resolution returned empty subject');
		}

		// ---- Step 6: login or register ----
		$this->loginOrRegister($identity);
	}

	// -----------------------------------------------------------------
	// Hookable methods
	// -----------------------------------------------------------------

	/**
	 * Hookable. Receives resolved identity array, returns it (possibly modified).
	 * Hook after to inspect, log, or alter identity before login/register.
	 *
	 * @param array $identity  ['email', 'name', 'provider', 'issuer', 'subject', 'raw']
	 * @return array
	 */
	protected function ___resolveIdentity(array $identity): array {
		return $identity;
	}

	/**
	 * Hookable. Called after forceLogin() for an existing user. Does nothing
	 * by default — hook after to inspect the user or set session data. The
	 * login redirect (to ?return=, loginRedirect setting, or ?oidc_login=1)
	 * happens afterwards in loginOrRegister(), not in this method, so that
	 * `after` hooks registered on this method actually get a chance to run
	 * (redirecting here would exit the process first).
	 *
	 * @param User   $user
	 * @param string $provider
	 */
	protected function ___loginUser(User $user, string $provider): void {
	}

	/**
	 * Hookable. Called when no existing user matches the email.
	 * Only fires when autoRegister is true.
	 * Hook before with $e->replace = true to handle registration yourself. In
	 * that case you are responsible for logging the user in (and redirecting,
	 * if desired; loginOrRegister() only redirects when this method itself
	 * doesn't).
	 * Hook after to inspect or modify the created user via $e->return; the
	 * login redirect happens afterwards in loginOrRegister(), not in this
	 * method.
	 *
	 * @param string $email
	 * @param string $name
	 * @param string $provider
	 * @param array  $identity
	 * @return User The newly created user.
	 */
	protected function ___registerUser(string $email, string $name, string $provider, array $identity = []): User {
		$sanitizer = $this->wire('sanitizer');
		$users     = $this->wire('users');
		$session   = $this->wire('session');

		// Derive a clean, unique username from the email local-part
		$base = preg_replace('/[^a-z0-9_-]/', '',
			strtolower($sanitizer->pageName(strtok($email, '@'), Sanitizer::translate))
		) ?: 'user';

		$username = $base;
		$suffix   = 2;
		while($users->get("name={$username}, include=all")->id) {
			$username = $base . $suffix++;
		}

		$user = $users->add($username);
		$user->of(false);
		$user->email = $email;

		// Store display name in the 'title' field only if it exists on the user template
		if($name && $user->template->fieldgroup->hasField('title')) {
			$user->title = $sanitizer->text($name);
		}

		$roleName = (string) $this->newUserRole;
		if($roleName) {
			$role = $this->wire('roles')->get($roleName);
			if($role && $role->id) $user->addRole($role);
		}

		$users->save($user);
		$user->of(true);
		$this->assertUserAllowedForOidc($user, $provider);
		if($identity) {
			$key = $this->identityKey(
				(string) ($identity['provider'] ?? $provider),
				(string) ($identity['issuer'] ?? $provider),
				(string) ($identity['subject'] ?? '')
			);
			$this->saveIdentityLink($key, $user, $identity);
		}

		session_regenerate_id(true);
		$session->forceLogin($user);
		$session->set('oidc_provider', $provider);

		return $user;
	}

	/**
	 * Hookable. Returns the built-in provider definition array.
	 * Hook after to add custom providers or modify existing ones.
	 *
	 * @return array [id => def]
	 */
	protected function ___getProviderDefs(): array {
		return [
			'google' => [
				'label'          => 'Google',
				'svg'            => "<svg width='18' height='18' viewBox='0 0 18 18' xmlns='http://www.w3.org/2000/svg'><g fill='none' fill-rule='evenodd'><path d='M17.64 9.205c0-.639-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z' fill='#4285F4'/><path d='M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z' fill='#34A853'/><path d='M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z' fill='#FBBC05'/><path d='M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z' fill='#EA4335'/></g></svg>",
				'auth_url'       => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token_url'      => 'https://oauth2.googleapis.com/token',
				'userinfo_url'   => 'https://www.googleapis.com/oauth2/v3/userinfo',
				'jwks_uri'       => 'https://www.googleapis.com/oauth2/v3/certs',
				'issuer'         => 'https://accounts.google.com',
				'scope'          => 'openid email profile',
				'token_type'     => 'json',
				'email_field'    => 'email',
				'name_field'     => 'name',
				'verified_field' => 'email_verified',
				'extra_emails'   => false,
				'oidc'           => true,
				'pkce'           => true,
			],
			'github' => [
				'label'          => 'GitHub',
				'svg'            => "<svg width='18' height='18' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' fill='currentColor'><path d='M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z'/></svg>",
				'auth_url'       => 'https://github.com/login/oauth/authorize',
				'token_url'      => 'https://github.com/login/oauth/access_token',
				'userinfo_url'   => 'https://api.github.com/user',
				'scope'          => 'user:email',
				'token_type'     => 'json',
				'email_field'    => 'email',
				'name_field'     => ['name', 'login'],
				'verified_field' => null,
				'extra_emails'   => true,
				'emails_url'     => 'https://api.github.com/user/emails',
				'email_verified_required' => true,
				'pkce'           => true,
			],
			'linkedin' => [
				'label'          => 'LinkedIn',
				'svg'            => "<svg width='18' height='18' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' fill='currentColor'><path d='M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 0 1-2.063-2.065 2.064 2.064 0 1 1 2.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'/></svg>",
				'auth_url'       => 'https://www.linkedin.com/oauth/v2/authorization',
				'token_url'      => 'https://www.linkedin.com/oauth/v2/accessToken',
				'userinfo_url'   => 'https://api.linkedin.com/v2/userinfo',
				'jwks_uri'       => 'https://www.linkedin.com/oauth/openid/jwks',
				'issuer'         => 'https://www.linkedin.com/oauth',
				'scope'          => 'openid profile email',
				'token_type'     => 'json',
				'email_field'    => 'email',
				'name_field'     => ['given_name', 'family_name'],
				'verified_field' => 'email_verified',
				'extra_emails'   => false,
				'oidc'           => true,
				'pkce'           => true,
			],
			'microsoft' => [
				'label'          => 'Microsoft',
				'svg'            => "<svg width='18' height='18' viewBox='0 0 23 23' xmlns='http://www.w3.org/2000/svg'><path fill='#f25022' d='M1 1h10v10H1z'/><path fill='#00a4ef' d='M12 1h10v10H12z'/><path fill='#7fba00' d='M1 12h10v10H1z'/><path fill='#ffb900' d='M12 12h10v10H12z'/></svg>",
				'auth_url'       => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
				'token_url'      => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
				'userinfo_url'   => 'https://graph.microsoft.com/v1.0/me',
				'jwks_uri'       => 'https://login.microsoftonline.com/common/discovery/v2.0/keys',
				'issuer'         => 'https://login.microsoftonline.com/{tenantid}/v2.0',
				'scope'          => 'openid email profile User.Read',
				'token_type'     => 'json',
				'email_field'    => 'mail',
				'email_fallback' => 'userPrincipalName',
				'name_field'     => 'displayName',
				'verified_field' => null,
				'extra_emails'   => false,
				'oidc'           => true,
				'email_verified_required' => false,
				'pkce'           => true,
			],
			'yandex' => [
				'label'          => 'Yandex',
				'svg'            => "<svg width='18' height='18' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' fill='currentColor'><path d='M13.5 0h-3C5.8 0 2.5 3.5 2.5 8.5c0 3.8 2 6.5 5 7.8L2.5 24h4l4.8-7.3H13v7.3h3.5V0H13.5zm0 13.5h-1.8c-2.5 0-4.2-2-4.2-5s1.7-5.2 4.2-5.2h1.8v10.2z'/></svg>",
				'auth_url'       => 'https://oauth.yandex.ru/authorize',
				'token_url'      => 'https://oauth.yandex.ru/token',
				'userinfo_url'   => 'https://login.yandex.ru/info',
				'scope'          => 'login:email login:info login:avatar',
				'token_type'     => 'json',
				'email_field'    => 'default_email',
				'name_field'     => ['first_name', 'last_name'],
				'verified_field' => null,
				'extra_emails'   => false,
				'oidc'           => false,
				'email_verified_required' => false,
				'pkce'           => true,
			],
			'yahoo' => [
				'label'          => 'Yahoo',
				'svg'            => "<svg width='18' height='18' viewBox='0 0 640 640' xmlns='http://www.w3.org/2000/svg' fill='currentColor'><path d='M287.8 205.1L231.1 348.3L175.1 205.1L79 205.1L184.8 454.2L146.2 544L240.4 544L381.3 205.1L287.7 205.1zM393.2 340.9C361.1 340.9 335 367 335 399.1C335 431.2 361.1 457.3 393.2 457.3C425.3 457.3 451.4 431.2 451.4 399.1C451.4 367 425.3 340.9 393.2 340.9zM458.7 96L365.7 319.5L470.5 319.5L563.1 96L458.7 96z'/></svg>",
				'auth_url'       => 'https://api.login.yahoo.com/oauth2/request_auth',
				'token_url'      => 'https://api.login.yahoo.com/oauth2/get_token',
				'userinfo_url'   => 'https://api.login.yahoo.com/openid/v1/userinfo',
				'jwks_uri'       => 'https://api.login.yahoo.com/openid/v1/certs',
				'issuer'         => 'https://api.login.yahoo.com',
				'scope'          => 'openid email profile',
				'token_type'     => 'json',
				'email_field'    => 'email',
				'name_field'     => ['given_name', 'family_name'],
				'verified_field' => 'email_verified',
				'extra_emails'   => false,
				'oidc'           => true,
				'pkce'           => true,
			],
		];
	}

	// -----------------------------------------------------------------
	// Login / register dispatch
	// -----------------------------------------------------------------

	protected function loginOrRegister(array $identity): void {
		$sanitizer = $this->wire('sanitizer');
		$users     = $this->wire('users');
		$session   = $this->wire('session');
		$current   = $this->wire('user');

		$email    = (string) ($identity['email'] ?? '');
		$name     = (string) ($identity['name'] ?? '');
		$provider = (string) ($identity['provider'] ?? '');
		$issuer   = (string) ($identity['issuer'] ?? $provider);
		$subject  = (string) ($identity['subject'] ?? '');
		$key      = $this->identityKey($provider, $issuer, $subject);

		if($key === '') {
			throw new WireException('OIDC: cannot login without a stable provider subject');
		}

		$linkedUser = $this->getLinkedUser($key);
		if($linkedUser && $linkedUser->id) {
			$this->assertUserAllowedForOidc($linkedUser, $provider);
			session_regenerate_id(true);
			$session->forceLogin($linkedUser);
			$session->set('oidc_provider', $provider);
			$this->loginUser($linkedUser, $provider);  // goes through hook system
			$this->redirectAfterLogin('?oidc_login=1');
			return;
		}

		// Authenticated users may explicitly link their own account.
		if($current && $current->isLoggedin()) {
			$this->assertUserAllowedForOidc($current, $provider);
			if($current->email && strcasecmp((string) $current->email, $email) !== 0) {
				throw new WireException('OIDC: provider email does not match the logged-in account');
			}
			$this->saveIdentityLink($key, $current, $identity);
			$session->set('oidc_provider', $provider);
			$this->loginUser($current, $provider);  // goes through hook system
			$this->redirectAfterLogin('?oidc_login=1');
			return;
		}

		$existing = $users->get('include=all, email=' . $sanitizer->selectorValue($email));

		if($existing && $existing->id) {
			if(!$this->allowEmailAccountLink) {
				throw new WireException('OIDC: an account with this email already exists; login first to link this provider');
			}
			if(empty($identity['email_verified'])) {
				throw new WireException('OIDC: refusing to link an existing account by unverified email');
			}
			$this->assertUserAllowedForOidc($existing, $provider);
			$this->saveIdentityLink($key, $existing, $identity);
			session_regenerate_id(true);
			$session->forceLogin($existing);
			$session->set('oidc_provider', $provider);
			$this->loginUser($existing, $provider);  // goes through hook system
			$this->redirectAfterLogin('?oidc_login=1');
			return;
		}

		if(!$this->autoRegister) {
			throw new Wire404Exception('OIDC: no account found and auto-registration is disabled');
		}

		$this->registerUser($email, $name, $provider, $identity);  // goes through hook system
		$this->redirectAfterLogin('?oidc_registered=1');
	}

	/**
	 * Redirect after a successful login or registration. Called after
	 * loginUser()/registerUser() return, so `after` hooks registered on
	 * either of those get a chance to run before the process exits via
	 * Session::redirect().
	 *
	 * @param string $fallbackQuery Query string appended to the current page
	 *   URL when no ?return= or loginRedirect setting is configured.
	 */
	protected function redirectAfterLogin(string $fallbackQuery): void {
		$session = $this->wire('session');

		// ?return= takes precedence, then module setting, then fallback
		$returnUrl = (string) $session->getFor($this, 'oidc_return');
		$session->removeFor($this, 'oidc_return');

		$dest = $this->safeRedirectUrl($returnUrl)
			?: $this->safeRedirectUrl($this->loginRedirect)
			?: $this->wire('page')->url . $fallbackQuery;

		$session->redirect($dest);
	}

	// Note: loginUser(), registerUser(), getProviderDefs(), resolveIdentity() are all
	// hookable (defined as ___method). Call them WITHOUT underscores — Wire's __call()
	// dispatches through the hook system automatically.

	protected function identityKey(string $provider, string $issuer, string $subject): string {
		$provider = strtolower($this->wire('sanitizer')->name($provider));
		$issuer   = trim($issuer);
		$subject  = trim($subject);
		if($provider === '' || $issuer === '' || $subject === '') return '';
		return hash('sha256', $provider . "\n" . $issuer . "\n" . $subject);
	}

	protected function getLinkedUser(string $key): ?User {
		$links = is_array($this->identityLinks) ? $this->identityLinks : [];
		$userId = (int) ($links[$key]['user_id'] ?? 0);
		if(!$userId) return null;
		$user = $this->wire('users')->get($userId);
		return ($user && $user->id) ? $user : null;
	}

	protected function saveIdentityLink(string $key, User $user, array $identity): void {
		if($key === '' || !$user->id) return;
		$links = is_array($this->identityLinks) ? $this->identityLinks : [];
		$links[$key] = [
			'user_id'  => (int) $user->id,
			'provider' => (string) ($identity['provider'] ?? ''),
			'issuer'   => (string) ($identity['issuer'] ?? ''),
			'subject'  => (string) ($identity['subject'] ?? ''),
			'email'    => (string) ($identity['email'] ?? ''),
			'linked'   => time(),
		];
		$this->identityLinks = $links;
		$this->wire('modules')->saveConfig($this, ['identityLinks' => $links]);
	}

	protected function assertUserAllowedForOidc(User $user, string $provider): void {
		if($this->blockSuperuserLogin && $user->isSuperuser()) {
			throw new WireException("OIDC: superuser login is blocked for {$provider}");
		}

		$allowed = array_filter(array_map('trim', explode(',', (string) $this->allowedLoginRoles)));
		if(!$allowed) return;

		foreach($allowed as $roleName) {
			if($roleName !== '' && $user->hasRole($roleName)) return;
		}

		throw new WireException("OIDC: this account is not allowed to login via {$provider}");
	}

	// -----------------------------------------------------------------
	// OIDC discovery
	// -----------------------------------------------------------------

	/**
	 * Fetch and cache an OIDC discovery document (1 hour TTL).
	 * Appends /.well-known/openid-configuration if not already in URL.
	 *
	 * @param string $issuerUrl
	 * @return array|null Parsed document, or null on failure
	 */
	public function oidcDiscover(string $issuerUrl): ?array {
		$url = rtrim($issuerUrl, '/');
		if(strpos($url, '.well-known') === false) {
			$url .= '/.well-known/openid-configuration';
		}
		if(!preg_match('~^https://~i', $url)) return null;

		$cacheKey = 'oidc_disc_' . md5($url);
		$cached   = $this->wire('cache')->get($cacheKey);
		if(is_array($cached)) return $cached;

		$data = $this->httpGet($url, '');
		if(!is_array($data) || empty($data['authorization_endpoint'])) return null;

		$this->wire('cache')->save($cacheKey, $data, 3600);
		return $data;
	}

	/**
	 * Decode a JWT payload without signature verification.
	 * Kept public for backwards compatibility; login flow uses validateIdToken().
	 */
	public function decodeIdToken(string $jwt): array {
		return $this->decodeJwtPayload($jwt);
	}

	/**
	 * Validate an OIDC ID token before trusting its claims.
	 */
	public function validateIdToken(string $jwt, array $cfg, string $nonce = ''): array {
		if(empty($cfg['jwks_uri'])) return [];
		if($nonce === '') return [];

		$parts = explode('.', $jwt);
		if(count($parts) !== 3) return [];

		$header = $this->decodeJwtPart($parts[0]);
		$claims = $this->decodeJwtPart($parts[1]);
		if(!$header || !$claims) return [];

		$alg = $header['alg'] ?? '';
		if($alg !== 'RS256') return [];

		$key = $this->getJwksPublicKey((string) $cfg['jwks_uri'], (string) ($header['kid'] ?? ''));
		if(!$key) return [];

		$signed = $parts[0] . '.' . $parts[1];
		$signature = $this->base64UrlDecode($parts[2]);
		$verified = openssl_verify($signed, $signature, $key, OPENSSL_ALGO_SHA256);
		if($verified !== 1) return [];

		$now = time();
		$leeway = 60;
		if(!empty($claims['exp']) && (int) $claims['exp'] < ($now - $leeway)) return [];
		if(!empty($claims['nbf']) && (int) $claims['nbf'] > ($now + $leeway)) return [];
		if(!empty($claims['iat']) && (int) $claims['iat'] > ($now + $leeway)) return [];

		if(!empty($cfg['issuer']) && !$this->issuerMatches((string) $cfg['issuer'], (string) ($claims['iss'] ?? ''))) return [];

		$aud = $claims['aud'] ?? null;
		$clientId = (string) ($cfg['client_id'] ?? '');
		if(is_array($aud)) {
			if(!in_array($clientId, $aud, true)) return [];
		} elseif($aud !== $clientId) {
			return [];
		}

		if($nonce && (($claims['nonce'] ?? '') !== $nonce)) return [];

		return $claims;
	}

	protected function issuerMatches(string $expected, string $actual): bool {
		if($expected === '') return true;
		if($actual === '') return false;
		if($expected === $actual) return true;
		if(strpos($expected, '{tenantid}') !== false) {
			$pattern = '~^' . str_replace('\\{tenantid\\}', '[^/]+', preg_quote($expected, '~')) . '$~';
			return (bool) preg_match($pattern, $actual);
		}
		return false;
	}

	protected function decodeJwtPayload(string $jwt): array {
		$parts = explode('.', $jwt);
		if(count($parts) !== 3) return [];
		return $this->decodeJwtPart($parts[1]);
	}

	protected function decodeJwtPart(string $part): array {
		$json = $this->base64UrlDecode($part);
		return is_string($json) ? (json_decode($json, true) ?: []) : [];
	}

	protected function base64UrlDecode(string $value): string {
		return (string) base64_decode(strtr(str_pad($value, strlen($value) + (4 - strlen($value) % 4) % 4, '='), '-_', '+/'));
	}

	protected function getJwksPublicKey(string $jwksUri, string $kid): string {
		if(!preg_match('~^https://~i', $jwksUri)) return '';

		$cacheKey = 'oidc_jwks_' . md5($jwksUri);
		$jwks = $this->wire('cache')->get($cacheKey);
		if(!is_array($jwks)) {
			$jwks = $this->httpGet($jwksUri, '');
			if(empty($jwks['keys']) || !is_array($jwks['keys'])) return '';
			$this->wire('cache')->save($cacheKey, $jwks, 3600);
		}

		foreach($jwks['keys'] as $jwk) {
			if(!is_array($jwk)) continue;
			if($kid && (($jwk['kid'] ?? '') !== $kid)) continue;
			if(($jwk['kty'] ?? '') !== 'RSA') continue;

			if(!empty($jwk['x5c'][0])) {
				return "-----BEGIN CERTIFICATE-----\n"
					. chunk_split($jwk['x5c'][0], 64, "\n")
					. "-----END CERTIFICATE-----\n";
			}

			if(!empty($jwk['n']) && !empty($jwk['e'])) {
				return $this->rsaJwkToPem((string) $jwk['n'], (string) $jwk['e']);
			}
		}

		return '';
	}

	protected function rsaJwkToPem(string $modulus, string $exponent): string {
		$n = $this->base64UrlDecode($modulus);
		$e = $this->base64UrlDecode($exponent);
		$modulusDer = $this->asn1Integer($n);
		$exponentDer = $this->asn1Integer($e);
		$rsaPublicKey = $this->asn1Sequence($modulusDer . $exponentDer);
		$algorithm = $this->asn1Sequence($this->asn1Oid('1.2.840.113549.1.1.1') . "\x05\x00");
		$bitString = "\x03" . $this->asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
		$der = $this->asn1Sequence($algorithm . $bitString);
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	protected function asn1Integer(string $value): string {
		$value = ltrim($value, "\x00");
		if($value === '') $value = "\x00";
		if((ord($value[0]) & 0x80) === 0x80) $value = "\x00" . $value;
		return "\x02" . $this->asn1Length(strlen($value)) . $value;
	}

	protected function asn1Sequence(string $value): string {
		return "\x30" . $this->asn1Length(strlen($value)) . $value;
	}

	protected function asn1Oid(string $oid): string {
		$parts = array_map('intval', explode('.', $oid));
		$body = chr($parts[0] * 40 + $parts[1]);
		for($i = 2; $i < count($parts); $i++) {
			$n = $parts[$i];
			$bytes = chr($n & 0x7f);
			while($n >>= 7) {
				$bytes = chr(($n & 0x7f) | 0x80) . $bytes;
			}
			$body .= $bytes;
		}
		return "\x06" . $this->asn1Length(strlen($body)) . $body;
	}

	protected function asn1Length(int $length): string {
		if($length < 128) return chr($length);
		$out = '';
		while($length > 0) {
			$out = chr($length & 0xff) . $out;
			$length >>= 8;
		}
		return chr(0x80 | strlen($out)) . $out;
	}

	// -----------------------------------------------------------------
	// HTTP helpers
	// -----------------------------------------------------------------

	public function httpPost(string $url, array $params, string $accept = 'json'): array {
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($params),
			CURLOPT_HTTPHEADER     => [
				'Accept: application/' . ($accept === 'json' ? 'json' : 'x-www-form-urlencoded'),
				'Content-Type: application/x-www-form-urlencoded',
			],
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		$response = (string) curl_exec($ch);
		$error = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if($error || $status >= 400) {
			return [
				'_error' => $error ?: "HTTP {$status}",
				'_status' => $status,
				'_body' => $response,
			];
		}
		if($accept === 'json') return json_decode($response, true) ?: [];
		parse_str($response, $data);
		return $data;
	}

	public function httpGet(string $url, string $token): array {
		$headers = ['Accept: application/json', 'User-Agent: ProcessWire-Oidc/1.1'];
		if($token) $headers[] = "Authorization: Bearer {$token}";
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		$response = (string) curl_exec($ch);
		$error = curl_error($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if($error || $status >= 400) return [];
		return json_decode($response, true) ?: [];
	}

	// -----------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------

	/**
	 * Resolve the callback URL: module setting > current page httpUrl.
	 * The callback URL must be the same page that renders ?oidc= buttons.
	 */
	protected function resolveCallbackUrl(): string {
		$setting = trim((string) $this->callbackUrl);
		return $setting ?: $this->wire('page')->httpUrl();
	}

	/**
	 * Generic OIDC provider skeleton for custom providers not in built-in defs.
	 */
	protected function genericDef(array $row): array {
		return [
			'label'          => $row['label'] ?? ($row['id'] ?? 'Custom'),
			'svg'            => "<svg width='18' height='18' viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg' fill='none' stroke='currentColor' stroke-width='2'><circle cx='12' cy='12' r='10'/><path d='M12 8v4l3 3'/></svg>",
			'bg_color'       => '#444',
			'text_color'     => '#fff',
			'auth_url'       => $row['auth_url']      ?? '',
			'token_url'      => $row['token_url']     ?? '',
			'userinfo_url'   => $row['userinfo_url']  ?? '',
			'scope'          => $row['scope']          ?? 'openid email profile',
			'token_type'     => 'json',
			'email_field'    => 'email',
			'name_field'     => 'name',
			'verified_field' => null,
			'email_verified_required' => true,
			'extra_emails'   => false,
			'oidc'           => true,
			'pkce'           => true,
		];
	}

	/**
	 * Validate a redirect URL: must be a relative path on this site.
	 * Returns empty string if the URL is external or suspicious.
	 */
	protected function safeRedirectUrl(string $url): string {
		$url = trim($url);
		if(!$url) return '';
		// Reject anything with a scheme, protocol-relative, or backslash tricks
		if(preg_match('~^([a-zA-Z][a-zA-Z0-9+\-.]*:|//|\\\\)~', $url)) return '';
		// Must start with /
		if(!str_starts_with($url, '/')) return '';
		// parse_url must not find a host
		$parsed = parse_url($url);
		if(!empty($parsed['host'])) return '';
		return $url;
	}

	protected function h($value): string {
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}

	protected function truthy($value): bool {
		if(is_bool($value)) return $value;
		if(is_int($value) || is_float($value)) return ((float) $value) !== 0.0;
		if(is_string($value)) return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
		return false;
	}

	protected function safeCssColor(string $color): string {
		$color = trim($color);
		if(preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) return $color;
		if(preg_match('/^[a-zA-Z]+$/', $color)) return $color;
		if(preg_match('/^rgba?\(\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}\s*,\s*[0-9]{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/', $color)) return $color;
		return '';
	}

	// -----------------------------------------------------------------
	// Module configuration
	// -----------------------------------------------------------------

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): void {
		$modules = $this->wire('modules');

		// Decode providers JSON string → array before PW saves config
		$this->wire->addHookBefore('Modules::saveConfig', function(HookEvent $e) {
			$mod  = $e->arguments(0);
			$data = $e->arguments(1);
			if((is_object($mod) ? $mod->className() : (string) $mod) !== 'Oidc') return;
			if(isset($data['providers']) && is_string($data['providers'])) {
				$decoded = json_decode($data['providers'], true);
				$data['providers'] = is_array($decoded)
					? array_values(array_filter($decoded, fn($r) => !empty($r['client_id']) || !empty($r['discovery_url'])))
					: [];
				$e->arguments(1, $data);
			}
			if(!isset($data['identityLinks'])) {
				$data['identityLinks'] = is_array($this->identityLinks) ? $this->identityLinks : [];
				$e->arguments(1, $data);
			}
		});

		// ---- Build rows data ----
		$defs      = $this->getProviderDefs();
		$saved     = is_array($this->providers) ? $this->providers : [];
		$savedById = [];
		foreach($saved as $row) {
			if(!empty($row['id'])) $savedById[$row['id']] = $row;
		}

		$rows = [];
		foreach($defs as $id => $def) {
			$rows[] = [
				'id'            => $id,
				'label'         => $def['label'],
				'client_id'     => $savedById[$id]['client_id']     ?? '',
				'client_secret' => $savedById[$id]['client_secret'] ?? '',
				'discovery_url' => '',
				'builtin'       => true,
			];
		}
		foreach($saved as $row) {
			$id = $row['id'] ?? '';
			if(!$id || isset($defs[$id])) continue;
			$rows[] = [
				'id'            => $id,
				'label'         => $row['label']         ?? $id,
				'client_id'     => $row['client_id']     ?? '',
				'client_secret' => $row['client_secret'] ?? '',
				'discovery_url' => $row['discovery_url'] ?? '',
				'builtin'       => false,
			];
		}

		$jsonFlags   = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
		$rowsJson    = json_encode($rows, $jsonFlags);
		$callbackUrl = json_encode($this->resolveCallbackUrl(), $jsonFlags);

		// ---- Providers fieldset ----
		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = 'Providers';
		$fs->icon  = 'key';

		/** @var InputfieldMarkup $f */
		$f = $modules->get('InputfieldMarkup');
		$f->attr('id+name', 'oidc_providers_ui');
		$f->skipLabel = Inputfield::skipLabelBlank;
		$f->value = <<<HTML
<p class="uk-text-muted uk-text-small uk-margin-small-bottom">Fill Client ID + Secret to enable a provider. The link icon shows the Callback URL to register in each provider's app settings.</p>

<div class="uk-overflow-auto">
<table id="OIDCTable" class="uk-table uk-table-divider uk-table-small uk-margin-remove-bottom" style="table-layout:fixed;width:100%">
  <colgroup>
	<col style="width:100px">
	<col>
	<col>
	<col style="width:36px">
  </colgroup>
  <thead>
	<tr>
	  <th>Provider</th>
	  <th>Client ID</th>
	  <th>Client Secret</th>
	  <th></th>
	</tr>
  </thead>
  <tbody id="OIDCBuiltinBody"></tbody>
</table>
</div>

<h4 class="uk-h6 uk-margin-top uk-margin-small-bottom uk-text-bold">Custom OIDC provider</h4>
<p class="uk-text-muted uk-text-small uk-margin-remove-top uk-margin-small-bottom">One custom provider: Okta, Auth0, Keycloak, authentik, Azure AD (single-tenant), etc. Discovery URL auto-fetches endpoints from <code>/.well-known/openid-configuration</code>.</p>

<div class="uk-card uk-card-default uk-card-body uk-card-small">
  <div class="uk-grid-small uk-margin-remove-bottom" uk-grid>
	<div class="uk-width-1-4@m">
	  <label class="uk-form-label uk-text-small">ID (slug)</label>
	  <input type="text" id="oidcCustomId" class="uk-input" placeholder="okta">
	</div>
	<div class="uk-width-1-4@m">
	  <label class="uk-form-label uk-text-small">Display name</label>
	  <input type="text" id="oidcCustomLabel" class="uk-input" placeholder="Okta">
	</div>
	<div class="uk-width-1-4@m">
	  <label class="uk-form-label uk-text-small">Client ID</label>
	  <input type="text" id="oidcCustomClientId" class="uk-input">
	</div>
	<div class="uk-width-1-4@m">
	  <label class="uk-form-label uk-text-small">Client Secret</label>
	  <input type="password" id="oidcCustomSecret" class="uk-input">
	</div>
  </div>
  <div class="uk-grid-small uk-margin-small-top" uk-grid>
	<div class="uk-width-expand">
	  <label class="uk-form-label uk-text-small">Discovery URL (issuer)</label>
	  <input type="text" id="oidcCustomDiscovery" class="uk-input" placeholder="https://your-domain.okta.com">
	</div>
	<div class="uk-width-auto uk-flex uk-flex-bottom">
	  <div id="oidcCustomCb" class="uk-text-small uk-text-muted uk-margin-small-bottom" style="line-height:1.3;white-space:nowrap"></div>
	</div>
  </div>
</div>

<script>
(function () {
  var rows    = {$rowsJson};
  var base    = {$callbackUrl};
  var sep     = base.indexOf('?') !== -1 ? '&' : '?';
  var builtin = rows.filter(function(r){ return r.builtin; });
  var custom  = rows.filter(function(r){ return !r.builtin; })[0] || null;
  var tbody   = document.getElementById('OIDCBuiltinBody');

  var SVG_LINK  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';

  // ---- Built-in table ----
  function renderBuiltin() {
	tbody.innerHTML = '';
	builtin.forEach(function (r, i) {
	  var callbackFull = base + sep + 'oidc=' + encodeURIComponent(r.id);
	  var tr = document.createElement('tr');
	  tr.innerHTML =
		  '<td class="uk-text-bold uk-text-small">' + esc(r.label) + '</td>'
		+ '<td><input type="text" value="' + esc(r.client_id) + '" placeholder="Client ID" class="uk-input" data-bi="' + i + '" data-f="client_id"></td>'
		+ '<td><input type="password" value="' + esc(r.client_secret) + '" placeholder="Secret" class="uk-input" data-bi="' + i + '" data-f="client_secret"></td>'
		+ '<td class="uk-text-center" style="white-space:nowrap">'
		  + '<a href="' + esc(callbackFull) + '" class="uk-icon-link" target="_blank" title="' + esc(callbackFull) + '" style="vertical-align:middle">' + SVG_LINK + '</a>'
		+ '</td>';
	  tbody.appendChild(tr);
	});
  }

  tbody.addEventListener('input', function (e) {
	var el = e.target;
	var i  = el.getAttribute('data-bi');
	var f  = el.getAttribute('data-f');
	if (i !== null && f) { builtin[parseInt(i)][f] = el.value; persist(); }
  });

  // ---- Custom OIDC section ----
  var elId       = document.getElementById('oidcCustomId');
  var elLabel    = document.getElementById('oidcCustomLabel');
  var elClientId = document.getElementById('oidcCustomClientId');
  var elSecret   = document.getElementById('oidcCustomSecret');
  var elDisc     = document.getElementById('oidcCustomDiscovery');
  var elCb       = document.getElementById('oidcCustomCb');

  function fillCustom() {
	if (!custom) return;
	elId.value       = custom.id       || '';
	elLabel.value    = custom.label    || '';
	elClientId.value = custom.client_id     || '';
	elSecret.value   = custom.client_secret || '';
	elDisc.value     = custom.discovery_url || '';
	updateCb();
  }

  function updateCb() {
	var id = elId.value.trim();
	if (id) {
	  var url = base + sep + 'oidc=' + encodeURIComponent(id);
	  elCb.innerHTML = '<span class="uk-text-muted">CB:</span> <a href="' + esc(url) + '" target="_blank" class="uk-link-muted" style="word-break:break-all;font-size:11px">' + esc(url) + '</a>';
	} else {
	  elCb.innerHTML = '';
	}
  }

  [elId, elLabel, elClientId, elSecret, elDisc].forEach(function(el) {
	el.addEventListener('input', function() {
	  custom = {
		id:            elId.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, ''),
		label:         elLabel.value.trim(),
		client_id:     elClientId.value.trim(),
		client_secret: elSecret.value.trim(),
		discovery_url: elDisc.value.trim(),
		builtin:       false
	  };
	  if (el === elId) elId.value = custom.id; // enforce slug on the fly
	  updateCb();
	  persist();
	});
  });

  // ---- Persist ----
  function persist() {
	var out = builtin.map(function (r) {
	  return { id: r.id, label: r.label, client_id: r.client_id,
			   client_secret: r.client_secret, discovery_url: '' };
	});
	if (custom && (custom.client_id || custom.discovery_url)) {
	  out.push({ id: custom.id, label: custom.label, client_id: custom.client_id,
				 client_secret: custom.client_secret, discovery_url: custom.discovery_url });
	}
	var h = document.getElementById('OIDCData');
	if (h) h.value = JSON.stringify(out);
  }

  function esc(s) {
	return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  document.addEventListener('submit', persist, true);
  renderBuiltin();
  fillCustom();
}());
</script>
HTML;
		$fs->add($f);

		/** @var InputfieldHidden $fh */
		$fh = $modules->get('InputfieldHidden');
		$fh->attr('id', 'OIDCData');
		$fh->attr('name', 'providers');
		$fh->attr('value', json_encode(array_values($this->providers ?: []), $jsonFlags));
		$fs->add($fh);

		$inputfields->add($fs);

		// ---- Settings fieldset ----
		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label     = 'Settings';
		$fs->icon      = 'cog';
		$fs->collapsed = Inputfield::collapsedBlank;

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'callbackUrl');
		$f->label       = 'Callback URL';
		$f->notes       = 'Fixed URL registered with your OAuth providers. Must be the same page where renderButtons() is called. Leave blank to use the current page URL at runtime (not recommended for production).';
		$f->attr('value', $this->callbackUrl);
		$f->columnWidth = 50;
		$fs->add($f);

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'loginRedirect');
		$f->label       = 'Redirect after login';
		$f->notes       = 'Relative path, e.g. /account/. Leave blank to use ?return= from URL, or fall back to ?oidc_login=1.';
		$f->attr('value', $this->loginRedirect);
		$f->columnWidth = 50;
		$fs->add($f);

		// Silent mode — redirect unauthenticated users straight to provider
		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'silentMode');
		$f->label       = 'Silent mode';
		$f->notes       = 'When on, unauthenticated users are immediately redirected to the default provider — no login buttons shown.';
		$f->attr('value', 1);
		if($this->silentMode) $f->attr('checked', 'checked');
		$f->columnWidth = 20;
		$fs->add($f);

		// Protected path for silent mode
		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'silentPath');
		$f->label       = 'Protected path';
		$f->notes       = 'If set, silent mode only applies to pages whose URL starts with this path, e.g. /account/. Leave blank to protect the entire site.';
		$f->attr('value', $this->silentPath);
		$f->columnWidth = 45;
		$fs->add($f);

		// Default provider for silent mode and renderButtons() ordering
		/** @var InputfieldSelect $f */
		$f = $modules->get('InputfieldSelect');
		$f->attr('name', 'defaultProvider');
		$f->label  = 'Default provider';
		$f->notes  = 'Used for silent mode redirect. Also shown first in renderButtons(). Leave blank to use the first configured provider.';
		$f->addOption('', '— none —');
		foreach($this->getEnabledProviders() as $pid => $pcfg) {
			$f->addOption($pid, $pcfg['label']);
		}
		$f->attr('value', $this->defaultProvider ?: '');
		$f->columnWidth = 32;
		$fs->add($f);

		/** @var InputfieldRadios $f */
		$f = $modules->get('InputfieldRadios');
		$f->attr('name', 'buttonStyle');
		$f->label         = 'Button style';
		$f->addOption('full', 'Full — icon + provider name');
		$f->addOption('icon', 'Icons only');
		$f->attr('value', $this->buttonStyle ?: 'full');
		$f->optionColumns = 1;
		$f->columnWidth   = 50;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'autoRegister');
		$f->label       = 'Auto-register new users';
		$f->notes       = 'When off, unknown emails throw a 404. Hook ___registerUser() to implement custom registration logic.';
		$f->attr('value', 1);
		if($this->autoRegister) $f->attr('checked', 'checked');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'allowEmailAccountLink');
		$f->label       = 'Allow email fallback linking';
		$f->notes       = 'Security-sensitive legacy mode. When off, an OIDC identity can only login by its provider subject link; existing email matches must login first and link explicitly.';
		$f->attr('value', 1);
		if($this->allowEmailAccountLink) $f->attr('checked', 'checked');
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'newUserRole');
		$f->label       = 'Role for new users';
		$f->notes       = 'Role name assigned on auto-registration. Leave blank for none.';
		$f->attr('value', $this->newUserRole);
		$f->columnWidth = 34;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'blockSuperuserLogin');
		$f->label       = 'Block superuser login';
		$f->notes       = 'Recommended. Prevents OIDC from creating a superuser session even if a provider identity is linked.';
		$f->attr('value', 1);
		if($this->blockSuperuserLogin) $f->attr('checked', 'checked');
		$f->columnWidth = 33;
		$fs->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'allowedLoginRoles');
		$f->label       = 'Allowed login roles';
		$f->notes       = 'Optional comma-separated role names. Leave blank to allow any non-superuser account.';
		$f->attr('value', $this->allowedLoginRoles);
		$f->columnWidth = 33;
		$fs->add($f);

		$inputfields->add($fs);
	}
}
