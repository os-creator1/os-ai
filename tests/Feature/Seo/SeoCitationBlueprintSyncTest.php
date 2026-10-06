<?php

namespace Tests\Feature\Seo;

use App\Library\NicheBlueprint\Adapters\CitationRecommendationsComponentAdapter;
use App\Models\NicheBlueprint;
use App\Models\SeoNicheCitationRecommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Publishing a Blueprint syncs its citation recommendations into the live
 * niche table — and never reverts a choice the Platform Owner made there. A
 * missing row is created; a disabled one stays disabled; importance / guidance
 * the owner edited are left alone; a recommendation the Blueprint itself
 * changed (and nobody has touched since) is updated.
 */
class SeoCitationBlueprintSyncTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    private const NICHE = 'photo_booth_service';

    private function adapter(): CitationRecommendationsComponentAdapter
    {
        return app(CitationRecommendationsComponentAdapter::class);
    }

    private function blueprint(): NicheBlueprint
    {
        $blueprint = new NicheBlueprint();
        $blueprint->broad_industry = self::NICHE;

        return $blueprint;
    }

    /** @param  array<int, array{0: string, 1: string, 2: ?string}>  $recs  [directory key, importance, guidance] */
    private function payload(array $recs): array
    {
        return ['recommendations' => array_map(
            fn (array $r) => ['directory_key' => $r[0], 'importance' => $r[1]] + ($r[2] !== null ? ['guidance' => $r[2]] : []),
            $recs,
        )];
    }

    private function row(string $directoryKey): ?SeoNicheCitationRecommendation
    {
        return SeoNicheCitationRecommendation::query()
            ->where('niche_key', self::NICHE)
            ->where('seo_citation_directory_id', $this->directory($directoryKey)->id)
            ->first();
    }

    public function test_publishing_does_not_re_enable_a_recommendation_the_owner_disabled(): void
    {
        $adminId = $this->platformAdminId();
        $this->row('bark')->forceFill(['is_enabled' => false])->save();

        $this->adapter()->onPublished($this->blueprint(), $this->payload([['bark', 'optional', null], ['gigsalad', 'recommended', null]]), null, $adminId);
        $this->adapter()->onPublished($this->blueprint(), $this->payload([['bark', 'optional', null], ['gigsalad', 'recommended', null]]), $this->payload([['bark', 'optional', null], ['gigsalad', 'recommended', null]]), $adminId);

        $this->assertFalse($this->row('bark')->is_enabled, 'An owner-disabled recommendation stays disabled across publishes.');
    }

    public function test_publishing_does_not_revert_importance_or_guidance_the_owner_edited(): void
    {
        $adminId = $this->platformAdminId();
        $this->row('gigsalad')->forceFill(['importance' => 'essential', 'guidance' => 'Owner wrote this.', 'sort_order' => 1])->save();
        $declared = $this->payload([['gigsalad', 'recommended', 'Blueprint copy.']]);

        // First publish, and a republish of an unchanged version.
        $this->adapter()->onPublished($this->blueprint(), $declared, null, $adminId);
        $this->adapter()->onPublished($this->blueprint(), $declared, $declared, $adminId);

        $row = $this->row('gigsalad');
        $this->assertSame('essential', $row->importance->value);
        $this->assertSame('Owner wrote this.', $row->guidance);
        $this->assertSame(1, $row->sort_order, 'Order is the owner\'s too.');
    }

    public function test_a_missing_recommendation_is_created_enabled(): void
    {
        $adminId = $this->platformAdminId();
        $this->assertNull($this->row('mapquest'));

        $this->adapter()->onPublished($this->blueprint(), $this->payload([['mapquest', 'optional', 'Only if you serve drivers.']]), null, $adminId);

        $row = $this->row('mapquest');
        $this->assertNotNull($row);
        $this->assertTrue($row->is_enabled);
        $this->assertSame('optional', $row->importance->value);
        $this->assertSame('Only if you serve drivers.', $row->guidance);
    }

    public function test_a_recommendation_the_owner_removed_is_not_recreated_by_a_republish(): void
    {
        $adminId = $this->platformAdminId();
        $declared = $this->payload([['mapquest', 'optional', null], ['gigsalad', 'recommended', null]]);

        $this->adapter()->onPublished($this->blueprint(), $declared, null, $adminId);
        $this->assertNotNull($this->row('mapquest'));

        // The Platform Owner removes it on purpose; the same list is published again.
        $this->row('mapquest')->delete();
        $this->adapter()->onPublished($this->blueprint(), $declared, $declared, $adminId);

        $this->assertNull($this->row('mapquest'), 'It was already in the previous version, so a republish leaves it removed.');

        // A directory that is NEW to the list is still created.
        $withNew = $this->payload([['mapquest', 'optional', null], ['gigsalad', 'recommended', null], ['yellow_pages', 'optional', null]]);
        $this->adapter()->onPublished($this->blueprint(), $withNew, $declared, $adminId);

        $this->assertNull($this->row('mapquest'));
        $this->assertNotNull($this->row('yellow_pages'));
    }

    public function test_a_change_the_blueprint_itself_made_updates_an_unedited_row_but_never_an_edited_one(): void
    {
        $adminId = $this->platformAdminId();
        $v1 = $this->payload([['mapquest', 'optional', 'v1 copy']]);
        $v2 = $this->payload([['mapquest', 'recommended', 'v2 copy']]);
        $v3 = $this->payload([['mapquest', 'essential', 'v3 copy']]);

        $this->adapter()->onPublished($this->blueprint(), $v1, null, $adminId);

        // Nobody touched the row since v1, so the Blueprint's v2 declaration applies.
        $this->adapter()->onPublished($this->blueprint(), $v2, $v1, $adminId);
        $row = $this->row('mapquest');
        $this->assertSame('recommended', $row->importance->value);
        $this->assertSame('v2 copy', $row->guidance);

        // The owner edits it; the Blueprint's later v3 must not win over that edit.
        $row->forceFill(['importance' => 'optional', 'guidance' => 'Owner edit'])->save();
        $this->adapter()->onPublished($this->blueprint(), $v3, $v2, $adminId);

        $row = $this->row('mapquest');
        $this->assertSame('optional', $row->importance->value);
        $this->assertSame('Owner edit', $row->guidance);
    }
}
