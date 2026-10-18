<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Catalog\CatalogFeatureList;
use App\Library\Website\Gallery\ImageAltText;
use App\Library\Website\Setup\Exceptions\InvalidAnswerException;
use App\Library\Website\Setup\QuestionnaireAnswerValidator;
use App\Library\Website\Setup\QuestionnaireDefinitionValidator;
use App\Library\Website\Setup\QuestionnaireStepResolver;
use App\Library\Website\Setup\ServiceAreaList;
use DomainException;
use Tests\TestCase;

/**
 * The building blocks of the smarter Website setup: wizard SCREENS (a
 * presentation grouping of atomic steps), one-per-row list answers, the
 * canonical package selection answer, niche category vocabularies, and the
 * small normalizers behind them. Pure — no database.
 */
class SmartInputsFoundationTest extends TestCase
{
    private function step(string $key, array $overrides = []): array
    {
        return array_merge([
            'key' => $key,
            'prompt' => ucfirst($key),
            'help_text' => null,
            'input_type' => 'text',
            'required' => true,
            'options' => null,
            'conditional_visibility' => null,
            'target_module' => 'answers',
            'target_field' => null,
            'ai_instructions' => null,
        ], $overrides);
    }

    private function validate(array $steps): void
    {
        app(QuestionnaireDefinitionValidator::class)->validate($steps);
    }

    // ---------------------------------------------------------------- screens

    public function test_steps_sharing_a_screen_key_form_one_screen_and_unscreened_steps_stay_their_own_screen(): void
    {
        $steps = [
            $this->step('a', ['screen' => 's1']),
            $this->step('b', ['screen' => 's1']),
            $this->step('c'),
            $this->step('d', ['screen' => 's2']),
            $this->step('e', ['screen' => 's2']),
            $this->step('f', ['screen' => 's2']),
            $this->step('g'),
        ];
        $resolver = new QuestionnaireStepResolver();

        $screens = array_map(fn ($screen) => array_column($screen, 'key'), $resolver->screens($steps, []));

        $this->assertSame([['a', 'b'], ['c'], ['d', 'e', 'f'], ['g']], $screens);
        $this->assertSame(['d', 'e', 'f'], array_column($resolver->screenFor($steps, [], 'e'), 'key'));
        $this->assertSame([], $resolver->screenFor($steps, [], 'unknown'));
    }

    public function test_next_and_previous_move_between_screens_not_between_steps(): void
    {
        $steps = [
            $this->step('a', ['screen' => 's1']),
            $this->step('b', ['screen' => 's1']),
            $this->step('c'),
            $this->step('d', ['screen' => 's2']),
            $this->step('e', ['screen' => 's2']),
        ];
        $resolver = new QuestionnaireStepResolver();

        // From either step of a screen, "next" is the FIRST step of the next screen.
        $this->assertSame('c', $resolver->nextStepKey($steps, [], 'a'));
        $this->assertSame('c', $resolver->nextStepKey($steps, [], 'b'));
        $this->assertSame('d', $resolver->nextStepKey($steps, [], 'c'));
        $this->assertNull($resolver->nextStepKey($steps, [], 'd'));
        $this->assertNull($resolver->nextStepKey($steps, [], 'e'));

        // "Previous" lands on the FIRST step of the previous screen.
        $this->assertNull($resolver->previousStepKey($steps, [], 'a'));
        $this->assertNull($resolver->previousStepKey($steps, [], 'b'));
        $this->assertSame('a', $resolver->previousStepKey($steps, [], 'c'));
        $this->assertSame('c', $resolver->previousStepKey($steps, [], 'd'));
        $this->assertSame('c', $resolver->previousStepKey($steps, [], 'e'));
    }

    public function test_a_definition_without_screens_navigates_exactly_as_before(): void
    {
        $steps = [$this->step('a'), $this->step('b'), $this->step('c')];
        $resolver = new QuestionnaireStepResolver();

        $this->assertSame('b', $resolver->nextStepKey($steps, [], 'a'));
        $this->assertSame('a', $resolver->previousStepKey($steps, [], 'b'));
        $this->assertCount(3, $resolver->screens($steps, []));
    }

