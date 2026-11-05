<?php

namespace App\Services\Account;

use App\Models\Car;
use App\Models\ChatThread;
use App\Models\Reminder;
use App\Models\Tag;
use App\Models\User;
use App\Services\Cars\CarPhotoStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountEraser
{
    public function __construct(private readonly CarPhotoStore $photos) {}

    /**
     * Erases the owner's personal data. Tags are unlinked with their dates and revenue fields untouched, so they
     * can be claimed again. Payments stay as financial records with only the user link cleared. Files go after
     * the database work has committed, and a file that will not delete never undoes the erasure.
     */
    public function erase(User $user): void
    {
        /** @var list<array{disk: string, path: string}> $files */
        $files = [];

        DB::transaction(function () use ($user, &$files) {
            User::query()->lockForUpdate()->findOrFail($user->id);

            $carIds = Car::query()->where('user_id', $user->id)->pluck('id');
            $threadIds = ChatThread::query()->whereIn('car_id', $carIds)->pluck('id');
            $reminderIds = Reminder::query()->whereIn('car_id', $carIds)->pluck('id');

            foreach (Car::query()->whereIn('id', $carIds)->whereNotNull('photo_path')->whereNotNull('photo_disk')->get() as $car) {
                $files[] = ['disk' => $car->photo_disk, 'path' => $car->photo_path];
            }

            // Lock the rows first, as claiming and transferring do, then unlink them.
            $tagIds = Tag::query()->whereIn('car_id', $carIds)->lockForUpdate()->pluck('id');
            Tag::query()->whereIn('id', $tagIds)->update(['car_id' => null]);

            // Moderation events keep message text: the owner's own, and visitor text in the owner's threads.
            DB::table('moderation_events')
                ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('thread_id', $threadIds))
                ->delete();
            DB::table('sent_notifications')->where('kind', 'reminder')->whereIn('subject_id', $reminderIds)->delete();

            DB::table('chat_messages')->whereIn('thread_id', $threadIds)->delete();
            DB::table('chat_threads')->whereIn('id', $threadIds)->delete();
            DB::table('emergency_contacts')->whereIn('car_id', $carIds)->delete();
            DB::table('reminders')->whereIn('car_id', $carIds)->delete();
            DB::table('cars')->whereIn('id', $carIds)->delete();

            DB::table('device_tokens')->where('user_id', $user->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Billing fields stay; only the link to the person goes.
            DB::table('payments')->where('user_id', $user->id)->update(['user_id' => null]);

            $user->delete();
        });

        $this->deleteFiles($files);
    }

    /** @param  list<array{disk: string, path: string}>  $files */
    private function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            if (! $this->photos->deleteFile($file['disk'], $file['path'])) {
                // The disk name only: never the path, which could name the car, and never an exception message.
                Log::warning('A car photo could not be deleted after account erasure.', ['disk' => $file['disk']]);
            }
        }
    }
}
