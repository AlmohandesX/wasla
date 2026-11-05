<?php

namespace Database\Seeders;

use App\Models\BlacklistTerm;
use App\Services\Moderation\TextNormalizer;
use Illuminate\Database\Seeder;

/**
 * Starter profanity and slur list for the harassment filter (Turkish and English).
 *
 * Terms are normalized like incoming text (ASCII-folded, substitutions undone, repeats
 * collapsed), so write them naturally. "word" matches whole words only and is used where the
 * term is also a prefix or part of an ordinary word (for example "amca" must never match);
 * "substring" is used only for long, unambiguous stems.
 *
 * NOT FINAL: a native Turkish speaker and a native English speaker must review this list
 * before launch, and the false-positive risks noted below need a decision.
 */
class BlacklistSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const TERMS = [
        'substring' => [
            // Turkish (long, unambiguous stems)
            'orospu', 'siktir', 'sikeyim', 'sikerim', 'amina koy', 'aminakoy', 'amcik', 'yarrak', 'dalyarak',
            'pezevenk', 'serefsiz', 'gotveren', 'got oglani', 'ibne', 'kahpe', 'kaltak', 'yavsak',
            'gerizekali', 'geri zekali', 'gavat',
            // English
            'fuck', 'bitch', 'asshole', 'dickhead', 'bullshit', 'cocksucker', 'wanker', 'faggot', 'whore',
        ],
        'word' => [
            // Turkish short forms and stems that sit inside ordinary words. KNOWN FALSE POSITIVE:
            // "sikik" also matches "sıkık" (bored) because ı folds to i. Native review needed.
            'amk', 'aq', 'sikik', 'pic', 'gotlek', 'pust', 'sicayim',
            // English. "cunt" is word-mode to avoid the Scunthorpe problem.
            'cunt', 'cunts', 'shit', 'shitty', 'dick', 'pussy', 'slut', 'bastard', 'retard', 'retarded',
            'nigger', 'nigga', 'tranny', 'kike', 'spic', 'chink', 'twat', 'prick',
        ],
    ];

    public function run(): void
    {
        $normalizer = new TextNormalizer;

        foreach (self::TERMS as $mode => $terms) {
            foreach ($terms as $term) {
                $exists = BlacklistTerm::query()
                    ->where('normalized', $normalizer->normalize($term))
                    ->where('match_mode', $mode)
                    ->exists();

                if (! $exists) {
                    BlacklistTerm::create(['term' => $term, 'match_mode' => $mode, 'active' => true]);
                }
            }
        }
    }
}
