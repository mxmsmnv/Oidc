<?php

return static function(string $nonce): array {
	return [
		'iss' => 'https://issuer.example',
		'aud' => 'test-client',
		'exp' => time() + 300,
		'iat' => time(),
		'sub' => 'user-123',
		'nonce' => $nonce,
		'email' => 'user@example.com',
		'email_verified' => true,
	];
};
