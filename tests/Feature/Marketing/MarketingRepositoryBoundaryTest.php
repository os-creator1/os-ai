<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use App\Repositories\Contracts\MarketingFaqRepository;
use App\Repositories\Contracts\MarketingTestimonialRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Review correction (P1): FAQ/testimonial persistence and querying moved
 * out of MarketingContentController and behind
 * App\Repositories\Contracts\MarketingFaqRepository /
 * MarketingTestimonialRepository, per AGENTS.md's controller -> repository
 * -> library -> model structure. MarketingContentAdminTest already proves
 * the full HTTP path through these repositories; this file proves the
 * repository contracts themselves — resolved from the container exactly
 * as the controller resolves them — preserve FAQ/testimonial ordering and
 * the shared-poster orphan check, independent of any HTTP request.
 */
class MarketingRepositoryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_faq_repository_resolves_from_the_container_and_preserves_ordering(): void
    {
        $repository = app(MarketingFaqRepository::class);

        $repository->create(['question' => 'Second?', 'answer' => 'A.', 'position' => 2, 'is_visible' => true]);
        $repository->create(['question' => 'First?', 'answer' => 'A.', 'position' => 1, 'is_visible' => true]);

        $ordered = $repository->allOrdered();

        $this->assertSame(['First?', 'Second?'], $ordered->pluck('question')->all());
        $this->assertSame(3, $repository->nextPosition());

        $faq = MarketingFaq::query()->where('question', 'First?')->firstOrFail();
        $repository->update($faq, ['question' => 'Updated first?', 'answer' => 'A.']);
        $this->assertDatabaseHas('marketing_faqs', ['id' => $faq->id, 'question' => 'Updated first?']);

        $repository->delete($faq);
        $this->assertDatabaseMissing('marketing_faqs', ['id' => $faq->id]);
    }

    /**
     * Review correction round 2 (P1): HomeController's public read moved
     * off MarketingFaq::visibleOrdered() directly onto this repository
     * method, so it must apply the same visibility filter and ordering.
     */
    public function test_faq_repository_visible_ordered_excludes_hidden_faqs(): void
    {
        $repository = app(MarketingFaqRepository::class);

        $repository->create(['question' => 'Hidden?', 'answer' => 'A.', 'position' => 1, 'is_visible' => false]);
        $repository->create(['question' => 'Visible second?', 'answer' => 'A.', 'position' => 3, 'is_visible' => true]);
        $repository->create(['question' => 'Visible first?', 'answer' => 'A.', 'position' => 2, 'is_visible' => true]);

        $visible = $repository->visibleOrdered();

        $this->assertSame(['Visible first?', 'Visible second?'], $visible->pluck('question')->all());
    }

    public function test_testimonial_repository_resolves_from_the_container_and_tracks_poster_usage(): void
    {
        $repository = app(MarketingTestimonialRepository::class);

        $this->assertFalse($repository->isPosterPathInUse('images/marketing/testimonials/does-not-exist.png'));

        $a = $repository->create([
            'name' => 'B Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => 'images/marketing/testimonials/shared.png',
            'position' => 2,
            'is_visible' => true,
        ]);
        $repository->create([
            'name' => 'A Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => 'images/marketing/testimonials/shared.png',
            'position' => 1,
            'is_visible' => true,
        ]);

        $ordered = $repository->allOrdered();
        $this->assertSame(['A Person', 'B Person'], $ordered->pluck('name')->all());
        $this->assertSame(3, $repository->nextPosition());

        $this->assertTrue($repository->isPosterPathInUse('images/marketing/testimonials/shared.png'));

        $repository->delete($a);
        $this->assertTrue(
            $repository->isPosterPathInUse('images/marketing/testimonials/shared.png'),
            'The other testimonial still references the shared path.',
        );

        $remaining = MarketingTestimonial::query()->where('name', 'A Person')->firstOrFail();
        $repository->delete($remaining);
        $this->assertFalse($repository->isPosterPathInUse('images/marketing/testimonials/shared.png'));
    }

    /**
     * Review correction round 2 (P1): HomeController's public read moved
     * off MarketingTestimonial::visibleOrdered() directly onto this
     * repository method, so it must apply the same visibility filter and
     * ordering (the separate hasDisplayableMedia() filter stays a plain
     * Collection operation in the controller, not the repository).
     */
    public function test_testimonial_repository_visible_ordered_excludes_hidden_testimonials(): void
    {
        $repository = app(MarketingTestimonialRepository::class);

        $repository->create([
            'name' => 'Hidden Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => 'images/marketing/testimonials/a.png',
            'position' => 1,
            'is_visible' => false,
        ]);
        $repository->create([
            'name' => 'Visible Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => 'images/marketing/testimonials/b.png',
            'position' => 2,
            'is_visible' => true,
        ]);

        $visible = $repository->visibleOrdered();

        $this->assertSame(['Visible Person'], $visible->pluck('name')->all());
    }
}
