<?php

use App\Services\Moderation\TextNormalizer;

it('handles Turkish casing', function (string $input) {
    expect((new TextNormalizer)->normalize($input))->toBe('kotukelime');
})->with(['KÖTÜKELİME', 'KÖTÜKELIME', 'kötükelime']);

it('undoes common substitutions', function (string $input) {
    expect((new TextNormalizer)->normalize($input))->toBe('kotukelime');
})->with(['k0tukel1me']);

it('folds @ to a', function () {
    expect((new TextNormalizer)->normalize('s@lak'))->toBe('salak')
        ->and((new TextNormalizer)->normalize('@mk'))->toBe('amk');
});

it('removes zero-width characters', function () {
    expect((new TextNormalizer)->normalize("k\u{200B}ö\u{200D}tü\u{FEFF}kelime"))->toBe('kotukelime');
});

it('collapses repeated letters', function () {
    expect((new TextNormalizer)->normalize('kotuuuukelime'))->toBe('kotukelime');
});
