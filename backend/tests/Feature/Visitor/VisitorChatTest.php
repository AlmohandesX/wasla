<?php

use App\Events\ChatMessageCreated;
use App\Models\BlacklistTerm;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\MessageReport;
use App\Models\ModerationEvent;
use App\Models\Tag;
use App\Models\VisitorSession;
use App\Services\Chat\ChatService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['wasla.moderation.api_key' => 'sk-test', 'wasla.moderation.daily_cap' => 5000]);
    Cache::flush();
    Http::fake(['api.openai.com/*' => Http::response(['results' => [['category_scores' => ['harassment' => 0.01]]]])]);
    $this->travelTo(now()->startOfSecond());
});

function chatTag(array $carAttrs = []): Tag
{
    return linkedCar(owner(), null, $carAttrs)->tag;
}

/** Sets up the next request as a browser holding $cookie (or none) on $ip. */
function asVisitor(?string $cookie = null, string $ip = '10.0.0.5'): void
{
    test()->withServerVariables(['REMOTE_ADDR' => $ip])->withCredentials(); // JSON requests send no cookies without this
    // An empty value overrides any cookie an earlier request in this test sent.
    test()->withUnencryptedCookie('wasla_visitor', $cookie ?? '');
}

function sendVisitor(Tag $tag, string $body = 'Isiklariniz acik kalmis', ?string $cookie = null, string $ip = '10.0.0.5', array $extra = [])
{
    asVisitor($cookie, $ip);

    return test()->postJson("/t/{$tag->token}/chat/messages", ['body' => $body] + $extra);
}

function pollVisitor(Tag $tag, ?string $cookie, int $after = 0, string $ip = '10.0.0.5')
{
    asVisitor($cookie, $ip);

    return test()->getJson("/t/{$tag->token}/chat/messages?after=$after");
}

function cookieOf($response): string
{
    $cookie = $response->getCookie('wasla_visitor', false);
    expect($cookie)->not->toBeNull();

    return $cookie->getValue();
}

it('starts a thread on the first message and sets the cookie', function () {
    $tag = chatTag();

    $response = sendVisitor($tag)->assertCreated();
    $cookie = $response->getCookie('wasla_visitor', false);

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and(strlen($cookie->getValue()))->toBeGreaterThanOrEqual(40)
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->timestamp)
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->timestamp);

    $session = VisitorSession::sole();
    expect($session->token_hash)->toBe(hash('sha256', $cookie->getValue()))
        ->and($session->token_hash)->not->toBe($cookie->getValue())
        ->and($session->ip_hash)->toBe(ChatService::hashIp('10.0.0.5'));

    $thread = ChatThread::sole();
    expect($thread->car_id)->toBe($tag->car_id)
        ->and($thread->visitor_session_id)->toBe($session->id)
        ->and($thread->messages()->sole()->sender)->toBe('visitor');

    // A second message reuses the thread and does not mint another session.
    sendVisitor($tag, 'Merhaba', $cookie->getValue())->assertCreated();
    expect(ChatThread::count())->toBe(1)->and(VisitorSession::count())->toBe(1)->and(ChatMessage::count())->toBe(2);
});

it('requires a CSRF token on posts', function () {
    $tag = chatTag();
    // Laravel skips the forgery check when the app environment is "testing"; run this one as a real request.
    $this->app->detectEnvironment(fn () => 'local');
    asVisitor();

    $this->postJson("/t/{$tag->token}/chat/messages", ['body' => 'Merhaba'])->assertStatus(419);
    expect(ChatMessage::count())->toBe(0);

    $this->withSession(['_token' => 'csrf-token-1234567890'])
        ->postJson("/t/{$tag->token}/chat/messages", ['body' => 'Merhaba'], ['X-CSRF-TOKEN' => 'csrf-token-1234567890'])
        ->assertCreated();
    expect(ChatMessage::count())->toBe(1);
});

