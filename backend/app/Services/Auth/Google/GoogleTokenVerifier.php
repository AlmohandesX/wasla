<?php

namespace App\Services\Auth\Google;

use App\Exceptions\InvalidGoogleToken;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks a Google ID token from the app: signed by one of Google's current keys, issued by Google to one of our
 * OAuth clients, not expired, and naming a verified email. Access tokens are not accepted: they do not say which
 * app they were issued to.
 */
class GoogleTokenVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private const CACHE_KEY = 'google:jwks';

    /** Clock skew allowed on exp, iat and nbf. */
    private const LEEWAY_SECONDS = 60;

    /** @throws InvalidGoogleToken */
    public function verify(#[\SensitiveParameter] string $idToken): GoogleIdentity
    {
        $audiences = (array) config('wasla.google.client_ids');
        if ($audiences === []) {
            report(new \RuntimeException('Google sign-in is not configured: set GOOGLE_CLIENT_IDS.'));
            throw new InvalidGoogleToken;
        }

        $claims = $this->decode($idToken);

        $verified = ($claims->email_verified ?? false) === true || ($claims->email_verified ?? null) === 'true';
        if (! in_array($claims->iss ?? null, self::ISSUERS, true)
            || ! in_array($claims->aud ?? null, $audiences, true)
            || ! is_string($claims->sub ?? null) || $claims->sub === ''
            || ! is_string($claims->email ?? null) || ! $verified) {
            throw new InvalidGoogleToken;
        }

        return new GoogleIdentity(
            sub: $claims->sub,
            email: mb_strtolower(trim($claims->email)),
            name: is_string($claims->name ?? null) ? trim($claims->name) : '',
        );
    }

    private function decode(string $idToken): object
    {
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->keySet(), 'RS256'));
        } catch (Throwable) {
            // Google rotates its keys: a token signed with a key newer than our cached set gets one retry with a
            // fresh set. At most once a minute, so a stream of junk tokens cannot make us hammer Google.
            if (! Cache::add(self::CACHE_KEY.':refreshed', true, 60)) {
                throw new InvalidGoogleToken;
            }
            Cache::forget(self::CACHE_KEY);
        }

        try {
            return JWT::decode($idToken, JWK::parseKeySet($this->keySet(), 'RS256'));
        } catch (Throwable) {
            throw new InvalidGoogleToken;
        }
    }

    /** Google's public keys, cached for as long as Google's Cache-Control allows. */
    private function keySet(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get(self::CERTS_URL)->throw();
        } catch (Throwable $e) {
            report($e);
            throw new InvalidGoogleToken;
        }

        $keys = $response->json();
        if (! is_array($keys) || ! isset($keys['keys'])) {
            throw new InvalidGoogleToken;
        }

        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $match);
        Cache::put(self::CACHE_KEY, $keys, max(60, min((int) ($match[1] ?? 3600), 86400)));

        return $keys;
    }
}
