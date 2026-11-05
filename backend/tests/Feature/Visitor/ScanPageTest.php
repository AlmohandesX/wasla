<?php

use App\Models\EmergencyContact;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagClaimer;

beforeEach(fn () => $this->travelTo(now()->startOfSecond()));

const SEEDED_PHONE = '+905321234567';
const REVEAL_HEADERS = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

function scanTag(array $carAttrs = [], ?User $owner = null): Tag
{
    $tag = tagFixture();
    app(TagClaimer::class)->claim($owner ?? User::factory()->create(['name' => 'Mehmet Yilmaz']), $tag->token, carAttrs());
    $tag->refresh();
    if ($carAttrs) {
        $tag->car->update($carAttrs);
    }

    return $tag;
}

function scanContact(Tag $tag, string $label = 'Esim', string $phone = SEEDED_PHONE): EmergencyContact
{
    return EmergencyContact::create(['car_id' => $tag->car_id, 'label' => $label, 'phone' => $phone, 'position' => 0]);
}

function revealUrl(Tag $tag, EmergencyContact $c): string
{
    return "/t/{$tag->token}/contacts/{$c->id}/reveal";
}

it('shows the current message and a message button when active', function () {
    $tag = scanTag(['default_message' => 'Lutfen arayin, hemen geliyorum.']);
    scanContact($tag, 'Esim');

    $this->get("/t/{$tag->token}")
        ->assertOk()
        ->assertSee('Lutfen arayin, hemen geliyorum.')
        ->assertSee('Esim')
        ->assertSee('href="/t/'.$tag->token.'/chat"', false);
});

it('prefers an unexpired status over the default message', function () {
    $tag = scanTag(['default_message' => 'Varsayilan', 'status_text' => '10 dakikaya döneceğim', 'status_expires_at' => now()->addHour()]);

    $this->get("/t/{$tag->token}")->assertSee('10 dakikaya döneceğim')->assertDontSee('Varsayilan');
});

it('never renders plate, owner name or phone digits in the HTML', function () {
    $tag = scanTag();
    scanContact($tag);

    $html = $this->get("/t/{$tag->token}")->assertOk()->getContent();

    expect($html)->not->toContain('34 ABC 123')->not->toContain('34ABC123')
        ->not->toContain('Mehmet')->not->toContain('Yilmaz')
        ->not->toContain('5321234567')->not->toContain('532 123');
});

it('reveals a phone only for that tag contact and rate limits it', function () {
    $tag = scanTag();
    $mine = scanContact($tag);
    $other = scanContact(scanTag(), 'Baska', '+905550000000');

    $this->postJson(revealUrl($tag, $mine), [], REVEAL_HEADERS)->assertOk()->assertExactJson(['phone' => SEEDED_PHONE]);
    $this->postJson(revealUrl($tag, $other), [], REVEAL_HEADERS)->assertNotFound();

    // 2 used so far; 8 more allowed, then the 11th is refused.
    for ($i = 0; $i < 8; $i++) {
        $this->postJson(revealUrl($tag, $mine), [], REVEAL_HEADERS)->assertOk();
    }
    $this->postJson(revealUrl($tag, $mine), [], REVEAL_HEADERS)->assertStatus(429);
});

it('refuses reveal in a neutral state', function () {
    $tag = scanTag();
    $c = scanContact($tag);
    $tag->car->update(['stealth_enabled' => true]);

    $this->postJson(revealUrl($tag, $c), [], REVEAL_HEADERS)->assertNotFound();
});

it('refuses reveal for a malformed or unknown token', function () {
    $this->postJson('/t/short/contacts/1/reveal', [], REVEAL_HEADERS)->assertNotFound();
    $this->postJson('/t/'.str_repeat('a', 16).'/contacts/1/reveal', [], REVEAL_HEADERS)->assertNotFound();
});

