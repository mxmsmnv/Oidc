<?php declare(strict_types=1);

namespace ProcessWire {
	interface Module {}
	interface ConfigurableModule {}
	class WireException extends \RuntimeException {}
	class Wire404Exception extends WireException {}
	class RedirectException extends \RuntimeException { public function __construct(public string $url, public int $status) { parent::__construct('redirect'); } }
	class WireData {
		private array $data = [];
		protected array $services = [];
		public function __construct() {}
		public function set(string $key, $value): void { $this->data[$key] = $value; }
		public function __get(string $key) { return $this->data[$key] ?? null; }
		public function __set(string $key, $value): void { $this->data[$key] = $value; }
		public function wire(?string $name = null) { return $name === null ? $this : ($this->services[$name] ?? null); }
		public function __call(string $name, array $arguments) {
			$implementation = '___' . $name;
			if(method_exists($this, $implementation)) return $this->{$implementation}(...$arguments);
			throw new \BadMethodCallException($name);
		}
	}
	class User {}
	class HookEvent {}
	class InputfieldWrapper {}

	final class FakeSession {
		private array $data = [];
		private function key(object $owner, string $key): string { return spl_object_hash($owner) . ':' . $key; }
		public function getFor(object $owner, string $key) { return $this->data[$this->key($owner, $key)] ?? null; }
		public function setFor(object $owner, string $key, $value): void { $this->data[$this->key($owner, $key)] = $value; }
		public function removeFor(object $owner, string $key): void { unset($this->data[$this->key($owner, $key)]); }
		public function redirect(string $url, int $status = 301): void { throw new RedirectException($url, $status); }
	}
	final class FakeInput {
		public function __construct(public array $data = []) {}
		public function get(string $key) { return $this->data[$key] ?? null; }
	}

	final class FakeCache {
		public array $data = [];
		public function get(string $key) { return $this->data[$key] ?? null; }
		public function save(string $key, $value, int $ttl): void { $this->data[$key] = $value; }
	}
	final class FakeLog {
		public array $entries = [];
		public function save(string $channel, string $message): void { $this->entries[] = [$channel, $message]; }
	}

	final class FakeSanitizer {
		public function name(string $value): string { return preg_replace('/[^a-zA-Z0-9_-]/', '', $value) ?? ''; }
		public function text(string $value): string { return strip_tags($value); }
	}

	require dirname(__DIR__) . '/Oidc.module.php';

	class TestOidc extends Oidc {
		public string $verificationKey = '';
		public array $nextJwks = [];
		public int $jwksFetches = 0;
		public function __construct(array $services) { parent::__construct(); $this->services = $services; }
		protected function getJwksPublicKey(string $uri, string $kid): string {
			return $this->verificationKey !== '' ? $this->verificationKey : parent::getJwksPublicKey($uri, $kid);
		}
		protected function fetchJwks(string $uri, string $cacheKey): array {
			$this->jwksFetches++;
			$this->wire('cache')->save($cacheKey, $this->nextJwks, 3600);
			return $this->nextJwks;
		}
		public function storeTx(string $state, array $tx): void { $this->storeTransaction($state, $tx); }
		public function consumeTx(string $state, string $provider): array { return $this->consumeTransaction($state, $provider); }
		public function safeReturn(string $url): string { return $this->safeRedirectUrl($url); }
		public function selectJwk(array $jwks, string $kid): string { return $this->findJwksPublicKey($jwks, $kid); }
		public function resolveJwk(string $uri, string $kid): string { return parent::getJwksPublicKey($uri, $kid); }
		public function safeEndpoint(string $url): void { $this->assertSafeHttpsUrl($url); }
		public function providerEndpoints(array $cfg): void { $this->assertProviderEndpoints($cfg); }
		public function failureCode(string $message): string { return $this->flowFailureCode(new WireException($message)); }
		public function redirectFailure(string $message, string $provider): void { $this->handleFlowFailure(new WireException($message), $provider); }
	}

