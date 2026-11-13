<?php

use App\Services\Auth\Google\GoogleTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

const GOOGLE_TEST_CLIENT = 'test-web-client.apps.googleusercontent.com';

/**
 * Fixed test-only RSA keys (tests/Fixtures/google/<kid>.pem) standing in for Google's signing keys. Generating keys
 * at runtime needs an openssl.cnf that PHP on Windows often cannot find.
 *
 * @return array{private: string, jwk: array}
 */
function googleTestKey(string $kid = 'test-kid'): array
{
    static $keys = [];

    if (! isset($keys[$kid])) {
        $private = (string) file_get_contents(dirname(__DIR__).'/Fixtures/google/'.$kid.'.pem');
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($private))['rsa'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $keys[$kid] = ['private' => $private, 'jwk' => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]];
    }

    return $keys[$kid];
}

/** Configures our client ID and serves Google's certs endpoint with the keys googlePublishes() names. */
function fakeGoogle(array $kids = ['test-kid']): void
{
    config(['wasla.google.client_ids' => [GOOGLE_TEST_CLIENT]]);
    googlePublishes($kids);
    Http::fake([GoogleTokenVerifier::CERTS_URL => fn () => Http::response(
        ['keys' => array_map(fn (string $kid) => googleTestKey($kid)['jwk'], $GLOBALS['google_test_kids'])],
        200,
        ['Cache-Control' => 'public, max-age=3600'],
    )]);
}

/** Which test keys "Google" publishes from now on, as when it rotates its keys. */
function googlePublishes(array $kids): void
{
    $GLOBALS['google_test_kids'] = $kids;
}

/** A Google ID token for the given claims, signed with the test key. */
function googleIdToken(array $claims = [], string $kid = 'test-kid'): string
{
    $now = time();

    return JWT::encode(array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => GOOGLE_TEST_CLIENT,
        'sub' => '1098765432100',
        'email' => 'ayse@gmail.com',
        'email_verified' => true,
        'name' => 'Ayşe Yılmaz',
        'iat' => $now,
        'exp' => $now + 3600,
    ], $claims), googleTestKey($kid)['private'], 'RS256', $kid);
}
