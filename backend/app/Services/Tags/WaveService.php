<?php

namespace App\Services\Tags;

use App\Jobs\GenerateWaveJob;
use App\Models\Tag;
use App\Models\User;
use App\Models\Wave;
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
    public const MAX_SIZE = 50000;

    private const INSERT_BATCH = 1000;

    /** QR SVGs are written to the zip in chunks of this size, which bounds memory. */
    private const PACKAGE_CHUNK = 250;

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

    public function markSold(Wave $wave): int
    {
        return Tag::query()->where('wave_id', $wave->id)->whereNull('sold_at')
            ->update(['sold_at' => now()]);
    }

    public function markSerialRangeSold(string $fromSerial, string $toSerial): int
    {
        return Tag::query()->whereBetween('serial', [$fromSerial, $toSerial])->whereNull('sold_at')
            ->update(['sold_at' => now()]);
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
        @unlink($workZip);

        $qrOptions = self::qrOptions();

        $csv = fopen($csvPath, 'wb');
        fwrite($csv, "serial,url\n");

        try {
            Tag::query()->where('wave_id', $wave->id)->select(['id', 'serial', 'token'])
                ->chunkById(self::PACKAGE_CHUNK, function ($tags) use ($qrOptions, $csv, $workZip) {
                    $zip = new ZipArchive;
                    if ($zip->open($workZip, ZipArchive::CREATE) !== true) {
                        throw new RuntimeException('Cannot open the wave package for writing.');
                    }
                    foreach ($tags as $tag) {
                        $url = $tag->publicUrl();
                        fwrite($csv, $tag->serial.','.$url."\n");
                        $zip->addFromString("qr/{$tag->serial}.svg", (new QRCode($qrOptions))->render($url));
                    }
                    if (! $zip->close()) {
                        throw new RuntimeException('Cannot write the wave package.');
                    }
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
        }

        return $relative;
    }
}
