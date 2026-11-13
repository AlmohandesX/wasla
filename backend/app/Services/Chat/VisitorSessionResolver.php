<?php

namespace App\Services\Chat;

use App\Models\VisitorSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Maps the random wasla_visitor cookie to a VisitorSession. Only the SHA-256 of the cookie is stored. */
class VisitorSessionResolver
{
    public const COOKIE = 'wasla_visitor';

    /** The session behind the request cookie, or null for a stranger. Never creates anything. */
    public function find(Request $request): ?VisitorSession
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $session = VisitorSession::query()->where('token_hash', hash('sha256', $token))->first();
        if ($session !== null && $session->last_seen_at->lt(now()->subHour())) {
            $session->update(['last_seen_at' => now()]);
        }

        return $session;
    }

    /** The existing session, or a new one whose cookie is queued onto the response. */
    public function resolve(Request $request): VisitorSession
    {
        return $this->find($request) ?? $this->create($request);
    }

    private function create(Request $request): VisitorSession
    {
        $token = bin2hex(random_bytes(32));
        $session = VisitorSession::create([
            'token_hash' => hash('sha256', $token),
            'ip_hash' => ChatService::hashIp((string) $request->ip()),
            'last_seen_at' => now(),
        ]);

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: (int) config('wasla.chat.cookie_days') * 24 * 60,
            path: '/',
            secure: $request->isSecure() || app()->environment('production'),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $session;
    }
}