it('puts the CSRF token on the chat page and keeps the scan page cookie free', function () {
    $tag = chatTag();
    asVisitor();

    $this->get("/t/{$tag->token}/chat")->assertOk()->assertSee('name="csrf-token"', false);
    $scan = $this->get("/t/{$tag->token}")->assertOk();
    expect($scan->headers->getCookies())->toBe([]);
});

it('shows the automated checks notice before the first message', function () {
    $tag = chatTag();
    asVisitor();

    $this->get("/t/{$tag->token}/chat", ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertSee('checked by an automated service outside Turkey')
        ->assertSee('/js/visitor-chat.js', false)
        ->assertDontSee('name="consent"', false)
        ->assertDontSee('34 ABC 123');

    $this->get("/t/{$tag->token}/chat", ['Accept-Language' => 'tr'])->assertSee('Türkiye dışındaki');
});

it('asks for consent only when configured', function () {
    $tag = chatTag();
    config(['wasla.chat.consent_required' => true]);
    asVisitor();

    $this->get("/t/{$tag->token}/chat")->assertSee('name="consent"', false);
});

it('stops polling and sending when stealth turns on mid-chat', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag, 'GIZLI-METIN-1'));
    $tag->car->update(['stealth_enabled' => true]);

    $poll = pollVisitor($tag, $cookie)->assertForbidden()->assertExactJson([
        'code' => 'chat_unavailable', 'message' => __('wasla.error_chat_unavailable'),
    ]);
    $send = sendVisitor($tag, 'Yeni mesaj', $cookie)->assertForbidden();

    expect($poll->getContent())->not->toContain('GIZLI-METIN-1')
        ->and($send->json())->toBe($poll->json())
        ->and(ChatMessage::count())->toBe(1);
});

it('stops polling and sending on expiry and owner suspension', function (string $reason) {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag));

    match ($reason) {
        'expiry' => $tag->update(['expires_at' => now()->subMinute()]),
        'suspension' => $tag->car->user->forceFill(['suspended_at' => now()])->save(),
    };

    pollVisitor($tag, $cookie)->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    sendVisitor($tag, 'Yeni mesaj', $cookie)->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    expect(ChatMessage::count())->toBe(1);
})->with(['expiry', 'suspension']);

it('answers a stealthed car, an expired car and a blocked session identically', function () {
    $stealth = chatTag(['stealth_enabled' => true]);
    $expired = linkedCar(owner(), now()->subDay())->tag;
    $blockedTag = chatTag();
    $cookie = cookieOf(sendVisitor($blockedTag));
    app(ChatService::class)->block(ChatThread::sole());

    $answers = [
        sendVisitor($stealth, 'a', null, '10.9.9.1'),
        sendVisitor($expired, 'a', null, '10.9.9.2'),
        sendVisitor($blockedTag, 'a', $cookie),
        pollVisitor($blockedTag, $cookie),
    ];

    foreach ($answers as $answer) {
        $answer->assertForbidden();
        expect($answer->json())->toBe($answers[0]->json());
    }

    // The chat page gives one shared copy for every reason, and it is not the scan page's "tag not active" text.
    asVisitor(null, '10.9.9.3');
    $html = $this->get("/t/{$stealth->token}/chat", ['Accept-Language' => 'en'])->assertOk()->getContent();
    expect($html)->toContain('Chat is not available')->not->toContain('This tag is not active')
        ->and($this->get("/t/{$expired->token}/chat", ['Accept-Language' => 'en'])->getContent())->toBe($html);
    asVisitor($cookie);
    expect($this->get("/t/{$blockedTag->token}/chat", ['Accept-Language' => 'en'])->getContent())->toBe($html);

    // A cooldown IP on a live tag sees the same page.
    asVisitor(null, '10.9.9.9');
    $other = chatTag();
    sendVisitor($other, 'x', null, '10.9.9.9')->assertCreated();
    app(ChatService::class)->block(ChatThread::where('car_id', $other->car_id)->sole());
    asVisitor(null, '10.9.9.9');
    expect($this->get("/t/{$other->token}/chat", ['Accept-Language' => 'en'])->getContent())->toBe($html);
    $this->get("/t/{$stealth->token}/chat", ['Accept-Language' => 'tr'])->assertSee('Sohbet kullanılamıyor');
});

