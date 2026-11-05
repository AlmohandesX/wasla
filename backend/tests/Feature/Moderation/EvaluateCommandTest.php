<?php

use App\Models\BlacklistTerm;
use App\Models\ModerationEvent;
use App\Services\Moderation\ModerationProvider;
use App\Services\Moderation\ProviderResult;
use Database\Seeders\BlacklistSeeder;
use Illuminate\Support\Facades\Cache;

function fakeModerationProvider(array $scoresByText): void
{
    app()->instance(ModerationProvider::class, new class($scoresByText) implements ModerationProvider
    {
        public function __construct(private array $scoresByText) {}

        public function check(string $text): ProviderResult
        {
            return new ProviderResult($this->scoresByText[$text] ?? ['harassment' => 0.01]);
        }
    });
}

function labelledCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'mod');
    file_put_contents($path, $body);

    return $path;
}

beforeEach(function () {
    config(['wasla.moderation.threshold' => 0.7, 'wasla.moderation.thresholds' => [], 'wasla.moderation.daily_cap' => 5000]);
    Cache::flush();
});

it('reports wrong results for a labelled CSV', function () {
    BlacklistTerm::create(['term' => 'siktir', 'match_mode' => 'substring']);
    fakeModerationProvider(['sen aptalsin' => ['harassment' => 0.2], 'seni gebertirim' => ['harassment' => 0.9]]);

    $path = labelledCsv("text,expected\n\"siktir git, burada park etme\",block\nseni gebertirim,block\nsen aptalsin,block\narabanız yolu kapatıyor,allow\n");

    $this->artisan('wasla:evaluate-moderation', ['file' => $path])
        ->expectsOutputToContain('Rows: 4 (3 expected block, 1 expected allow)')
        ->expectsOutputToContain('Block rate on expected-block rows: 2/3')
        ->expectsOutputToContain('Block rate on expected-allow rows: 0/1')
        ->expectsOutputToContain('WRONG expected=block got=allow: sen aptalsin')
        ->assertExitCode(0);

    unlink($path);
});

it('flags a false positive on an ordinary message', function () {
    fakeModerationProvider(['arabanız yolu kapatıyor' => ['harassment' => 0.95]]);
    $path = labelledCsv("text,expected\narabanız yolu kapatıyor,allow\n");

    $this->artisan('wasla:evaluate-moderation', ['file' => $path])
        ->expectsOutputToContain('WRONG expected=allow got=block: arabanız yolu kapatıyor')
        ->assertExitCode(0);

    unlink($path);
});

it('writes no moderation events and spends no daily cap', function () {
    BlacklistTerm::create(['term' => 'siktir', 'match_mode' => 'substring']);
    fakeModerationProvider(['seni gebertirim' => ['harassment' => 0.9]]);
    $path = labelledCsv("text,expected\nsiktir,block\nseni gebertirim,block\n");

    $this->artisan('wasla:evaluate-moderation', ['file' => $path])->assertExitCode(0);

    expect(ModerationEvent::count())->toBe(0)
        ->and(Cache::get('moderation:calls:'.now(config('wasla.display_timezone'))->toDateString()))->toBeNull();

    unlink($path);
});

it('fails on a missing file or an unknown label', function () {
    $this->artisan('wasla:evaluate-moderation', ['file' => '/nonexistent.csv'])->assertExitCode(1);

    $path = labelledCsv("text,expected\nhello,maybe\n");
    $this->artisan('wasla:evaluate-moderation', ['file' => $path])->assertExitCode(1);
    unlink($path);
});

it('ships a starter Turkish set that parses', function () {
    $this->seed(BlacklistSeeder::class);
    fakeModerationProvider([]);

    $this->artisan('wasla:evaluate-moderation', ['file' => base_path('tests/Fixtures/moderation/tr-sample.csv')])
        ->expectsOutputToContain('NATIVE-SPEAKER SIGN-OFF REQUIRED')
        ->assertExitCode(0);
});
