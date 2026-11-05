<?php

namespace App\Services\Tags;

use App\Models\Tag;

class TagTransfer
{
    /** Detaches the tag from its car. The car stays with its owner; dates are untouched. */
    public function unlink(Tag $tag): void
    {
        $tag->update(['car_id' => null]);
    }
}
