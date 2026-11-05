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
    /** Files up to and including this size stay on the local private disk; larger ones go to object storage. */
    public const LOCAL_MAX_BYTES = 1048576;

    public function store(Car $car, UploadedFile $file): void
    {
        $disk = $file->getSize() <= self::LOCAL_MAX_BYTES ? 'photos' : 's3';
        // Extension comes from the detected content, never from the client's name.
        $ext = match (getimagesize($file->getRealPath())[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => 'jpg',
        };
        $path = 'car-photos/'.Str::random(40).'.'.$ext;

        // Disks run with throw=false, so a failed write returns false: nothing may change until it succeeds.
        if (Storage::disk($disk)->put($path, $file->get()) === false) {
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

    public function delete(Car $car): void
    {
        if ($car->photo_path !== null && $car->photo_disk !== null) {
            Storage::disk($car->photo_disk)->delete($car->photo_path);
        }
        $car->update(['photo_disk' => null, 'photo_path' => null]);
    }
}
