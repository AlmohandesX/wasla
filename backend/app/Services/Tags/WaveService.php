<?php

namespace App\Services\Tags;

use App\Exceptions\AdminActionException;
use App\Jobs\GenerateWaveJob;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wave;
use App\Services\Concerns\AuditsAdminActions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class WaveService
{
    use AuditsAdminActions;

    public const MAX_SIZE = 50000;

    private const INSERT_BATCH = 1000;

    /**
     * QR SVGs (about 30 KB each) and stickers (about 55 KB each) are staged as files and added to the zip in chunks of this size. Each chunk closes the
     * zip, which rewrites it, so a 50k wave rewrites it 10 times; staging on disk keeps memory flat whatever the size.
     */
    private const PACKAGE_CHUNK = 5000;

    public function create(int $size, User $admin): Wave
    {
        if ($size < 1 || $size > self::MAX_SIZE) {
            throw new InvalidArgumentException('Wave size must be between 1 and '.self::MAX_SIZE.'.');
        }

        $wave = DB::transaction(function () use ($size, $admin) {
            $wave = new Wave;
            $wave->number = (int) Wave::query()->lockForUpdate()->max('number') + 1;
            $wave->size = $size;
            $wave->status = 'generating';
            $wave->created_by = $admin->id;
            $wave->save();

            return $wave;
        });

        GenerateWaveJob::dispatch($wave->id)->afterCommit();

        return $wave;
    }

    /** Idempotent: resumes from the highest existing serial, then rebuilds the package. */
    public function generate(Wave $wave): void
    {
        try {
            $this->insertMissingTags($wave);
            $path = $this->buildPackage($wave);

            $wave->status = 'ready';
            $wave->package_path = $path;
            $wave->save();
        } catch (Throwable $e) {
            $wave->status = 'failed';
            $wave->save();

            throw $e;
        }
    }

    public function markSold(Wave $wave, User $admin): int
    {
        $count = Tag::query()->where('wave_id', $wave->id)->whereNull('sold_at')
            ->update(['sold_at' => now()]);

        $this->audit($admin, 'wave.mark_sold', 'wave', $wave->id, 'mark sold', ['count' => $count]);

        return $count;
    }

    /**
     * Marks the tags between two serials of one wave sold. Serials sort as text, which is only right inside a wave
     * (the sequence is fixed width), so a malformed, reversed or cross-wave range is refused rather than matching nothing.
     *
     * @throws AdminActionException
     */
    public function markSerialRangeSold(string $fromSerial, string $toSerial, User $admin): int
    {
        $from = strtoupper(trim($fromSerial));
        $to = strtoupper(trim($toSerial));

        $pattern = '/^(W\d{3,})-(\d{6})$/D';
        if (! preg_match($pattern, $from, $a) || ! preg_match($pattern, $to, $b)) {
            throw AdminActionException::because('serial_invalid');
        }
        if ($a[1] !== $b[1]) {
            throw AdminActionException::because('serial_range_waves');
        }
        if ($a[2] > $b[2]) {
            throw AdminActionException::because('serial_range_order');
        }

        $count = Tag::query()->whereBetween('serial', [$from, $to])->whereNull('sold_at')
            ->update(['sold_at' => now()]);

        $this->audit($admin, 'wave.mark_range_sold', 'tag_range', $admin->id, 'mark range sold', ['from' => $from, 'to' => $to, 'count' => $count]);

        return $count;
    }

    /** @return array{pending:int,sold:int,activated:int} */
    public function stats(Wave $wave): array
    {
        $tags = fn () => Tag::query()->where('wave_id', $wave->id);

        return [
            'pending' => $tags()->whereNull('sold_at')->count(),
            'sold' => $tags()->whereNotNull('sold_at')->count(),
            'activated' => $tags()->whereNotNull('first_activated_at')->count(),
        ];
    }

    public static function serial(Wave $wave, int $sequence): string
    {
        return sprintf('W%03d-%06d', $wave->number, $sequence);
    }

    private function insertMissingTags(Wave $wave): void
    {
        $tokens = app(TokenGenerator::class);
        $last = Tag::query()->where('wave_id', $wave->id)->max('serial');
        $sequence = $last === null ? 0 : (int) substr($last, strrpos($last, '-') + 1);

        while ($sequence < $wave->size) {
            $now = now();
            $rows = [];
            for ($i = 0; $i < self::INSERT_BATCH && $sequence < $wave->size; $i++) {
                $sequence++;
                $rows[] = [
                    'wave_id' => $wave->id,
                    'serial' => self::serial($wave, $sequence),
                    'token' => $tokens->generate(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Tag::query()->insert($rows);
        }
    }

    /** The QR settings used for every printed tag; tests render the same settings to a bitmap and decode them. */
    public static function qrOptions(array $overrides = []): QROptions
    {
        return new QROptions($overrides + [
            'eccLevel' => EccLevel::M,
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => true,
        ]);
    }

    /** Streams the zip onto the private disk and returns its path relative to that disk. */
    private function buildPackage(Wave $wave): string
    {
        $disk = Storage::disk('local');
        $relative = sprintf('waves/wave-%03d.zip', $wave->number);
        $disk->makeDirectory('waves');
        $final = $disk->path($relative);
        $workZip = $final.'.part';
        $csvPath = $final.'.csv.part';
        $stage = $final.'.svg.part';
        @unlink($workZip);
        $this->removeStage($stage);
        if (! @mkdir($stage, 0700, true)) {
            throw new RuntimeException('Cannot create the wave staging directory.');
        }

        $qrOptions = self::qrOptions();
        $stickers = app(StickerRenderer::class);

        $csv = fopen($csvPath, 'wb');
        fwrite($csv, "serial,url\n");

        try {
            Tag::query()->where('wave_id', $wave->id)->select(['id', 'serial', 'token'])
                ->chunkById(self::PACKAGE_CHUNK, function ($tags) use ($qrOptions, $stickers, $csv, $workZip, $stage) {
                    $zip = new ZipArchive;
                    if ($zip->open($workZip, ZipArchive::CREATE) !== true) {
                        throw new RuntimeException('Cannot open the wave package for writing.');
                    }
                    foreach ($tags as $tag) {
                        $url = $tag->publicUrl();
                        fwrite($csv, $tag->serial.','.$url."\n");
                        $qr = (new QRCode($qrOptions))->render($url);
                        $file = $stage.'/'.$tag->serial.'.svg';
                        $stickerFile = $stage.'/'.$tag->serial.'.sticker.svg';
                        if (file_put_contents($file, $qr) === false
                            || file_put_contents($stickerFile, $stickers->render($qr)) === false) {
                            throw new RuntimeException('Cannot stage a QR code.');
                        }
                        $zip->addFile($file, "qr/{$tag->serial}.svg");
                        // Print-ready 70 x 70 mm sticker with bleed and die line (resources/stickers/sticker-70.svg).
                        $zip->addFile($stickerFile, "stickers/{$tag->serial}.svg");
                    }
                    // The staged files are read during the close, so they can only go after it.
                    if (! $zip->close()) {
                        throw new RuntimeException('Cannot write the wave package.');
                    }
                    $this->removeStage($stage, keepDirectory: true);
                });
            fclose($csv);

            $zip = new ZipArchive;
            if ($zip->open($workZip, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Cannot open the wave package for writing.');
            }
            $zip->addFile($csvPath, 'tags.csv');
            if (! $zip->close()) {
                throw new RuntimeException('Cannot write the wave package.');
            }
            @unlink($final);
            if (! rename($workZip, $final)) {
                throw new RuntimeException('Cannot store the wave package.');
            }
        } finally {
            if (is_resource($csv)) {
                fclose($csv);
            }
            @unlink($csvPath);
            @unlink($workZip);
            $this->removeStage($stage);
        }

        return $relative;
    }

    private function removeStage(string $stage, bool $keepDirectory = false): void
    {
        foreach (glob($stage.'/*.svg') ?: [] as $file) {
            @unlink($file);
        }
        if (! $keepDirectory) {
            @rmdir($stage);
        }
    }
}