it('treats an unclaimed tag as unavailable and a malformed token as not found', function () {
    $free = tagFixture();
    asVisitor();

    $this->postJson("/t/{$free->token}/chat/messages", ['body' => 'Merhaba'])->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    $this->getJson('/t/not-a-token/chat/messages')->assertNotFound();
    $this->postJson('/t/ünï/chat/messages', ['body' => 'x'])->assertNotFound();
    expect(ChatThread::count())->toBe(0);
});

it('resumes the same thread after stealth turns off', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag, 'Ilk mesaj'));
    $tag->car->update(['stealth_enabled' => true]);
    pollVisitor($tag, $cookie)->assertForbidden();

    $tag->car->update(['stealth_enabled' => false]);
    sendVisitor($tag, 'Ikinci mesaj', $cookie)->assertCreated();

    pollVisitor($tag, $cookie)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.body', 'Ilk mesaj');
    expect(ChatThread::count())->toBe(1);
});

it('keeps a thread private to its cookie', function () {
    $tag = chatTag();
    $mine = cookieOf(sendVisitor($tag, 'Gizli mesajim'));
    $thread = ChatThread::sole();
    $ownerMessage = $thread->messages()->create(['sender' => 'owner', 'body' => 'Sahibin cevabi']);

    $other = cookieOf(sendVisitor($tag, 'Baska biri', null, '10.0.0.6'));
    $seen = pollVisitor($tag, $other, 0, '10.0.0.6')->assertOk();

    expect($seen->getContent())->not->toContain('Gizli mesajim')->not->toContain('Sahibin cevabi')
        ->and($seen->json('data'))->toHaveCount(1);
    pollVisitor($tag, null)->assertOk()->assertExactJson(['data' => []]);
    pollVisitor($tag, str_repeat('a', 40))->assertOk()->assertExactJson(['data' => []]);

    // Reporting a message outside the own thread is a 404, and the message stays untouched.
    asVisitor($other, '10.0.0.6');
    $this->postJson("/t/{$tag->token}/chat/messages/{$ownerMessage->id}/report")->assertNotFound();
    expect($ownerMessage->fresh()->report)->toBeNull();
    expect(pollVisitor($tag, $mine)->json('data'))->toHaveCount(2);
});

it('returns only id, sender, body, hidden and created_at', function () {
    $tag = chatTag();
    $created = sendVisitor($tag)->assertCreated();
    $cookie = cookieOf($created);
    ChatThread::sole()->messages()->create(['sender' => 'owner', 'body' => 'Tesekkurler']);

    expect(array_keys($created->json()))->toBe(['id', 'sender', 'body', 'hidden', 'created_at']);
    $poll = pollVisitor($tag, $cookie)->assertOk();
    foreach ($poll->json('data') as $row) {
        expect(array_keys($row))->toBe(['id', 'sender', 'body', 'hidden', 'created_at']);
    }
    $first = $poll->json('data.0.id');
    expect(pollVisitor($tag, $cookie, $first)->json('data'))->toHaveCount(1);
});

it('applies 10 messages per 10 minutes per session', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag, 'm1'));
    foreach (range(2, 10) as $i) {
        sendVisitor($tag, "m$i", $cookie)->assertCreated();
    }

    sendVisitor($tag, 'm11', $cookie)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    expect(ChatMessage::count())->toBe(10);

    $this->travel(11)->minutes();
    sendVisitor($tag, 'm11', $cookie)->assertCreated();
});

it('applies 3 new threads per hour per IP', function () {
    foreach (range(1, 3) as $i) {
        sendVisitor(chatTag(), 'Merhaba', null, '10.7.7.7')->assertCreated();
    }

    $fourth = chatTag();
    sendVisitor($fourth, 'Merhaba', null, '10.7.7.7')->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    sendVisitor($fourth, 'Merhaba', null, '10.7.7.8')->assertCreated();
    expect(ChatThread::count())->toBe(4);

    $this->travel(61)->minutes();
    sendVisitor(chatTag(), 'Merhaba', null, '10.7.7.7')->assertCreated();
});

