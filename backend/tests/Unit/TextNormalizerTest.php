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

it('ignores Arabic short vowels, shadda and the tatweel stretch', function (string $input) {
    expect((new TextNormalizer)->normalize($input))->toBe((new TextNormalizer)->normalize('كلب'));
})->with(['كَلْب', 'كـلـب', 'كــــلب', 'كَلّب']);

it('folds Arabic alef, ya, ta marbuta and hamza-seat variants to one form', function () {
    $n = new TextNormalizer;

    expect($n->normalize('أحمق'))->toBe($n->normalize('احمق'))
        ->and($n->normalize('إحمق'))->toBe($n->normalize('احمق'))
        ->and($n->normalize('آحمق'))->toBe($n->normalize('احمق'))
        ->and($n->normalize('حقيرة'))->toBe($n->normalize('حقيره'))
        ->and($n->normalize('مستوى'))->toBe($n->normalize('مستوي'))
        // Alef written as bare alef plus a combining hamza ends up the same as the precomposed letter.
        ->and($n->normalize("ا\u{0654}حمق"))->toBe($n->normalize('أحمق'));
});
