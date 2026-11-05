<?php

use App\Models\Tag;
use App\Models\User;
use App\Models\Wave;
use App\Services\Tags\TokenGenerator;

function tagWave(): Wave
{
    return Wave::query()->firstOrCreate(
        ['number' => 1],
        ['size' => 10, 'status' => 'ready', 'created_by' => User::factory()->create(['role' => 'admin'])->id],
    );
}

function tagFixture(array $attrs = []): Tag
{
    static $serial = 0;
    $serial++;

    return Tag::create(array_merge([
        'wave_id' => tagWave()->id,
        'serial' => sprintf('W1-%05d-%s', $serial, bin2hex(random_bytes(2))),
        'token' => (new TokenGenerator)->generate(),
    ], $attrs));
}

function carAttrs(array $overrides = []): array
{
    return array_merge(['model' => 'Renault Clio', 'plate' => '34 ABC 123'], $overrides);
}
