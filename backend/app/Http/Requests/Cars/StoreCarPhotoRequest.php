<?php

namespace App\Http\Requests\Cars;

use Closure;
use Illuminate\Http\UploadedFile;

class StoreCarPhotoRequest extends OwnerRequest
{
    public function rules(): array
    {
        return [
            'photo' => [
                'required', 'file', 'max:10240',
                'mimetypes:image/jpeg,image/png,image/webp',
                // The bytes must decode as a real image, not just carry a magic number.
                function (string $attribute, mixed $value, Closure $fail) {
                    $info = $value instanceof UploadedFile ? @getimagesize($value->getRealPath()) : false;
                    if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                        $fail(__('validation.image', ['attribute' => $attribute]));
                    }
                },
            ],
        ];
    }
}