	class FlowOidc extends Oidc {
		public array $provider;
		public array $tokenResponse = ['access_token' => 'access'];
		public function __construct(array $services) {
			parent::__construct();
			$this->services = $services;
			$this->provider = [
				'label' => 'FusionAuth', 'auth_url' => 'https://issuer.example/oauth2/authorize',
				'token_url' => 'https://issuer.example/oauth2/token', 'userinfo_url' => '',
				'jwks_uri' => 'https://issuer.example/jwks', 'issuer' => 'https://issuer.example',
				'scope' => 'openid email profile', 'client_id' => 'test-client',
				'client_secret' => 'runtime-test-secret', 'oidc' => true, 'pkce' => true,
			];
		}
		public function getEnabledProviders(): array { return ['fusionauth' => $this->provider]; }
		public function httpPost(string $url, array $params, string $accept = 'json'): array { return $this->tokenResponse; }
		protected function resolveCallbackUrl(): string { return 'https://app.example/sso/'; }
		protected function assertSafeHttpsUrl(string $url): array { return ['host' => 'issuer.example', 'port' => 443, 'ip' => '203.0.113.1', 'resolve' => '']; }
	}

	class RuntimeOidc extends Oidc {
		public function __construct(array $services) { parent::__construct(); $this->services = $services; }
		protected function ___getProviderDefs(): array {
			return ['fusionauth' => [
				'label' => 'FusionAuth', 'auth_url' => 'https://issuer.example/auth',
				'token_url' => 'https://issuer.example/token', 'userinfo_url' => 'https://issuer.example/userinfo',
				'jwks_uri' => 'https://issuer.example/jwks', 'issuer' => 'https://issuer.example',
				'scope' => 'openid email profile', 'oidc' => true, 'pkce' => true,
			]];
		}
	}
}

namespace {
	use ProcessWire\FakeCache;
	use ProcessWire\FakeInput;
	use ProcessWire\FakeLog;
	use ProcessWire\FakeSanitizer;
	use ProcessWire\FakeSession;
	use ProcessWire\TestOidc;
	use ProcessWire\FlowOidc;
	use ProcessWire\RedirectException;
	use ProcessWire\RuntimeOidc;
	use ProcessWire\WireException;

	$tests = 0;
	$failures = [];
	$assert = static function(bool $condition, string $message) use (&$tests, &$failures): void {
		$tests++;
		if(!$condition) $failures[] = $message;
	};
	$b64 = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	$makeJwt = static function(array $claims, $privateKey, array $header = ['alg' => 'RS256', 'kid' => 'test-key']) use ($b64): string {
		$head = $b64(json_encode($header, JSON_UNESCAPED_SLASHES));
		$body = $b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
		openssl_sign($head . '.' . $body, $signature, $privateKey, OPENSSL_ALGO_SHA256);
		return $head . '.' . $body . '.' . $b64($signature);
	};

	$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
	openssl_pkey_export($key, $privatePem);
	$details = openssl_pkey_get_details($key);
	$publicPem = $details['key'];
	$config = (object) ['oidcProviders' => [], 'oidcAllowedIssuers' => [], 'oidcAllowedEndpointOrigins' => []];
	$cache = new FakeCache();
	$oidc = new TestOidc(['session' => new FakeSession(), 'cache' => $cache, 'config' => $config, 'sanitizer' => new FakeSanitizer()]);
	$oidc->verificationKey = $publicPem;
	$claimsFactory = require __DIR__ . '/fixtures/claims.php';
	$nonce = 'nonce-123';
	$base = $claimsFactory($nonce);
	$cfg = ['jwks_uri' => 'https://issuer.example/jwks', 'issuer' => 'https://issuer.example', 'client_id' => 'test-client'];
	$valid = $makeJwt($base, $privatePem);
	$assert($oidc->validateIdToken($valid, $cfg, $nonce)['sub'] === 'user-123', 'valid token rejected');

