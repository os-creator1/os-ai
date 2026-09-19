<?php

namespace Tests\Feature\Seo;

use App\Library\Seo\SeoPhraseNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract 18 §8.4 — the single phrase normalization:
 * NFKC → lower-case → collapse whitespace → trim. No stemming.
 */
class SeoPhraseNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function matrix(): array
    {
        return [
            'already normal' => ['best bakery', 'best bakery'],
            'upper case' => ['BEST BAKERY', 'best bakery'],
            'mixed case' => ['Best Bakery In Tampa', 'best bakery in tampa'],
            'leading and trailing spaces' => ['   best bakery   ', 'best bakery'],
            'internal runs of spaces' => ['best    bakery', 'best bakery'],
            'tabs and newlines collapse' => ["best\t\nbakery", 'best bakery'],
            'non-breaking space (NFKC)' => ["best\u{00A0}bakery", 'best bakery'],
            'ideographic space (NFKC)' => ["best\u{3000}bakery", 'best bakery'],
            'em space' => ["best\u{2003}bakery", 'best bakery'],
            'fullwidth latin (NFKC)' => ['ＢＥＳＴ　ＢＡＫＥＲＹ', 'best bakery'],
            'ligature expands (NFKC)' => ['ﬁne ﬂour', 'fine flour'],
            'decomposed accent composes (NFKC)' => ["cafe\u{0301}", "caf\u{00E9}"],
            'precomposed accent unchanged' => ["caf\u{00E9}", "caf\u{00E9}"],
            'accents are NOT stripped' => ['café', 'café'],
            'unicode lower-casing' => ['ÉCOLE', 'école'],
            'punctuation is kept' => ['Best Bakery, Tampa!', 'best bakery, tampa!'],
            'digits kept' => ['24 Hour Plumber', '24 hour plumber'],
            'no stemming (plural stays)' => ['Bakeries', 'bakeries'],
            'no stop-word removal' => ['the best bakery', 'the best bakery'],
            'empty' => ['', ''],
            'only whitespace' => ["  \t\n ", ''],
        ];
    }

    #[DataProvider('matrix')]
    public function test_normalization_matrix(string $input, string $expected): void
    {
        $this->assertSame($expected, SeoPhraseNormalizer::normalize($input));
    }

    public function test_it_is_idempotent(): void
    {
        foreach (array_column(self::matrix(), 0) as $input) {
            $once = SeoPhraseNormalizer::normalize($input);
            $this->assertSame($once, SeoPhraseNormalizer::normalize($once), 'Normalizing twice must change nothing.');
        }
    }

    public function test_phrases_that_differ_only_by_form_normalize_equal(): void
    {
        $forms = ['Best Bakery', 'best bakery', '  BEST   BAKERY ', "Best\u{00A0}Bakery", 'ＢＥＳＴ　ＢＡＫＥＲＹ'];

        $this->assertCount(1, array_unique(array_map([SeoPhraseNormalizer::class, 'normalize'], $forms)));
    }

    public function test_phrases_that_genuinely_differ_stay_different(): void
    {
        $this->assertNotSame(SeoPhraseNormalizer::normalize('bakery'), SeoPhraseNormalizer::normalize('bakeries'));
        $this->assertNotSame(SeoPhraseNormalizer::normalize('cafe'), SeoPhraseNormalizer::normalize('café'));
        $this->assertNotSame(SeoPhraseNormalizer::normalize('best bakery'), SeoPhraseNormalizer::normalize('bakery best'));
    }

    public function test_invalid_utf8_normalizes_to_empty_so_a_caller_can_reject_it(): void
    {
        $this->assertSame('', SeoPhraseNormalizer::normalize("bad \xC3\x28 bytes"));
        $this->assertSame('', SeoPhraseNormalizer::normalize("\xFF\xFE"));
    }
}
