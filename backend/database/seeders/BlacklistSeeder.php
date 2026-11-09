<?php

namespace Database\Seeders;

use App\Models\BlacklistTerm;
use App\Services\Moderation\TextNormalizer;
use Illuminate\Database\Seeder;

/**
 * Starter profanity and slur list for the harassment filter, grouped by language (Turkish, English, Arabic).
 *
 * Terms are normalized like incoming text (ASCII-folded, substitutions undone, repeats
 * collapsed), so write them naturally. "word" matches whole words only and is used where the
 * term is also a prefix or part of an ordinary word (for example "amca" must never match);
 * "prefix" matches a word that starts with the term, so suffixed forms are caught;
 * "substring" is used only for long, unambiguous stems.
 *
 * NOT FINAL: a native Turkish speaker and a native English speaker must review this list
 * before launch, and the false-positive risks noted below need a decision.
 */
class BlacklistSeeder extends Seeder
{
    /** @var array<'ar'|'en'|'tr', array<'word'|'prefix'|'substring', list<string>>> */
    private const TERMS = [
        'tr' => [
            // Long, unambiguous stems.
            'substring' => [
                'orospu', 'siktir', 'sikeyim', 'sikerim', 'amina koy', 'aminakoy', 'amcik', 'yarrak', 'dalyarak',
                'pezevenk', 'serefsiz', 'gotveren', 'got oglani', 'gotoglani', 'ibne', 'kahpe', 'kaltak', 'yavsak',
                'gerizekali', 'geri zekali', 'gavat',
            ],
            // Short forms and stems that sit inside ordinary words. KNOWN FALSE POSITIVE: "sikik" also matches
            // "sıkık" (bored) because ı folds to i. Native review needed.
            'word' => ['amk', 'aq', 'sikik', 'pic', 'gotlek', 'pust', 'sicayim'],
            'prefix' => [],
        ],
        'en' => [
            'substring' => ['fuck', 'bitch', 'asshole', 'dickhead', 'bullshit', 'cocksucker', 'wanker', 'faggot', 'whore'],
            // "cunt" is word-mode to avoid the Scunthorpe problem.
            'word' => [
                'cunt', 'cunts', 'shit', 'shitty', 'dick', 'pussy', 'slut', 'bastard', 'retard', 'retarded',
                'nigger', 'nigga', 'tranny', 'kike', 'spic', 'chink', 'twat', 'prick',
            ],
            'prefix' => [],
        ],
        // Arabic: to be supplied and reviewed by a native speaker. Write terms naturally; tashkeel, tatweel
        // and alef/ya/ta marbuta forms are folded away by TextNormalizer, so one spelling covers the variants.
        'ar' => ['substring' => [], 'word' => [], 'prefix' => []],
    ];

    public function run(): void
    {
        $normalizer = new TextNormalizer;

        foreach (self::TERMS as $language => $modes) {
            foreach ($modes as $mode => $terms) {
                foreach ($terms as $term) {
                    $existing = BlacklistTerm::query()
                        ->where('normalized', $normalizer->normalize($term))
                        ->where('match_mode', $mode)
                        ->first();

                    if ($existing === null) {
                        BlacklistTerm::create(['term' => $term, 'language' => $language, 'match_mode' => $mode, 'active' => true]);
                    } elseif ($existing->language === 'tr' && $language !== 'tr') {
                        // Rows seeded before the language column got the Turkish default; put them right. A language
                        // an admin chose is otherwise left alone.
                        $existing->update(['language' => $language]);
                    }
                }
            }
        }
    }
}