	$tampered = substr($valid, 0, -2) . 'aa';
	$assert($oidc->validateIdToken($tampered, $cfg, $nonce) === [], 'tampered signature accepted');
	$mutations = [
		'nonce' => ['nonce' => 'wrong'],
		'issuer' => ['iss' => 'https://evil.example'],
		'audience' => ['aud' => 'other-client'],
		'expired' => ['exp' => time() - 120],
	];
	foreach($mutations as $label => $change) {
		$assert($oidc->validateIdToken($makeJwt(array_merge($base, $change), $privatePem), $cfg, $nonce) === [], "$label token accepted");
	}
	foreach(['iss', 'aud', 'exp', 'sub', 'nonce'] as $claim) {
		$missing = $base;
		unset($missing[$claim]);
		$assert($oidc->validateIdToken($makeJwt($missing, $privatePem), $cfg, $nonce) === [], "missing $claim accepted");
	}
	$multi = array_merge($base, ['aud' => ['test-client', 'api']]);
	$assert($oidc->validateIdToken($makeJwt($multi, $privatePem), $cfg, $nonce) === [], 'multiple aud without azp accepted');
	$assert($oidc->validateIdToken($makeJwt(array_merge($multi, ['azp' => 'wrong']), $privatePem), $cfg, $nonce) === [], 'wrong azp accepted');
	$assert($oidc->validateIdToken($makeJwt(array_merge($multi, ['azp' => 'test-client']), $privatePem), $cfg, $nonce) !== [], 'valid azp rejected');
	$assert($oidc->validateIdToken($makeJwt($base, $privatePem, ['alg' => 'RS256']), $cfg, $nonce) === [], 'missing kid accepted');

	$oidc->storeTx('state-a', ['provider' => 'fusionauth', 'created' => time(), 'nonce' => 'a']);
	$oidc->storeTx('state-b', ['provider' => 'fusionauth', 'created' => time(), 'nonce' => 'b']);
	$assert(($oidc->consumeTx('state-a', 'fusionauth')['nonce'] ?? '') === 'a', 'parallel state A lost');
	$assert(($oidc->consumeTx('state-b', 'fusionauth')['nonce'] ?? '') === 'b', 'parallel state B lost');
	$assert($oidc->consumeTx('state-a', 'fusionauth') === [], 'state replay accepted');
	$assert($oidc->consumeTx('tampered', 'fusionauth') === [], 'tampered state accepted');
	$oidc->storeTx('expired', ['provider' => 'fusionauth', 'created' => time() - 601]);
	$assert($oidc->consumeTx('expired', 'fusionauth') === [], 'expired state accepted');

	$assert($oidc->safeReturn('/account/?tab=profile') !== '', 'safe local return rejected');
	foreach(['https://evil.example', '//evil.example', '/\\evil.example', '/%5cevil.example', "/account/\r\nLocation:https://evil.example"] as $unsafe) {
		$assert($oidc->safeReturn($unsafe) === '', "unsafe return accepted: $unsafe");
	}