    public function test_the_definition_validator_enforces_screen_rules(): void
    {
        // Valid grouping.
        $this->validate([$this->step('a', ['screen' => 's1']), $this->step('b', ['screen' => 's1']), $this->step('c')]);

        // A screen may not be split.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('split');
        $this->validate([$this->step('a', ['screen' => 's1']), $this->step('b'), $this->step('c', ['screen' => 's1'])]);
    }

    public function test_a_screen_is_bounded_and_may_not_depend_on_itself(): void
    {
        $tooMany = [];
        foreach (range(1, 7) as $i) {
            $tooMany[] = $this->step('s' . $i, ['screen' => 'big']);
        }

        try {
            $this->validate($tooMany);
            $this->fail('A 7-step screen must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('more than', $e->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('own screen');
        $this->validate([
            $this->step('a', ['screen' => 's1', 'input_type' => 'boolean']),
            $this->step('b', ['screen' => 's1', 'conditional_visibility' => ['depends_on' => 'a', 'condition' => 'equals', 'value' => true]]),
        ]);
    }

    public function test_an_invalid_screen_key_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->validate([$this->step('a', ['screen' => 'Not Valid!'])]);
    }

    // ----------------------------------------- new input types and categories

    public function test_string_list_and_catalog_selection_only_target_their_own_modules(): void
    {
        $this->validate([
            $this->step('areas', ['input_type' => 'string_list', 'target_module' => 'business_location']),
            $this->step('packages', ['input_type' => 'catalog_selection', 'target_module' => 'catalog_item']),
        ]);

        foreach ([
            ['input_type' => 'string_list', 'target_module' => 'business'],
            ['input_type' => 'catalog_selection', 'target_module' => 'business_service'],
        ] as $overrides) {
            try {
                $this->validate([$this->step('x', $overrides + ['target_field' => 'name'])]);
                $this->fail('An incompatible pairing must be refused: ' . json_encode($overrides));
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_categories_are_only_allowed_on_backdrop_and_gallery_steps(): void
    {
        $categories = [['value' => 'backdrop', 'label' => 'Backdrop'], ['value' => 'event', 'label' => 'Event']];

        $this->validate([
            $this->step('backdrops', ['input_type' => 'repeatable_group', 'target_module' => 'backdrop', 'categories' => $categories]),
            $this->step('gallery', ['input_type' => 'photo_upload', 'target_module' => 'gallery', 'categories' => $categories]),
        ]);

        $this->expectException(DomainException::class);
        $this->validate([$this->step('services', ['input_type' => 'repeatable_group', 'target_module' => 'business_service', 'categories' => $categories])]);
    }

    public function test_category_values_must_be_clean_keys_with_labels(): void
    {
        $this->expectException(DomainException::class);
        $this->validate([$this->step('backdrops', ['input_type' => 'repeatable_group', 'target_module' => 'backdrop', 'categories' => [['value' => 'Bad Key', 'label' => 'Label']]])]);
    }

    public function test_categories_must_be_an_ordered_list_so_their_order_survives_storage(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ordered list');
        $this->validate([$this->step('backdrops', ['input_type' => 'repeatable_group', 'target_module' => 'backdrop', 'categories' => ['backdrop' => 'Backdrop']])]);
    }

    // ---------------------------------------------------------- answer checks

    public function test_string_list_answers_are_bounded(): void
    {
        $validator = new QuestionnaireAnswerValidator();
        $step = $this->step('areas', ['input_type' => 'string_list', 'target_module' => 'business_location']);

        $validator->validate($step, ['Naperville, IL', 'Aurora, IL']);

        foreach ([
            'a string' => 'Naperville, Aurora',
            'a blank entry' => ['Naperville', ' '],
            'too long an entry' => [str_repeat('x', QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH + 1)],
            'too many entries' => array_map(fn ($i) => 'City ' . $i, range(1, QuestionnaireAnswerValidator::MAX_LIST_ITEMS + 1)),
        ] as $label => $bad) {
            try {
                $validator->validate($step, $bad);
                $this->fail("$label must be refused.");
            } catch (InvalidAnswerException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_required_string_list_cannot_be_empty(): void
    {
        $this->expectException(InvalidAnswerException::class);
        (new QuestionnaireAnswerValidator())->validate($this->step('areas', ['input_type' => 'string_list', 'target_module' => 'business_location']), []);
    }

    public function test_catalog_selection_answers_hold_only_unique_uids(): void
    {
        $validator = new QuestionnaireAnswerValidator();
        $step = $this->step('packages', ['input_type' => 'catalog_selection', 'target_module' => 'catalog_item']);

        $validator->validate($step, [['uid' => 'a'], ['uid' => 'b']]);

        $this->expectException(InvalidAnswerException::class);
        $validator->validate($step, [['uid' => 'a'], ['uid' => 'a']]);
    }

    public function test_a_backdrop_category_must_come_from_the_steps_own_vocabulary(): void
    {
        $validator = new QuestionnaireAnswerValidator();
        $step = $this->step('backdrops', [
            'input_type' => 'repeatable_group',
            'target_module' => 'backdrop',
            'categories' => ['backdrop' => 'Backdrop', 'event' => 'Event'],
        ]);

        $validator->validate($step, [['name' => 'Floral', 'category' => 'backdrop']]);
        $validator->validate($step, [['name' => 'Floral']]); // category is optional

        $this->expectException(InvalidAnswerException::class);
        $validator->validate($step, [['name' => 'Floral', 'category' => 'made_up']]);
    }

    // ------------------------------------------------------------ normalizers

    public function test_service_areas_keep_their_order_collapse_whitespace_and_drop_duplicates_without_comma_parsing(): void
    {
        $normalized = ServiceAreaList::normalize([
            '  Manhattan,   NY ',
            'Brooklyn',
            '',
            'manhattan, ny',
            'Queens',
            "Long\n  Island",
        ]);

        // "Manhattan, NY" stays ONE entry — a comma is just a character.
        $this->assertSame(['Manhattan, NY', 'Brooklyn', 'Queens', 'Long Island'], $normalized);
    }

    public function test_service_area_normalization_is_bounded(): void
    {
        $many = array_map(fn ($i) => 'Area ' . $i, range(1, 80));

        $this->assertCount(QuestionnaireAnswerValidator::MAX_LIST_ITEMS, ServiceAreaList::normalize($many));
        $this->assertSame(
            QuestionnaireAnswerValidator::MAX_LIST_ITEM_LENGTH,
            mb_strlen(ServiceAreaList::normalize([str_repeat('y', 500)])[0])
        );
    }

    public function test_package_features_round_trip_through_the_description_convention(): void
    {
        $joined = CatalogFeatureList::join('Great for weddings.', ['Unlimited prints', ' Props included ', '']);

        $this->assertSame("Great for weddings.\n\n- Unlimited prints\n- Props included", $joined);
        $this->assertSame(['Great for weddings.', ['Unlimited prints', 'Props included']], CatalogFeatureList::split($joined));
        $this->assertSame([null, ['Only features']], CatalogFeatureList::split(CatalogFeatureList::join(null, ['Only features'])));
        $this->assertNull(CatalogFeatureList::join(' ', []));
    }

    public function test_hand_written_bullets_in_the_middle_of_prose_are_not_mistaken_for_features(): void
    {
        $text = "Intro\n- not a feature list\nMore prose after it";

        $this->assertSame([$text, []], CatalogFeatureList::split($text));
    }

    public function test_default_alt_text_is_factual_never_empty_and_never_keyword_stuffed(): void
    {
        $this->assertSame('White floral — backdrop', ImageAltText::suggest('White floral', 'Backdrop', 'Snap Booth Co'));
        $this->assertSame('Floral backdrop', ImageAltText::suggest('Floral backdrop', 'Backdrop', 'Snap Booth Co'), 'a name that already says the category is not repeated');
        $this->assertSame('open air booth — Snap Booth Co', ImageAltText::suggest(null, 'open_air_booth', 'Snap Booth Co'));
        $this->assertSame('Snap Booth Co photo', ImageAltText::suggest(null, null, 'Snap Booth Co'));
        $this->assertSame('Photo', ImageAltText::suggest(null, null, null));
        $this->assertLessThanOrEqual(ImageAltText::MAX_LENGTH, mb_strlen(ImageAltText::suggest(str_repeat('a ', 200), null, null)));
    }
}
