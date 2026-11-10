<?php

namespace App\Library\Website;

use App\Library\Website\Seo\WebsitePageIntentClassifier;
use Illuminate\Support\Str;

/**
 * Does this event type have something of the owner's own to say, enough for a page of its own?
 *
 * A "Weddings" card on the home page is legitimate on its own. A whole page about weddings is only useful if it can tell a
 * couple something specific, and the generator may only say what the owner confirmed. The questionnaire offers a prewritten
 * starting sentence for each event type; that sentence is an example, not a fact the owner confirmed, so a description that is
 * still the unchanged example does not count, and neither does an empty one. The owner is told what to add.
 *
 * Also owns the event page's address and wording, so the URL, title, heading and plan all derive from the one saved name:
 * "Weddings" -> /wedding-photo-booth-rental, "Corporate Events" -> /corporate-photo-booth-rental,
 * "Birthday Parties" -> /birthday-party-photo-booth-rental.
 *
 * Pure: no queries, no AI, no network.
 */
final class EventPageUsefulness
{
    /**
     * @return array{qualifies: bool, reason: string}
     */
    public static function assess(string $name, ?string $description): array
    {
        [$prose, $features] = \App\Library\Catalog\CatalogFeatureList::split($description);
        $text = trim((string) $prose);

        if ($text === '' && $features === []) {
            return ['qualifies' => false, 'reason' => self::missing($name, 'You have not described ' . $name . ' yet.')];
        }

        if ($features === [] && self::isExample($text)) {
            return ['qualifies' => false, 'reason' => self::missing($name, 'The description is still the example sentence the setup offered, which is not something you confirmed.')];
        }

        return ['qualifies' => true, 'reason' => 'You described this event type in your own words, so it gets its own page.'];
    }

    private static function missing(string $name, string $why): string
    {
        return 'Listed under "Events we cover" without a page of its own. ' . $why . ' Describe in a few sentences what you do for ' . $name . ' (what is included, how you set up, anything couples or organisers should know) and it is planned.';
    }

    /** True when the text is one of the setup's prewritten example sentences, unchanged. */
    public static function isExample(string $text): bool
    {
        $flat = fn (string $value) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
        $needle = $flat($text);

        foreach (\Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder::eventPresets() as $preset) {
            if ($flat($preset['description']) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** "Weddings" -> "Wedding", "Corporate Events" -> "Corporate", "Birthday Parties" -> "Birthday party"; null when nothing is left. */
    public static function label(string $name): ?string
    {
        $words = WebsitePageIntentClassifier::tokens($name, true);

        return $words === [] ? null : Str::ucfirst(implode(' ', $words));
    }

    /** The page's address, e.g. "wedding-photo-booth-rental"; null when the name leaves no usable words. */
    public static function slug(string $name): ?string
    {
        $label = self::label($name);

        return $label === null ? null : WebsiteSlugRules::bounded('', $label . ' photo booth rental', 'event');
    }

    /** The words that identify this event type, for spotting another page that already targets it. @return array<int, string> */
    public static function words(string $name): array
    {
        return WebsitePageIntentClassifier::tokens($name, true);
    }
}