it('refuses a blocked session', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag));
    app(ChatService::class)->block(ChatThread::sole());

    sendVisitor($tag, 'Hala burada', $cookie)->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    pollVisitor($tag, $cookie)->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    expect(ChatMessage::count())->toBe(1);
});

it('refuses a new session from a blocked IP on that tag for 24 hours and allows other tags', function () {
    $tag = chatTag();
    $other = chatTag();
    sendVisitor($tag, 'Ilk', null, '10.4.4.4')->assertCreated();
    app(ChatService::class)->block(ChatThread::sole());

    sendVisitor($tag, 'Yeni cerez', null, '10.4.4.4')->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    sendVisitor($other, 'Baska arac', null, '10.4.4.4')->assertCreated();
    sendVisitor($tag, 'Baska ag', null, '10.4.4.5')->assertCreated();

    $this->travel(24)->hours();
    $this->travel(1)->minutes();
    sendVisitor($tag, 'Gun sonra', null, '10.4.4.4')->assertCreated();
});

it('refuses new threads when paused and keeps existing ones working', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag, 'Pause oncesi'));
    $tag->car->update(['chats_paused' => true]);

    sendVisitor($tag, 'Yeni kisi', null, '10.5.5.5')->assertForbidden()
        ->assertExactJson(['code' => 'chats_paused', 'message' => __('wasla.error_chats_paused')]);
    sendVisitor($tag, 'Ayni kisi', $cookie)->assertCreated();
    pollVisitor($tag, $cookie)->assertOk()->assertJsonCount(2, 'data');
    expect(ChatThread::count())->toBe(1);

    // The page keeps its chat entry: new visitors get the paused notice, existing ones the form.
    asVisitor(null, '10.5.5.5');
    $this->get("/t/{$tag->token}/chat", ['Accept-Language' => 'en'])->assertOk()
        ->assertSee('not accepting new messages')->assertDontSee('<form', false);
    asVisitor($cookie);
    $this->get("/t/{$tag->token}/chat", ['Accept-Language' => 'en'])->assertOk()
        ->assertDontSee('not accepting new messages')->assertSee('<form', false);
    $this->get("/t/{$tag->token}")->assertSee('href="/t/'.$tag->token.'/chat"', false);
});

it('uses blacklist-only moderation when consent is required and declined', function () {
    config(['wasla.chat.consent_required' => true]);
    BlacklistTerm::create(['term' => 'zibidi', 'match_mode' => 'word']);
    $tag = chatTag();

    sendVisitor($tag, 'Merhaba', null, '10.6.6.1', ['consent' => false])->assertCreated();
    Http::assertNothingSent();

    sendVisitor($tag, 'zibidi', null, '10.6.6.2', ['consent' => false])->assertStatus(422)->assertJsonPath('code', 'message_blocked');
    Http::assertNothingSent();

    sendVisitor($tag, 'Merhaba', null, '10.6.6.3', ['consent' => true])->assertCreated();
    Http::assertSentCount(1);
});

it('sends to the provider by default without a consent field', function () {
    sendVisitor(chatTag(), 'Merhaba')->assertCreated();
    Http::assertSentCount(1);
});

it('shows no blocked message to the owner', function () {
    BlacklistTerm::create(['term' => 'zibidi', 'match_mode' => 'word']);
    $user = owner();
    $tag = linkedCar($user)->tag;

    sendVisitor($tag, 'zibidi')->assertStatus(422)->assertJsonPath('code', 'message_blocked');

    expect(ChatThread::count())->toBe(0)->and(ChatMessage::count())->toBe(0)
        ->and(ModerationEvent::where('kind', 'blocked')->count())->toBe(1);
    $this->getJson('/api/v1/chats', bearer($user))->assertOk()->assertJsonCount(0, 'data');

    // Refused attempts still count toward the 3 per hour IP limit.
    sendVisitor($tag, 'zibidi')->assertStatus(422);
    sendVisitor($tag, 'zibidi')->assertStatus(422);
    sendVisitor($tag, 'Merhaba')->assertStatus(429);
});

