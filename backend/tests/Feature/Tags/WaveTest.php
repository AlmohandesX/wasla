<?php

use App\Jobs\GenerateWaveJob;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wave;
use App\Services\Tags\TokenGenerator;
use App\Services\Tags\WaveService;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function waveAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function makeWave(int $size): Wave
{
    $wave = app(WaveService::class)->create($size, waveAdmin());

    return $wave->refresh();
}

beforeEach(function () {
    Storage::fake('local');
});

it('rejects a wave above 50000 and below 1', function () {
    Queue::fake();

    foreach ([0, -5, 50001] as $size) {
        expect(fn () => app(WaveService::class)->create($size, waveAdmin()))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(Wave::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues generation when a wave is created', function () {
    Queue::fake();

    $wave = app(WaveService::class)->create(5, waveAdmin());

    expect($wave->status)->toBe('generating')->and($wave->size)->toBe(5)->and($wave->number)->toBe(1);
    Queue::assertPushed(GenerateWaveJob::class, fn ($job) => $job->waveId === $wave->id);
});

it('generates exactly the requested count with unique tokens', function () {
    $wave = makeWave(200);

    expect($wave->status)->toBe('ready')
        ->and($wave->tags()->count())->toBe(200)
        ->and($wave->tags()->distinct()->count('token'))->toBe(200);
    $wave->tags()->pluck('token')->each(fn ($t) => expect(TokenGenerator::isWellFormed($t))->toBeTrue());
});

it('formats serials as W001-000123', function () {
    $wave = makeWave(130);

    expect($wave->tags()->orderBy('serial')->first()->serial)->toBe('W001-000001')
        ->and($wave->tags()->orderByDesc('serial')->first()->serial)->toBe('W001-000130');
    expect(Tag::where('serial', 'W001-000123')->exists())->toBeTrue();
});

it('builds a package whose CSV has one row per tag and a QR svg per serial', function () {
    config(['wasla.tag_base_url' => 'https://wasla.example/t']);
    $wave = makeWave(25);

    expect($wave->package_path)->not->toBeNull();
    Storage::disk('local')->assertExists($wave->package_path);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($wave->package_path)))->toBeTrue();

    $lines = array_values(array_filter(explode("\n", $zip->getFromName('tags.csv'))));
    expect($lines[0])->toBe('serial,url')->and($lines)->toHaveCount(26);

    $tags = $wave->tags()->get()->keyBy('serial');
    foreach (array_slice($lines, 1) as $line) {
        [$serial, $url] = explode(',', $line);
        expect($url)->toBe('https://wasla.example/t/'.$tags[$serial]->token);
        $svg = $zip->getFromName("qr/{$serial}.svg");
        expect($svg)->toBeString()->toContain('<svg');
    }
    expect($zip->numFiles)->toBe(26);
    $zip->close();
});

it('counts pending, sold and activated', function () {
    $wave = makeWave(10);
    $sold = $wave->tags()->orderBy('serial')->limit(3)->get();
    $wave->tags()->whereIn('id', $sold->pluck('id'))->update(['sold_at' => now()]);
    $wave->tags()->where('id', $sold->first()->id)->update(['first_activated_at' => now()]);

    expect(app(WaveService::class)->stats($wave))->toBe(['pending' => 7, 'sold' => 3, 'activated' => 1]);
});

it('marks a whole wave sold once', function () {
    $wave = makeWave(10);
    $other = Wave::create(['number' => 2, 'size' => 1, 'status' => 'ready']);
    $outsider = Tag::create(['wave_id' => $other->id, 'serial' => 'W002-000001', 'token' => (new TokenGenerator)->generate()]);

    expect(app(WaveService::class)->markSold($wave, waveAdmin()))->toBe(10)
        ->and(app(WaveService::class)->markSold($wave, waveAdmin()))->toBe(0)
        ->and($outsider->refresh()->sold_at)->toBeNull();
});

it('marks a serial range sold without touching other tags', function () {
    $wave = makeWave(10);

    $count = app(WaveService::class)->markSerialRangeSold('W001-000003', 'W001-000005', waveAdmin());

    expect($count)->toBe(3)
        ->and($wave->tags()->whereNotNull('sold_at')->orderBy('serial')->pluck('serial')->all())
        ->toBe(['W001-000003', 'W001-000004', 'W001-000005'])
        ->and($wave->tags()->whereNull('sold_at')->count())->toBe(7);
});

it('does not create duplicates if the job retries', function () {
    $wave = makeWave(30);

    (new GenerateWaveJob($wave->id))->handle(app(WaveService::class));
    (new GenerateWaveJob($wave->id))->handle(app(WaveService::class));

    expect($wave->tags()->count())->toBe(30)->and($wave->refresh()->status)->toBe('ready');
});

it('resumes from the highest existing sequence', function () {
    Queue::fake();
    $wave = app(WaveService::class)->create(5, waveAdmin());
    foreach ([1, 2] as $n) {
        Tag::create(['wave_id' => $wave->id, 'serial' => sprintf('W001-%06d', $n), 'token' => (new TokenGenerator)->generate()]);
    }

    app(WaveService::class)->generate($wave);

    expect($wave->tags()->orderBy('serial')->pluck('serial')->all())
        ->toBe(['W001-000001', 'W001-000002', 'W001-000003', 'W001-000004', 'W001-000005']);
});

it('sets the wave to failed when generation throws', function () {
    $wave = makeWave(5);
    $wave->tags()->delete();
    $wave->update(['status' => 'generating']);

    $this->app->bind(TokenGenerator::class, fn () => new class extends TokenGenerator
    {
        public function generate(): string
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => (new GenerateWaveJob($wave->id))->handle(app(WaveService::class)))
        ->toThrow(RuntimeException::class);
    expect($wave->refresh()->status)->toBe('failed');
});

it('sets the wave to failed when the job gives up', function () {
    Queue::fake();
    $wave = app(WaveService::class)->create(5, waveAdmin());

    (new GenerateWaveJob($wave->id))->failed(new RuntimeException('x'));

    expect($wave->refresh()->status)->toBe('failed');
});

it('prints QR codes that decode back to the tag URL', function () {
    $tag = tagFixture();
    $url = $tag->publicUrl();

    $png = (new QRCode(WaveService::qrOptions([
        'outputType' => QROutputInterface::GDIMAGE_PNG,
        'outputBase64' => false,
        'scale' => 8,
    ])))->render($url);

    expect((string) (new QRCode)->readFromBlob($png))->toBe($url);

    // The shipped SVG is produced by the same settings and is a real QR markup.
    expect((new QRCode(WaveService::qrOptions()))->render($url))->toContain('<svg');
});
