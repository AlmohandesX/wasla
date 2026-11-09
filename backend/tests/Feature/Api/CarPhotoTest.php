<?php

use App\Models\Car;
use App\Services\Cars\PhotoResizer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

/**
 * Makes the resizer hand back exactly these sizes, one per upload, so the disk rule is tested on the stored size.
 * Bound before the first request: the controller, and the store in it, is reused for the rest of the test.
 */
function resizedTo(int ...$sizes): void
{
    app()->instance(PhotoResizer::class, new class($sizes) extends PhotoResizer
    {
        public function __construct(private array $sizes) {}

        public function toJpeg(string $path): string
        {
            return str_repeat('x', count($this->sizes) > 1 ? array_shift($this->sizes) : $this->sizes[0]);
        }
    });
}

/** A real image of the given size and type, written to a temporary upload. */
function photoOfDimensions(int $width, int $height, string $type = 'png', bool $transparent = false): UploadedFile
{
    $img = imagecreatetruecolor($width, $height);
    if ($transparent) {
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    } else {
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    }
    $path = tempnam(sys_get_temp_dir(), 'wph');
    match ($type) {
        'png' => imagepng($img, $path),
        'webp' => imagewebp($img, $path),
        default => imagejpeg($img, $path, 95),
    };
    $mime = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'][$type];

    return new UploadedFile($path, "car.$type", $mime, null, true);
}

it('keeps a stored file of exactly 1048576 bytes locally', function () {
    $user = owner();
    $car = linkedCar($user);
    resizedTo(1048576);

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();

    $car->refresh();
    expect($car->photo_disk)->toBe('photos');
    Storage::disk('photos')->assertExists($car->photo_path);
    Storage::disk('s3')->assertMissing($car->photo_path);
});

it('puts a stored file of 1048577 bytes in object storage', function () {
    $user = owner();
    $car = linkedCar($user);
    resizedTo(1048577);

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();

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
        ->and($path)->toMatch('/^car-photos\/[A-Za-z0-9]{40}\.jpg$/');
});

it('removes the old file when replacing', function () {
    $user = owner();
    $car = linkedCar($user);
    resizedTo(2000, 1048577);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $old = $car->refresh()->photo_path;

    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();

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

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('put')->once()->andReturnFalse();
    $failing->shouldNotReceive('delete');
    Storage::set('photos', $failing);

    $mine = bearer($user);
    app('auth')->forgetGuards();
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(3000)], $mine)->assertStatus(500);

    expect($car->refresh()->photo_path)->toBe($old)->and($car->photo_disk)->toBe('photos');
});

it('removes the photo file when the car is deleted', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $path = $car->refresh()->photo_path;
    Storage::disk('photos')->assertExists($path);

    $this->deleteJson("/api/v1/cars/{$car->id}", [], bearer($user))->assertNoContent();

    Storage::disk('photos')->assertMissing($path);
    expect(Car::find($car->id))->toBeNull();
});

it('still deletes the car and logs without the path when the photo file cannot be removed', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $path = $car->refresh()->photo_path;

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('delete')->once()->andReturnFalse();
    Storage::set('photos', $failing);
    Log::spy();

    $mine = bearer($user);
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/cars/{$car->id}", [], $mine)->assertNoContent();

    expect(Car::find($car->id))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($m, $c = []) => $c === ['disk' => 'photos'])->once();
    assertNotLogged($path);
});

it('keeps the photo file when the car delete fails', function () {
    $user = owner();
    $car = linkedCar($user);
    $this->postJson(photoUrl($car), ['photo' => photoOfSize(2000)], bearer($user))->assertOk();
    $path = $car->refresh()->photo_path;
    Car::deleting(fn () => throw new RuntimeException('db down'));

    $mine = bearer($user);
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/cars/{$car->id}", [], $mine)->assertStatus(500);

    Storage::disk('photos')->assertExists($path);
    expect(Car::find($car->id))->not->toBeNull();
});

it('scales a large photo down to 1024 px wide, keeping its 16:9 shape, and stores a compact JPEG', function (string $type) {
    $user = owner();
    $car = linkedCar($user);
    $upload = photoOfDimensions(4000, 2250, $type);

    $this->postJson(photoUrl($car), ['photo' => $upload], bearer($user))->assertOk();

    $stored = Storage::disk('photos')->get($car->refresh()->photo_path);
    $info = getimagesizefromstring($stored);
    expect([$info[0], $info[1], $info[2]])->toBe([1024, 576, IMAGETYPE_JPEG])
        ->and(strlen($stored))->toBeLessThan(200_000)
        ->and($car->photo_disk)->toBe('photos');
})->with(['png', 'jpg', 'webp']);

it('keeps the shape of a portrait photo and never enlarges a small one', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->postJson(photoUrl($car), ['photo' => photoOfDimensions(1500, 3000, 'jpg')], bearer($user))->assertOk();
    $info = getimagesizefromstring(Storage::disk('photos')->get($car->refresh()->photo_path));
    expect([$info[0], $info[1]])->toBe([512, 1024]);

    $this->postJson(photoUrl($car), ['photo' => photoOfDimensions(640, 360, 'jpg')], bearer($user))->assertOk();
    $info = getimagesizefromstring(Storage::disk('photos')->get($car->refresh()->photo_path));
    expect([$info[0], $info[1]])->toBe([640, 360]);
});

it('flattens a transparent PNG onto white', function () {
    $user = owner();
    $car = linkedCar($user);

    $this->postJson(photoUrl($car), ['photo' => photoOfDimensions(32, 18, 'png', transparent: true)], bearer($user))->assertOk();

    $img = imagecreatefromstring(Storage::disk('photos')->get($car->refresh()->photo_path));
    $rgb = imagecolorsforindex($img, imagecolorat($img, 16, 9));
    expect($rgb['red'])->toBeGreaterThan(245)->and($rgb['green'])->toBeGreaterThan(245)->and($rgb['blue'])->toBeGreaterThan(245);
});

it('refuses an image with too many pixels to resize safely', function () {
    $user = owner();
    $car = linkedCar($user);

    // 5100 x 5000 = 25.5 megapixels, in a small file.
    $this->postJson(photoUrl($car), ['photo' => photoOfDimensions(5100, 5000, 'png')], bearer($user))
        ->assertUnprocessable()->assertJsonValidationErrors('photo');
    expect($car->refresh()->photo_path)->toBeNull();
});