it('lets the visitor report an owner message and nothing else', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag));
    $thread = ChatThread::sole();
    $visitorMessage = $thread->messages()->sole();
    $ownerMessage = $thread->messages()->create(['sender' => 'owner', 'body' => 'Kotu bir mesaj']);

    asVisitor($cookie);
    $this->postJson("/t/{$tag->token}/chat/messages/{$visitorMessage->id}/report")->assertNotFound();
    $this->postJson("/t/{$tag->token}/chat/messages/{$ownerMessage->id}/report")->assertOk()->assertExactJson(['reported' => true]);

    $report = $ownerMessage->fresh()->report;
    expect($report->reporter_type)->toBe('visitor')
        ->and($report->reporter_visitor_session_id)->toBe($ownerMessage->thread->visitor_session_id)
        ->and($report->reporter_user_id)->toBeNull()
        ->and($visitorMessage->fresh()->report)->toBeNull();

    // Reporting again keeps the single report.
    $this->postJson("/t/{$tag->token}/chat/messages/{$ownerMessage->id}/report")->assertOk();
    expect(MessageReport::count())->toBe(1);
});

it('rejects empty, blank and over-long bodies', function (mixed $body) {
    $tag = chatTag();
    asVisitor();

    $this->postJson("/t/{$tag->token}/chat/messages", ['body' => $body])->assertStatus(422)->assertJsonValidationErrors('body');
    expect(ChatThread::count())->toBe(0);
})->with(['', "  \u{00A0} ", [str_repeat('a', 501)], null]);

it('fires ChatMessageCreated for both senders', function () {
    Event::fake([ChatMessageCreated::class]);
    $tag = chatTag();
    sendVisitor($tag)->assertCreated();
    app(ChatService::class)->send(ChatThread::sole(), 'owner', 'Cevap');

    Event::assertDispatchedTimes(ChatMessageCreated::class, 2);
    Event::assertDispatched(ChatMessageCreated::class, fn ($e) => $e->message->sender === 'visitor');
    Event::assertDispatched(ChatMessageCreated::class, fn ($e) => $e->message->sender === 'owner');
});

it('does not fire ChatMessageCreated for a blocked message', function () {
    Event::fake([ChatMessageCreated::class]);
    BlacklistTerm::create(['term' => 'zibidi', 'match_mode' => 'word']);
    sendVisitor(chatTag(), 'zibidi')->assertStatus(422);

    Event::assertNotDispatched(ChatMessageCreated::class);
});

it('puts the cooldown on the IP the blocked session last used', function () {
    $tag = chatTag();
    $cookie = cookieOf(sendVisitor($tag, 'Evden', null, '10.8.0.1'));
    sendVisitor($tag, 'Mobil veriden', $cookie, '10.8.0.2')->assertCreated();
    app(ChatService::class)->block(ChatThread::sole());

    sendVisitor($tag, 'Yeni cerez', null, '10.8.0.2')->assertForbidden()->assertJsonPath('code', 'chat_unavailable');
    sendVisitor($tag, 'Eski ag baska biri', null, '10.8.0.1')->assertCreated();
});

it('blocks a visitor message with a vowelled Arabic blacklist term and saves nothing', function () {
    BlacklistTerm::create(['term' => 'أحمق', 'language' => 'ar', 'match_mode' => 'word']);
    $tag = linkedCar(owner())->tag;

    sendVisitor($tag, 'انت أَحْـمَق')->assertStatus(422)->assertJsonPath('code', 'message_blocked');

    expect(ChatMessage::count())->toBe(0)->and(ModerationEvent::where('kind', 'blocked')->count())->toBe(1);
});
