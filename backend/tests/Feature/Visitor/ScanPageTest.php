<?php

use App\Models\EmergencyContact;
use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagClaimer;
use Carbon\CarbonImmutable;

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
    $this->get('/t/'.tagFixture()->token)->assertOk()->assertSee('Beni Bul')->assertDontSee('/chat');
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

it('opens in Turkish whatever the browser or the owner uses, and switches to English on request', function () {
    $tag = scanTag();
    $tag->car->user->update(['locale' => 'en']);

    foreach (['', 'en-GB,en;q=0.9', 'de', 'ar'] as $browser) {
        $this->get("/t/{$tag->token}", ['Accept-Language' => $browser])
            ->assertSee('Sahibine mesaj gönder')->assertSee('<html lang="tr">', false);
    }
    $this->get("/t/{$tag->token}?lang=en")->assertSee('Message the owner')->assertSee('<html lang="en">', false);
    $this->get("/t/{$tag->token}?lang=EN")->assertSee('Message the owner');
    $this->get("/t/{$tag->token}?lang=tr", ['Accept-Language' => 'en'])->assertSee('Sahibine mesaj gönder');
    $this->get("/t/{$tag->token}?lang=fr")->assertSee('Sahibine mesaj gönder');
    // The chat page's background requests carry the choice in a header instead.
    $this->get("/t/{$tag->token}", ['X-Wasla-Lang' => 'en'])->assertSee('Message the owner');
});

it('shows a TR | EN switch and keeps the choice on the way to the chat', function () {
    $tag = scanTag();

    $tr = $this->get("/t/{$tag->token}")->assertOk();
    $tr->assertSee('<a href="?lang=en"', false)->assertSee('<a href="?lang=tr"', false)
        ->assertSee('href="/t/'.$tag->token.'/chat"', false);
    expect($tr->getContent())->toMatch('/href="\?lang=tr"[^>]*aria-current="true"/');

    $en = $this->get("/t/{$tag->token}?lang=en")->assertOk();
    $en->assertSee('href="/t/'.$tag->token.'/chat?lang=en"', false);
    expect($en->getContent())->toMatch('/href="\?lang=en"[^>]*aria-current="true"/');

    $this->get("/t/{$tag->token}/chat?lang=en")->assertSee('href="/t/'.$tag->token.'?lang=en"', false);
    expect($tr->headers->getCookies())->toBeEmpty();
});

it('shows a quick status in the visitor language and an owner-typed status as written', function () {
    $tag = scanTag(['status_text' => 'Eczanedeyim.', 'status_key' => 'at_pharmacy', 'status_expires_at' => now()->addHour()]);

    $this->get("/t/{$tag->token}")->assertSee('Eczanedeyim.');
    $this->get("/t/{$tag->token}?lang=en")->assertSee('At the pharmacy.')->assertDontSee('Eczanedeyim.');

    $tag->car->update(['status_text' => 'Kapıdayım, 2 dk', 'status_key' => null]);
    $this->get("/t/{$tag->token}?lang=en")->assertSee('Kapıdayım, 2 dk');
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

    foreach (['?lang=en' => 'This tag is not active', '' => 'Bu etiket etkin değil', '?lang=tr' => 'Bu etiket etkin değil'] as $lang => $title) {
        $html = $this->get("/t/{$tag->token}{$lang}")->assertOk()->getContent();

        expect($html)->toContain($title)
            ->not->toContain('34 ABC 123')->not->toContain('Mehmet')->not->toContain('Yilmaz')
            ->not->toContain('Gizli')->not->toContain($tag->token)->not->toContain('5321234567')
            ->not->toContain('cannot be reached')->not->toContain('ulaşılamıyor');
    }
});

it('shows when the status runs out, in Istanbul time, with the time left', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 13:23:00', 'Europe/Istanbul'));
    $tag = scanTag(['status_text' => 'Eczanedeyim.', 'status_key' => 'at_pharmacy', 'status_expires_at' => now()->addMinutes(45)]);

    $this->get("/t/{$tag->token}?lang=en")->assertSee('Until 14:08 · 45 min left')
        ->assertSee('<time datetime="2026-11-09T14:08:00+03:00">', false);
    $this->get("/t/{$tag->token}")->assertSee('Bitiş: 14:08 · 45 dk kaldı');

    $tag->car->update(['status_expires_at' => now()->addMinutes(150)]);
    $this->get("/t/{$tag->token}?lang=en")->assertSee('Until 15:53 · 2 h 30 min left');
    $tag->car->update(['status_expires_at' => now()->addHours(2)]);
    $this->get("/t/{$tag->token}")->assertSee('Bitiş: 15:23 · 2 sa kaldı');

    // A part minute counts as a whole one, so it never reads "0 min".
    $tag->car->update(['status_expires_at' => now()->addSeconds(20)]);
    $this->get("/t/{$tag->token}?lang=en")->assertSee('1 min left');
});

it('adds the date when the status runs out on a later day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 22:00:00', 'Europe/Istanbul'));
    $tag = scanTag(['status_text' => 'Tatildeyim', 'status_expires_at' => now()->addDays(3)]);

    $this->get("/t/{$tag->token}?lang=en")->assertSee('Until 12 Nov, 22:00 · 3 days left');
    $this->get("/t/{$tag->token}")->assertSee('Bitiş: 12 Kas, 22:00 · 3 gün kaldı');
});

it('shows no end time without a status or once it has run out', function () {
    $tag = scanTag(['default_message' => 'Hemen donerim']);
    $this->get("/t/{$tag->token}?lang=en")->assertSee('Hemen donerim')->assertDontSee('left')->assertDontSee('class="until"', false);

    $tag->car->update(['status_text' => 'Market', 'status_expires_at' => now()->addMinutes(10)]);
    $this->travel(11)->minutes();
    $this->get("/t/{$tag->token}?lang=en")->assertSee('Hemen donerim')->assertDontSee('class="until"', false);
});