it('refuses a forged cross-site reveal', function () {
    $tag = scanTag();
    $c = scanContact($tag);

    $this->post(revealUrl($tag, $c))->assertForbidden();
    $this->postJson(revealUrl($tag, $c), [], REVEAL_HEADERS + ['Origin' => 'https://evil.example'])->assertForbidden();
    $this->postJson(revealUrl($tag, $c), [], REVEAL_HEADERS + ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
    $this->postJson(revealUrl($tag, $c), [], REVEAL_HEADERS + ['Origin' => rtrim(config('app.url'), '/')])->assertOk();
});

it('renders byte-identical neutral pages', function () {
    $expired = scanTag();
    $expired->update(['expires_at' => now()->subDay()]);
    $stealth = scanTag();
    $stealth->car->update(['stealth_enabled' => true]);
    $revoked = scanTag();
    $revoked->update(['revoked_at' => now()]);
    $suspended = scanTag();
    $suspended->car->user->forceFill(['suspended_at' => now()])->save();

    $pages = collect([$expired, $stealth, $revoked, $suspended])->map(function (Tag $t) {
        $r = $this->get("/t/{$t->token}");
        $r->assertOk();

        return $r->getContent();
    });

    expect($pages->unique()->count())->toBe(1)
        ->and($pages->first())->not->toContain('/chat');
});

it('sets no cookie on neutral and unclaimed pages', function () {
    $stealth = scanTag();
    $stealth->car->update(['stealth_enabled' => true]);

    foreach ([$stealth, tagFixture()] as $tag) {
        $r = $this->get("/t/{$tag->token}")->assertOk();
        expect($r->headers->getCookies())->toBe([])->and($r->headers->has('Set-Cookie'))->toBeFalse();
    }
});

it('explains how to claim an unclaimed tag', function () {
    $this->get('/t/'.tagFixture()->token)->assertOk()->assertSee('WASLA')->assertDontSee('/chat');
});

it('returns 404 for an unknown or malformed token', function () {
    $this->get('/t/'.str_repeat('a', 16))->assertNotFound();
    $this->get('/t/short')->assertNotFound();
    $this->get('/t/'.rawurlencode(str_repeat('ş', 16)))->assertNotFound();
});

it('sends noindex', function () {
    $r = $this->get('/t/'.scanTag()->token)->assertOk();
    expect($r->headers->get('X-Robots-Tag'))->toContain('noindex');
    $r->assertSee('<meta name="robots" content="noindex, nofollow">', false);

    $this->get('/t/'.tagFixture()->token)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('uses Turkish by default and English on Accept-Language en', function () {
    $tag = scanTag();

    $this->get("/t/{$tag->token}", ['Accept-Language' => ''])->assertSee('Sahibine mesaj gönder')->assertSee('<html lang="tr">', false);
    $this->get("/t/{$tag->token}", ['Accept-Language' => 'en-GB,en;q=0.8'])
        ->assertSee('Message the owner')->assertSee('<html lang="en">', false);
    $this->get("/t/{$tag->token}", ['Accept-Language' => 'de'])->assertSee('Sahibine mesaj gönder');
});

it('escapes owner text', function () {
    $tag = scanTag(['default_message' => '<script>alert(1)</script>']);
    scanContact($tag, '<b>Esim</b>');

    $html = $this->get("/t/{$tag->token}")->getContent();
    expect($html)->not->toContain('<script>alert(1)</script>')->not->toContain('<b>Esim</b>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('rate limits scan page requests', function () {
    $tag = scanTag();
    for ($i = 0; $i < 60; $i++) {
        $this->get("/t/{$tag->token}")->assertOk();
    }
    $this->get("/t/{$tag->token}")->assertStatus(429);
});

it('shows only the plain neutral copy and no tag data on a neutral page', function () {
    $tag = scanTag(['default_message' => 'Gizli mesaj XYZ', 'status_text' => 'Gizli durum QRS', 'status_expires_at' => now()->addHour()]);
    scanContact($tag, 'Gizli Etiket');
    $tag->car->update(['stealth_enabled' => true]);

    foreach (['en-GB,en;q=0.8' => 'This tag is not active', '' => 'Bu etiket etkin değil'] as $lang => $title) {
        $html = $this->get("/t/{$tag->token}", ['Accept-Language' => $lang])->assertOk()->getContent();

        expect($html)->toContain($title)
            ->not->toContain('34 ABC 123')->not->toContain('Mehmet')->not->toContain('Yilmaz')
            ->not->toContain('Gizli')->not->toContain($tag->token)->not->toContain('5321234567')
            ->not->toContain('cannot be reached')->not->toContain('ulaşılamıyor');
    }
});
