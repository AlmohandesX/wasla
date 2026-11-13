<?php

namespace App\Services\Cars;

use App\Models\Car;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CarPhotoStore
{
    /** Stored files up to and including this size stay on the local private disk; larger ones go to object storage. */
    public const LOCAL_MAX_BYTES = 1048576;

    public function __construct(private readonly PhotoResizer $resizer) {}

    /** Every photo is stored resized and re-encoded as JPEG (see PhotoResizer), whatever was uploaded. */
    public function store(Car $car, UploadedFile $file): void
    {
        $bytes = $this->resizer->toJpeg($file->getRealPath());
        $disk = strlen($bytes) <= self::LOCAL_MAX_BYTES ? 'photos' : 's3';
        $path = 'car-photos/'.Str::random(40).'.jpg';

        // Disks run with throw=false, so a failed write returns false: nothing may change until it succeeds.
        if (Storage::disk($disk)->put($path, $bytes) === false) {
            throw new RuntimeException('Car photo could not be written to the '.$disk.' disk.');
        }

        $oldDisk = $car->photo_disk;
        $oldPath = $car->photo_path;
        try {
            $car->update(['photo_disk' => $disk, 'photo_path' => $path]);
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }

        if ($oldPath !== null && $oldDisk !== null) {
            Storage::disk($oldDisk)->delete($oldPath);
        }
    }

    /**
     * Removes one file. Disks run with throw=false, so a failed delete returns false rather than throwing;
     * both that and an exception count as failure, and the caller decides what to do about it.
     */
    public function deleteFile(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(Car $car): void
    {
        if ($car->photo_path !== null && $car->photo_disk !== null) {
            $this->deleteFile($car->photo_disk, $car->photo_path);
        }
        $car->update(['photo_disk' => null, 'photo_path' => null]);
    }
}
