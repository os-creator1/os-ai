<?php

namespace App\Library\Seo;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoDirectoryTrackingMode;
use App\Exceptions\Seo\SeoCitationCatalogException;
use App\Models\SeoCitationDirectory;
use App\Models\SeoNicheCitationRecommendation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Citations V1 — the Platform Owner's write path for the directory CATALOG and
 * for NICHE RECOMMENDATIONS. The only code that writes platform rows of
 * seo_citation_directories or any row of seo_niche_citation_recommendations.
 *
 * Boundaries (each proven by test):
 *  - Platform-administrator authority is re-derived from users.is_admin on
 *    every call, on top of the route middleware (the NicheBlueprintPublisher
 *    idiom). There is no customer path here.
 *  - A niche recommendation REFERENCES a directory and can set only importance,
 *    order, enabled and guidance. It can never change a directory's website,
 *    claim URL, tracking mode or any other catalog field.
 *  - A directory is never deleted: disabling it stops new recommendations while
 *    every Business's existing record stays readable (history is kept).
 *  - tracking_mode here is limited to `assisted` and `manual`. `connected` and
 *    `automatic_check` describe a real provider integration, which no screen
 *    can grant; they are set only by code that ships the integration. No
 *    credentials are handled here.
 *  - Claim and website links must be https and pass SeoLinkSafety.
 *  - Business-owned custom directories are never reachable through this class.
 */
