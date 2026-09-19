<?php

namespace Tests\Feature\Seo\Concerns;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Seo\SeoReviewRequestChannel;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Http\Controllers\Customer\Business\SeoReviewsController;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Models\CrmOpportunity;
use App\Models\SeoLocationReviewLink;
use App\Models\SeoReviewRequest;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Support\Seo\EntitlementBypassSeoReviewsController;

/**
 * Contract 18 Sub-slice 18F — Reviews fixtures, on top of the SEO ones.
 */
trait CreatesSeoReviewFixtures
{
    use CreatesSeoFixtures;

    private int $reviewPhoneSequence = 7000;

    protected function bypassReviewEntitlementForTest(): void
    {
        $this->app->bind(SeoReviewsController::class, EntitlementBypassSeoReviewsController::class);
    }

    protected function reviewsUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.seo.reviews.index', [$workspace->uid, $business->uid]);
    }

    protected function reviewRoute(string $name, Workspace $workspace, Business $business, string ...$parameters): string
    {
        return route('customer.workspaces.businesses.seo.reviews.' . $name, array_merge([$workspace->uid, $business->uid], $parameters));
    }

    /**
     * A Location with a public storefront address.
     */
    protected function reviewLocation(Business $business, string $name = 'Storefront'): BusinessLocation
    {
        return $this->extraLocation($business, $name, [
            'service_mode' => BusinessServiceMode::Storefront,
            'public_address' => true,
            'address_line_1' => '12 High Street',
            'city' => 'Springfield',
            'country_code' => 'US',
        ]);
    }

    protected function archiveLocation(BusinessLocation $location): void
    {
        DB::table('business_locations')->where('id', $location->id)->update([
            'lifecycle_state' => BusinessLocationLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);
    }

    /**
     * A Contact of the Business, attached to a Location (or, with null, to
     * none — the legacy shape the equality rule refuses).
     *
     * @param  array<string, string>  $identity  custom-field tag => value
     */
    protected function reviewContact(Business $business, ?BusinessLocation $location, array $identity = ['FIRST_NAME' => 'Pat', 'LAST_NAME' => 'Rivera']): Contacts
    {
        $group = ContactGroups::query()->where('business_id', $business->id)->first() ?? ContactGroups::create([
            'customer_id' => $business->customer_id,
            'business_id' => $business->id,
            'name' => 'Clients',
            'status' => true,
        ]);

        $contact = Contacts::create([
            'customer_id' => $group->customer_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => '1415555' . str_pad((string) (++$this->reviewPhoneSequence), 4, '0', STR_PAD_LEFT),
            'status' => Contacts::STATUS_SUBSCRIBE,
        ]);

        // Set explicitly and last, so no creation-time defaulting can decide it.
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        foreach ($identity as $tag => $value) {
            $field = ContactGroupFields::query()->firstOrCreate(
                ['contact_group_id' => $group->id, 'tag' => $tag],
                ['label' => ucwords(strtolower(str_replace('_', ' ', $tag))), 'type' => 'text', 'visible' => true, 'required' => false],
            );
            ContactsCustomField::create(['contact_id' => $contact->id, 'field_id' => $field->id, 'value' => $value]);
        }

        return $contact->fresh();
    }

    protected function reviewOpportunity(Business $business, ?Contacts $contact = null): CrmOpportunity
    {
        $pipeline = app(CrmPipelineService::class)->setUpStandardPipeline($business);

        return app(CrmOpportunityService::class)->create($business, $pipeline, $contact ?? $this->reviewContact($business, null), 'Kitchen job', null, null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeReviewRequest(Business $business, BusinessLocation $location, ?Contacts $contact = null, array $overrides = []): SeoReviewRequest
    {
        return SeoReviewRequest::create(array_merge([
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'contact_id' => $contact?->id,
            'channel' => SeoReviewRequestChannel::Sms->value,
            'status' => SeoReviewRequestStatus::Requested->value,
            'requested_at' => now(),
        ], $overrides));
    }

    protected function makeReviewLink(Business $business, BusinessLocation $location, string $url = 'https://g.page/r/example/review'): SeoLocationReviewLink
    {
        return SeoLocationReviewLink::create([
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'review_url' => $url,
        ]);
    }

    /** Permissions of a fully-permitted Reviews user (Contacts and product surfaces included). */
    protected function reviewPermissions(): array
    {
        return array_merge($this->seoPermissions(), ['view_contact', 'chat_box', 'automations']);
    }
}
