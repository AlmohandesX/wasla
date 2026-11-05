<?php

use App\Models\Car;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('photos');
    Storage::fake('s3');
});

/** A real PNG padded after IEND to exactly $bytes, so the size is genuine, not reported. */
function photoOfSize(int $bytes, string $name = 'client-name.png'): UploadedFile
{
    $img = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    $path = tempnam(sys_get_temp_dir(), 'wph');
    file_put_contents($path, str_pad($png, $bytes, "\0"));

    return new UploadedFile($path, $name, 'image/png', null, true);
}

function photoUrl(Car $car): string
{
    return "/api/v1/cars/{$car->id}/photo";
}

it('stores a file of exactly 1048576 bytes locally', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(1048576)], bearer($user))->assertOk();

    $car->refresh();
    expect($car->photo_disk)->toBe('photos');
    Storage::disk('photos')->assertExists($car->photo_path);
    Storage::disk('s3')->assertMissing($car->photo_path);
});

it('stores a file of 1048577 bytes in object storage', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(1048577)], bearer($user))->assertOk();

    $car->refresh();
    expect($car->photo_disk)->toBe('s3');
    Storage::disk('s3')->assertExists($car->photo_path);
    Storage::disk('photos')->assertMissing($car->photo_path);
});

it('rejects a PHP file renamed to .jpg', function () {
    $user = owner();
    $car = linkedCar($user);
    $file = UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo "pwned";');

    $this->postJson(photoUrl($car), ['photo' => $file], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect($car->refresh()->photo_path)->toBeNull();
});

it('rejects a file above 10 MB', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(10 * 1024 * 1024 + 1)], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
});

it('generates a random filename, never the client name', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000, 'my-secret-plate-34ABC123.png')], bearer($user))->assertOk();

    $path = $car->refresh()->photo_path;
    expect($path)->not->toContain('secret')->not->toContain('34ABC123')
        ->and($path)->toMatch('/^car-photos\/[A-Za-z0-9]{40}\.png$/');
});

it('removes the old file when replacing', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $old = $car->refresh()->photo_path;

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(1048577)], bearer($user))->assertOk();

    Storage::disk('photos')->assertMissing($old);
    Storage::disk('s3')->assertExists($car->refresh()->photo_path);
});

it('keeps photos off public URLs', function () {
    $user = owner();
    $other = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $path = $car->refresh()->photo_path;

    // The test app reuses its resolved guard between requests; forget it so each token is read afresh.
    $as = function (callable $request) {
        app('auth')->forgetGuards();

        return $request();
    };
    $theirs = bearer($other);
    $as(fn () => $this->getJson(photoUrl($car), $theirs))->assertForbidden();
    $as(fn () => $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], $theirs))->assertForbidden();
    $as(fn () => $this->deleteJson(photoUrl($car), [], $theirs))->assertForbidden();
    $as(fn () => $this->getJson(photoUrl($car)))->assertUnauthorized();
    expect(Storage::disk('photos')->exists($path))->toBeTrue();
    $mine = bearer($user);
    $as(fn () => $this->getJson(photoUrl($car), $mine))->assertOk();

    expect(config('filesystems.disks.photos.root'))->toBe(storage_path('app/private/photos'))
        ->and(config('filesystems.disks.photos.visibility'))->not->toBe('public')
        ->and(config('filesystems.disks.photos.url'))->toBeNull()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();
    expect(json_encode($as(fn () => $this->getJson("/api/v1/cars/{$car->id}", $mine))->json()))->not->toContain($path);
});

it('deletes the photo and 404s afterwards', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $path = $car->refresh()->photo_path;

    $this->deleteJson(photoUrl($car), [], bearer($user))->assertNoContent();

    Storage::disk('photos')->assertMissing($path);
    expect($car->refresh()->photo_path)->toBeNull()->and($car->photo_disk)->toBeNull();
    $this->getJson(photoUrl($car), bearer($user))->assertNotFound();
});

it('blocks photo writes on an expired car', function () {
    $user = owner();
    $car = linkedCar($user, now()->subDay());

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))
        ->assertStatus(402)->assertJsonPath('code', 'subscription_expired');
});

it('keeps the old photo and does not return 200 when the storage write fails', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $old = $car->refresh()->photo_path;

    $failing = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $failing->shouldReceive('put')->once()->andReturnFalse();
    $failing->shouldNotReceive('delete');
    Storage::set('photos', $failing);

    $mine = bearer($user);
    app('auth')->forgetGuards();
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(3000)], $mine)->assertStatus(500);

    expect($car->refresh()->photo_path)->toBe($old)->and($car->photo_disk)->toBe('photos');
});