final class SeoCitationCatalogManager
{
    public const CATEGORIES = ['general', 'maps', 'search', 'reviews', 'social', 'directory', 'data_aggregator', 'trust', 'wedding_events', 'lead_marketplace'];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function createDirectory(int $actorUserId, array $input): SeoCitationDirectory
    {
        $this->assertPlatformAdministrator($actorUserId);

        $values = $this->directoryValues($input);
        $key = $this->uniqueKey($values['name']);

        $directory = new SeoCitationDirectory();
        $directory->forceFill($values + ['key' => $key, 'is_active' => true, 'business_id' => null, 'business_location_id' => null])->save();

        return $directory;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function updateDirectory(int $actorUserId, string $uid, array $input): SeoCitationDirectory
    {
        $this->assertPlatformAdministrator($actorUserId);

        $directory = $this->platformDirectory($uid);
        $directory->forceFill($this->directoryValues($input))->save();

        return $directory;
    }

    /**
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function setActive(int $actorUserId, string $uid, bool $active): SeoCitationDirectory
    {
        $this->assertPlatformAdministrator($actorUserId);

        $directory = $this->platformDirectory($uid);
        $directory->forceFill(['is_active' => $active])->save();

        return $directory;
    }

    /**
     * Adds (or re-enables) a niche recommendation for a catalog directory.
     *
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function recommend(int $actorUserId, string $nicheKey, string $directoryUid, ?string $importance, ?string $guidance): SeoNicheCitationRecommendation
    {
        $this->assertPlatformAdministrator($actorUserId);
        $this->assertNiche($nicheKey);

        $directory = $this->platformDirectory($directoryUid);

        $row = SeoNicheCitationRecommendation::query()
            ->where('niche_key', $nicheKey)
            ->where('seo_citation_directory_id', $directory->id)
            ->first() ?? new SeoNicheCitationRecommendation();

        $nextOrder = ((int) SeoNicheCitationRecommendation::query()->where('niche_key', $nicheKey)->max('sort_order')) + 10;

        $row->forceFill([
            'niche_key' => $nicheKey,
            'seo_citation_directory_id' => $directory->id,
            'importance' => $this->importance($importance),
            'guidance' => $this->guidance($guidance),
            'is_enabled' => true,
            'sort_order' => $row->exists ? $row->sort_order : $nextOrder,
        ]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            throw new SeoCitationCatalogException('That directory is already recommended for this niche.');
        }

        return $row;
    }

    /**
     * The Blueprint-publish path for a niche recommendation: creates the row
     * when it is missing, and otherwise NEVER reverts a Platform Owner choice.
     * It does not re-enable a recommendation the owner disabled, never touches
     * its order, and changes its importance / guidance only when they still
     * equal what the PREVIOUS published version declared — i.e. nobody has
     * edited them since — so a republish cannot undo an edit made on the Niche
     * Recommendations screen. (The seeder keeps the owner's enable/disable
     * choice the same way.)
     *
     * @param  ?array{importance: ?string, guidance: ?string}  $previouslyDeclared  what the previous published version said
     *                                                                              about this directory; null when it did not list it
     *
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function syncRecommendation(int $actorUserId, string $nicheKey, string $directoryUid, ?string $importance, ?string $guidance, ?array $previouslyDeclared = null): ?SeoNicheCitationRecommendation
    {
        $this->assertPlatformAdministrator($actorUserId);
        $this->assertNiche($nicheKey);

        $directory = $this->platformDirectory($directoryUid);

        $row = SeoNicheCitationRecommendation::query()
            ->where('niche_key', $nicheKey)
            ->where('seo_citation_directory_id', $directory->id)
            ->first();

        if ($row === null) {
            // The previous version already listed it and the row is gone: the Platform Owner removed it on
            // purpose, and a republish must not bring it back. Only a directory new to the list is created.
            return $previouslyDeclared !== null ? null : $this->recommend($actorUserId, $nicheKey, $directoryUid, $importance, $guidance);
        }

        if ($previouslyDeclared === null
            || $row->importance?->value !== $this->importance($previouslyDeclared['importance'] ?? null)
            || $this->guidance($row->guidance) !== $this->guidance($previouslyDeclared['guidance'] ?? null)) {
            return $row;
        }

        $row->forceFill(['importance' => $this->importance($importance), 'guidance' => $this->guidance($guidance)])->save();

        return $row;
    }

    /**
     * @param  array{importance?: ?string, guidance?: ?string, sort_order?: mixed, is_enabled?: mixed}  $input
     *
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function updateRecommendation(int $actorUserId, string $uid, array $input): SeoNicheCitationRecommendation
    {
        $this->assertPlatformAdministrator($actorUserId);

        $row = $this->recommendation($uid);

        $row->forceFill([
            'importance' => $this->importance($input['importance'] ?? null),
            'guidance' => $this->guidance($input['guidance'] ?? null),
            'sort_order' => $this->sortOrder($input['sort_order'] ?? $row->sort_order),
            'is_enabled' => filter_var($input['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ])->save();

        return $row;
    }

    /**
     * @throws AuthorizationException
     * @throws SeoCitationCatalogException
     */
    public function removeRecommendation(int $actorUserId, string $uid): void
    {
        $this->assertPlatformAdministrator($actorUserId);

        // A recommendation holds no Business state, so removing it loses
        // nothing a Business recorded; its citations stay as history.
        $this->recommendation($uid)->delete();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws SeoCitationCatalogException
     */
    private function directoryValues(array $input): array
    {
        $name = $this->requiredString($input['name'] ?? null, 120, 'Enter the directory name.');
        $category = (string) ($input['category'] ?? 'general');
        $mode = SeoDirectoryTrackingMode::tryFrom((string) ($input['tracking_mode'] ?? ''));
        $importance = SeoDirectoryImportance::tryFrom((string) ($input['importance'] ?? ''));
        $country = $this->nullableString($input['country_scope'] ?? null);

        if (! in_array($category, self::CATEGORIES, true)) {
            throw new SeoCitationCatalogException('Choose a valid category.');
        }

        if ($importance === null) {
            throw new SeoCitationCatalogException('Choose a default importance.');
        }

        if ($mode !== SeoDirectoryTrackingMode::Assisted && $mode !== SeoDirectoryTrackingMode::Manual) {
            throw new SeoCitationCatalogException('Tracking mode must be Guided setup (assisted) or Manually tracked. Automatic checking exists only where a real integration ships.');
        }

        if ($country !== null && preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new SeoCitationCatalogException('Country must be a two-letter code such as US, or empty for everywhere.');
        }

        $claim = $this->safeUrl($input['claim_url'] ?? null, 'Claim / manage link');

        if ($mode === SeoDirectoryTrackingMode::Assisted && $claim === null) {
            throw new SeoCitationCatalogException('A guided-setup directory needs a verified official claim / manage link. Use Manually tracked if there is none.');
        }

        return [
            'name' => $name,
            'website_url' => $this->safeUrl($input['website_url'] ?? null, 'Website'),
            'claim_url' => $claim,
            'category' => $category,
            'icon' => $this->nullableString($input['icon'] ?? null, 40),
            'importance' => $importance->value,
            'tracking_mode' => $mode->value,
            'setup_guidance' => $this->guidance($input['setup_guidance'] ?? null),
            'is_platform_core' => filter_var($input['is_platform_core'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'country_scope' => $country,
            'sort_order' => $this->sortOrder($input['sort_order'] ?? 100),
        ];
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'directory';
        $key = Str::limit($base, 56, '');

        if (! SeoCitationDirectory::query()->where('key', $key)->exists()) {
            return $key;
        }

        return $key . '_' . Str::lower(Str::random(6));
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function platformDirectory(string $uid): SeoCitationDirectory
    {
        $directory = SeoCitationDirectory::query()->where('uid', $uid)->whereNull('business_id')->first();

        if ($directory === null) {
            throw new SeoCitationCatalogException('Directory not found.');
        }

        return $directory;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function recommendation(string $uid): SeoNicheCitationRecommendation
    {
        $row = SeoNicheCitationRecommendation::query()->where('uid', $uid)->first();

        if ($row === null) {
            throw new SeoCitationCatalogException('Recommendation not found.');
        }

        return $row;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function assertNiche(string $nicheKey): void
    {
        if (BusinessIndustry::tryFrom($nicheKey) === null) {
            throw new SeoCitationCatalogException('Unknown niche.');
        }
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function importance(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        if ($string === null) {
            return null;
        }

        $importance = SeoDirectoryImportance::tryFrom($string);

        if ($importance === null) {
            throw new SeoCitationCatalogException('Choose Essential, Recommended or Optional.');
        }

        return $importance->value;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function guidance(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        if ($string !== null && mb_strlen($string) > 500) {
            throw new SeoCitationCatalogException('Guidance must be 500 characters or fewer.');
        }

        return $string;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function sortOrder(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0 || (int) $value > 60000) {
            throw new SeoCitationCatalogException('Order must be a whole number from 0 to 60000.');
        }

        return (int) $value;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function safeUrl(mixed $value, string $label): ?string
    {
        $url = $this->nullableString($value, 2048);

        if ($url !== null && ! SeoLinkSafety::isSafeHttpsUrl($url)) {
            throw new SeoCitationCatalogException($label . ' must be a full link that starts with https://.');
        }

        return $url;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function requiredString(mixed $value, int $max, string $message): string
    {
        $string = $this->nullableString($value, $max);

        if ($string === null) {
            throw new SeoCitationCatalogException($message);
        }

        return $string;
    }

    /**
     * @throws SeoCitationCatalogException
     */
    private function nullableString(mixed $value, ?int $max = null): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        if ($max !== null && mb_strlen($string) > $max) {
            throw new SeoCitationCatalogException("A value is longer than {$max} characters.");
        }

        return $string;
    }

    /**
     * @throws AuthorizationException
     */
    private function assertPlatformAdministrator(int $actorUserId): void
    {
        if (! (bool) User::query()->whereKey($actorUserId)->value('is_admin')) {
            throw new AuthorizationException('Only a platform administrator can manage the citation catalog.');
        }
    }
}