	$rsa = $details['rsa'];
	$jwk = ['kid' => 'new', 'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'key_ops' => ['verify'], 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])];
	$assert($oidc->selectJwk(['keys' => [$jwk]], 'new') !== '', 'strict valid JWK rejected');
	foreach([['use' => 'enc'], ['alg' => 'RS512'], ['key_ops' => ['sign']], ['kid' => 'other']] as $change) {
		$assert($oidc->selectJwk(['keys' => [array_merge($jwk, $change)]], 'new') === '', 'invalid JWK accepted');
	}

	$oidc->verificationKey = '';
	$cache->data['oidc_jwks_' . md5('https://issuer.example/jwks')] = ['keys' => [array_merge($jwk, ['kid' => 'old'])]];
	$oidc->nextJwks = ['keys' => [$jwk]];
	$assert($oidc->resolveJwk('https://issuer.example/jwks', 'new') !== '', 'unknown kid did not refresh JWKS');
	$assert($oidc->jwksFetches === 1, 'unknown kid refresh count is not one');
	foreach(['http://8.8.8.8/token', 'https://127.0.0.1/token', 'https://169.254.169.254/latest', 'https://[::1]/token'] as $unsafeEndpoint) {
		try { $oidc->safeEndpoint($unsafeEndpoint); $assert(false, "unsafe endpoint accepted: $unsafeEndpoint"); }
		catch(WireException $e) { $assert(true, 'unsafe endpoint rejected'); }
	}
	try {
		$oidc->providerEndpoints([
			'issuer' => 'https://8.8.8.8', 'auth_url' => 'https://8.8.8.8/auth',
			'token_url' => 'https://1.1.1.1/token', 'userinfo_url' => '', 'jwks_uri' => 'https://8.8.8.8/jwks',
		]);
		$assert(false, 'cross-origin token endpoint accepted');
	} catch(WireException $e) { $assert(true, 'cross-origin endpoint rejected'); }

	$failureCodes = [
		'OIDC: unverified email from FusionAuth' => 'email_unverified',
		'OIDC: an account with this email already exists; login first to link this provider' => 'account_link_required',
		'OIDC: no account found and auto-registration is disabled' => 'registration_disabled',
		'OIDC: state mismatch — possible CSRF attempt' => 'invalid_state',
		'OIDC: provider returned error access_denied' => 'access_denied',
		'OIDC: superuser login is blocked for fusionauth' => 'account_not_allowed',
		'OIDC: token exchange failed for FusionAuth' => 'authentication_failed',
	];
	foreach($failureCodes as $message => $expectedCode) {
		$assert($oidc->failureCode($message) === $expectedCode, "wrong public failure code for {$expectedCode}");
	}
	$failureLog = new FakeLog();
	$failureSession = new FakeSession();
	$failureOidc = new TestOidc(['session' => $failureSession, 'log' => $failureLog, 'config' => $config, 'sanitizer' => new FakeSanitizer()]);
	$failureOidc->errorRedirect = '/login/';
	try {
		$failureOidc->redirectFailure('OIDC: unverified email from FusionAuth for private@example.com token-secret', 'fusionauth');
		$assert(false, 'failure redirect did not run');
	} catch(RedirectException $e) {
		$assert($e->status === 303, 'failure redirect is not a 303');
		$assert($e->url === '/login/?oidc_error=email_unverified', 'failure redirect leaked details or used the wrong reason');
	}
	$failureLogText = json_encode($failureLog->entries);
	$assert(!str_contains($failureLogText, 'private@example.com'), 'failure log leaked the email address');
	$assert(!str_contains($failureLogText, 'token-secret'), 'failure log leaked provider details');

	$flowSession = new FakeSession();
	$flowInput = new FakeInput();
	$flow = new FlowOidc(['session' => $flowSession, 'input' => $flowInput, 'sanitizer' => new FakeSanitizer()]);
	try {
		$flow->runFlow('fusionauth');
		$assert(false, 'authorization did not redirect');
	} catch(RedirectException $e) {
		$assert($e->status === 302, 'authorization redirect is not temporary');
		parse_str((string) parse_url($e->url, PHP_URL_QUERY), $query);
		$flowInput->data = ['code' => 'code', 'state' => $query['state'] ?? ''];
	}
	try {
		$flow->runFlow('fusionauth');
		$assert(false, 'missing id_token accepted');
	} catch(WireException $e) {
		$assert(str_contains($e->getMessage(), 'id_token is required'), 'missing id_token failed for the wrong reason');
	}

	$flowInput->data = [];
	try { $flow->runFlow('fusionauth'); } catch(RedirectException $e) { parse_str((string) parse_url($e->url, PHP_URL_QUERY), $errorQuery); }
	$flowInput->data = ['error' => 'access_denied', 'state' => $errorQuery['state'] ?? ''];
	try { $flow->runFlow('fusionauth'); } catch(WireException $e) { $assert(str_contains($e->getMessage(), 'provider returned error'), 'valid error callback rejected before provider error'); }
	try {
		$flow->runFlow('fusionauth');
		$assert(false, 'error callback state replay accepted');
	} catch(WireException $e) {
		$assert(str_contains($e->getMessage(), 'state mismatch'), 'error callback replay was not rejected by state');
	}

	$runtimeConfig = (object) ['oidcRequireRuntimeCredentials' => true, 'oidcProviders' => []];
	$runtime = new RuntimeOidc(['config' => $runtimeConfig, 'sanitizer' => new FakeSanitizer()]);
	$runtime->providers = [['id' => 'fusionauth', 'client_id' => 'db-client', 'client_secret' => 'db-secret']];
	$assert($runtime->getEnabledProviders() === [], 'database credentials enabled provider in runtime-only mode');
	$runtimeConfig->oidcProviders = ['fusionauth' => ['client_id' => 'runtime-client', 'client_secret' => 'runtime-secret']];
	$assert(($runtime->getEnabledProviders()['fusionauth']['client_id'] ?? '') === 'runtime-client', 'runtime credentials were not injected');

	if($failures) {
		fwrite(STDERR, "FAIL (" . count($failures) . "/$tests)\n- " . implode("\n- ", $failures) . "\n");
		exit(1);
	}
	echo "OK ($tests assertions)\n";
}
