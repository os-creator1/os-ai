<?php

namespace App\Library\Branding;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Branding\AgencyWhiteLabelException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyWhiteLabelChange;
use App\Models\AgencyWhiteLabelSetting;
use App\Models\Workspace;
use App\Rules\ValidBrandingImageRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Agency V1 completion — the one writer of an Agency's white-label identity.
 *
 * AUTHORITY, re-derived on every write from persisted state, never from the
 * caller's say-so:
 *  - the actor is the Agency Workspace OWNER, read from the Workspace row
 *    AFTER it is locked (Acceptance Matrix: White-label is owner-only; an
 *    Agency Admin/Staff member can read the surface but never change it);
 *  - the Workspace is currently an eligible Agency — Agency tier with a
 *    usable account (AgencyClientRelationshipManager, the one definition of
 *    management eligibility) — so a locked/suspended/downgraded Agency can
 *    neither change nor publish a brand;
 *  - the Workspace is entitled to `white_label` through
 *    EntitlementManager::decideForWorkspace() — the existing entitlement
 *    architecture, not a second permission engine.
 * The Agency is always the Workspace passed in and locked here; no client
 * Workspace, uid or "which agency" parameter exists in this class, so a
 * caller cannot write another Agency's brand by naming it.
 *
 * TRANSACTION-SAFE AND IDEMPOTENT. One transaction under a row lock on the
 * Agency Workspace serializes concurrent saves; the settings row is unique per
 * Agency; resubmitting identical values writes nothing and records no audit
 * row, so a browser retry or double click is a no-op.
 *
 * AUDIT. Every effective change writes one insert-only agency_white_label_changes
 * row with the actor and a field-level before/after in the same transaction.
 * No central event bus, no Automations.
 *
 * ASSETS. A logo is validated twice (the FormRequest, then here — this class
 * never trusts that validation already ran) with the platform's own
 * ValidBrandingImageRule: magic-byte type detection, raster only (SVG is
 * refused everywhere), 2 MB and 800x200 px bounds. It is stored under
 * images/branding/agency/{agency uid}/ with a content-hashed name, never a
 * client-derived one, and only files inside that Agency's own directory are
 * ever deleted.
 */
class AgencyWhiteLabelManager
{
    public const NAME_MAX = 80;

    /** Asset slot => column. Each slot is validated with ValidBrandingImageRule under its own field name. */
    public const ASSET_COLUMNS = [
        'logo' => 'logo_path',
        'logo_dark' => 'logo_dark_path',
        'logo_compact' => 'logo_compact_path',
        'favicon' => 'favicon_path',
    ];

    /** Text fields written through save() and recorded in the audit trail. */
    public const AUDITED_FIELDS = [
        'display_name', 'tagline', 'accent_color', 'secondary_color', 'support_email', 'support_url',
        'legal_name', 'social_links', 'is_enabled',
    ];

    /** The only social networks accepted, each with the hosts its link may point at. */
    public const SOCIAL_NETWORKS = [
        'facebook' => ['facebook.com', 'fb.com'],
        'instagram' => ['instagram.com'],
        'linkedin' => ['linkedin.com'],
        'x' => ['x.com', 'twitter.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'tiktok' => ['tiktok.com'],
    ];

    public const TAGLINE_MAX = 160;

    public const EMAIL_MAX = 191;

    /** How many audit rows the settings page reads. */
    public const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    public function find(Workspace $agency): ?AgencyWhiteLabelSetting
    {
        return AgencyWhiteLabelSetting::query()->where('agency_workspace_id', $agency->id)->first();
    }

    /** @return Collection<int, AgencyWhiteLabelChange> newest first, bounded */
    public function history(Workspace $agency): Collection
    {
        return AgencyWhiteLabelChange::query()
            ->where('agency_workspace_id', $agency->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /**
     * The three authority checks every write re-derives from the LOCKED Workspace row: owner, live Agency
     * eligibility and the `white_label` entitlement. Shared with AgencyAppDomainService so a domain write
     * can never be authorised more loosely than a brand write.
     *
     * @throws AgencyWhiteLabelException
     */
    public function assertCanManage(Workspace $locked, int $actorUserId): void
    {
        if ((int) $locked->owner_user_id !== $actorUserId) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_OWNER);
        }

        if (! $this->relationships->agencyWorkspaceHasManagementEligibility($locked)) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_ELIGIBLE);
        }

        if (! $this->isEntitled($locked)) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_ENTITLED);
        }
    }

    /** Is white label included in this Agency's plan right now? (Read-only.) */
    public function isEntitled(Workspace $agency): bool
    {
        return $this->entitlements->decideForWorkspace($agency, PlatformFeature::WhiteLabel->value)->allowed;
    }

    /**
     * @param  array<string, mixed>  $data  display_name is required; every other key is applied only when present, so a
     *                                      caller that predates the Branding tab keeps its exact behaviour
     * @param  array<string, UploadedFile>  $assets  logo_dark | logo_compact | favicon (the primary logo is $logo)
     * @param  array<int, string>  $removeAssets  asset keys to clear: logo_dark | logo_compact | favicon
     *
     * @throws AgencyWhiteLabelException
     */
    public function save(
        int $actorUserId,
        Workspace $agency,
        array $data,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
        array $assets = [],
        array $removeAssets = [],
    ): AgencyWhiteLabelSetting {
        // Validated before any lock or file write: a bad value costs nothing.
        $values = $this->normalize($data);

        $uploads = [];

        if ($logo !== null) {
            $uploads['logo'] = $logo;
        }

        foreach ($assets as $key => $file) {
            if ($file instanceof UploadedFile && isset(self::ASSET_COLUMNS[$key]) && $key !== 'logo') {
                $uploads[$key] = $file;
            }
        }

        foreach ($uploads as $key => $file) {
            $this->assertAssetIsValid($file, $key);
        }

        // No file is written for an actor who is not the owner. This is a
        // cheap early refusal, not the authority: the owner is read again
        // from the locked row inside the transaction below.
        if ($uploads !== [] && (int) Workspace::query()->whereKey($agency->id)->value('owner_user_id') !== $actorUserId) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_OWNER);
        }

        $stored = []; // column => [relative path, created by this call]

        try {
            foreach ($uploads as $key => $file) {
                $stored[self::ASSET_COLUMNS[$key]] = $this->storeAsset($agency, $file, $key);
            }
        } catch (Throwable $e) {
            foreach ($stored as [$path, $created]) {
                if ($created) {
                    $this->deleteOwnedFile($agency, $path);
                }
            }

            throw $e;
        }

        $removeColumns = [];

        foreach (array_merge($removeLogo ? ['logo'] : [], $removeAssets) as $key) {
            if (isset(self::ASSET_COLUMNS[$key])) {
                $removeColumns[] = self::ASSET_COLUMNS[$key];
            }
        }

        $replacedPaths = [];

        try {
            $setting = DB::transaction(function () use ($actorUserId, $agency, $values, $stored, $removeColumns, &$replacedPaths): AgencyWhiteLabelSetting {
                /** @var Workspace|null $locked */
                $locked = Workspace::query()->whereKey($agency->id)->lockForUpdate()->first();

                if ($locked === null || (int) $locked->owner_user_id !== $actorUserId) {
                    throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_OWNER);
                }

                if (! $this->relationships->agencyWorkspaceHasManagementEligibility($locked)) {
                    throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_ELIGIBLE);
                }

                if (! $this->isEntitled($locked)) {
                    throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_ENTITLED);
                }

                $existing = $this->find($locked);
                $fields = array_merge(self::AUDITED_FIELDS, array_values(self::ASSET_COLUMNS));
                $before = array_fill_keys($fields, null);
                $before['is_enabled'] = false;

                if ($existing !== null) {
                    $before = array_merge($before, $existing->only($fields));
                }

                $after = $before;

                foreach ($values as $field => $value) {
                    $after[$field] = $value;
                }

                foreach ($removeColumns as $column) {
                    $after[$column] = null;
                }

                foreach ($stored as $column => [$path]) {
                    $after[$column] = $path;
                }

                $changes = [];

                foreach ($after as $field => $value) {
                    if ($before[$field] !== $value) {
                        $changes[$field] = [$before[$field], $value];
                    }
                }

                // Idempotent: nothing differs, nothing is written or audited.
                if ($existing !== null && $changes === []) {
                    return $existing;
                }

                foreach (array_values(self::ASSET_COLUMNS) as $column) {
                    if (isset($changes[$column]) && $before[$column] !== null) {
                        $replacedPaths[] = $before[$column];
                    }
                }

                $setting = $existing ?? new AgencyWhiteLabelSetting(['agency_workspace_id' => $locked->id]);
                $setting->fill($after);
                $setting->updated_by_user_id = $actorUserId;
                $setting->save();

                AgencyWhiteLabelChange::query()->create([
                    'agency_workspace_id' => $locked->id,
                    'changed_by_user_id' => $actorUserId,
                    'change_type' => $this->changeType($existing === null, $changes),
                    'changes' => $changes,
                ]);

                return $setting;
            });
        } catch (Throwable $e) {
            // The transaction rolled back: a file THIS call created is an
            // orphan. A file that already existed (an identical image already
            // in use) is never touched.
            foreach ($stored as [$path, $created]) {
                if ($created) {
                    $this->deleteOwnedFile($agency, $path);
                }
            }

            throw $e;
        }

        // Swap, then delete — never delete-then-write. Only after the commit
        // that stopped pointing at the old file, and never a file still in use
        // by another slot of this same brand.
        $inUse = array_filter(array_map(fn (string $column) => $setting->{$column}, array_values(self::ASSET_COLUMNS)));

        foreach ($replacedPaths as $old) {
            if (! in_array($old, $inUse, true)) {
                $this->deleteOwnedFile($agency, $old);
            }
        }

        return $setting->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{display_name: string, tagline: ?string, accent_color: ?string, support_email: ?string, is_enabled: bool}
     */
    private function normalize(array $data): array
    {
        $name = $this->plainText($data['display_name'] ?? null);

        if ($name === null || mb_strlen($name) > self::NAME_MAX) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_NAME);
        }

        $tagline = $this->plainTextOrNull($data['tagline'] ?? null, AgencyWhiteLabelException::INVALID_TAGLINE);

        if ($tagline !== null && mb_strlen($tagline) > self::TAGLINE_MAX) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_TAGLINE);
        }

        $accent = trim((string) ($data['accent_color'] ?? ''));

        if ($accent !== '') {
            $accent = strtolower($accent);

            if (preg_match('/^#[0-9a-f]{6}$/', $accent) !== 1) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_ACCENT);
            }
        }

        $email = trim((string) ($data['support_email'] ?? ''));

        if ($email !== '' && (mb_strlen($email) > self::EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_SUPPORT_EMAIL);
        }

        $out = [
            'display_name' => $name,
            'tagline' => $tagline,
            'accent_color' => $accent === '' ? null : $accent,
            'support_email' => $email === '' ? null : $email,
            'is_enabled' => filter_var($data['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        if (array_key_exists('secondary_color', $data)) {
            $secondary = strtolower(trim((string) $data['secondary_color']));

            if ($secondary !== '' && preg_match('/^#[0-9a-f]{6}$/', $secondary) !== 1) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_SECONDARY);
            }

            $out['secondary_color'] = $secondary === '' ? null : $secondary;
        }

        if (array_key_exists('support_url', $data)) {
            $out['support_url'] = $this->httpsUrlOrNull($data['support_url'], null, AgencyWhiteLabelException::INVALID_SUPPORT_URL);
        }

        if (array_key_exists('legal_name', $data)) {
            $legal = $this->plainTextOrNull($data['legal_name'], AgencyWhiteLabelException::INVALID_LEGAL_NAME);

            if ($legal !== null && mb_strlen($legal) > 120) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LEGAL_NAME);
            }

            $out['legal_name'] = $legal;
        }

        if (array_key_exists('social_links', $data)) {
            $links = [];

            foreach (self::SOCIAL_NETWORKS as $network => $hosts) {
                $url = $this->httpsUrlOrNull($data['social_links'][$network] ?? null, $hosts, AgencyWhiteLabelException::INVALID_SOCIAL);

                if ($url !== null) {
                    $links[$network] = $url;
                }
            }

            $out['social_links'] = $links === [] ? null : $links;
        }

        return $out;
    }

    /**
     * An absolute https:// URL with no credentials, optionally restricted to a host (or its subdomains).
     *
     * @param  array<int, string>|null  $allowedHosts
     */
    private function httpsUrlOrNull(mixed $value, ?array $allowedHosts, string $reason): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $url = is_string($value) ? trim($value) : '';
        $parts = $url === '' ? false : parse_url($url);

        if ($parts === false || mb_strlen($url) > 255 || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20<>"\x7F]/', $url) === 1) {
            throw AgencyWhiteLabelException::because($reason);
        }

        $host = strtolower($parts['host']);

        if ($allowedHosts !== null) {
            $ok = false;

            foreach ($allowedHosts as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    $ok = true;
                }
            }

            if (! $ok) {
                throw AgencyWhiteLabelException::because($reason);
            }
        } elseif (! str_contains($host, '.')) {
            throw AgencyWhiteLabelException::because($reason);
        }

        return $url;
    }

    /** Plain text only: HTML and control characters are refused, not silently stripped. */
    private function plainText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($text === '' || $text !== strip_tags($text) || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1) {
            return null;
        }

        return $text;
    }

    private function plainTextOrNull(mixed $value, string $reason): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->plainText($value) ?? throw AgencyWhiteLabelException::because($reason);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function changeType(bool $isNew, array $changes): string
    {
        if ($isNew) {
            return AgencyWhiteLabelChange::TYPE_CREATED;
        }

        if (isset($changes['is_enabled'])) {
            return $changes['is_enabled'][1] === true
                ? AgencyWhiteLabelChange::TYPE_ENABLED
                : AgencyWhiteLabelChange::TYPE_DISABLED;
        }

        if (count($changes) === 1 && in_array(array_key_first($changes), array_values(self::ASSET_COLUMNS), true)) {
            return $changes[array_key_first($changes)][1] === null
                ? AgencyWhiteLabelChange::TYPE_LOGO_REMOVED
                : AgencyWhiteLabelChange::TYPE_LOGO_REPLACED;
        }

        return AgencyWhiteLabelChange::TYPE_UPDATED;
    }

    /** @throws AgencyWhiteLabelException */
    private function assertAssetIsValid(UploadedFile $file, string $field): void
    {
        $failure = null;

        (new ValidBrandingImageRule($field))->validate($field, $file, function (string $message) use (&$failure): void {
            $failure = $message;
        });

        if ($failure !== null) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LOGO, str_replace(':attribute', str_replace('_', ' ', $field), $failure));
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LOGO, 'That image must be 2 MB or smaller.');
        }
    }

    /**
     * @return array{0: string, 1: bool} the relative path, and whether THIS call
     *         created the file (false when an identical one already existed)
     *
     * @throws AgencyWhiteLabelException
     */
    private function storeAsset(Workspace $agency, UploadedFile $file, string $field): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LOGO);
        }

        $extension = ValidBrandingImageRule::detectExtension($contents);
        $hash = hash('sha256', $contents);
        $relativeDirectory = self::directoryFor($agency);
        $directory = public_path($relativeDirectory);
        // The primary logo keeps its historical name so existing files stay valid; other slots are prefixed.
        $relativePath = $relativeDirectory . '/' . ($field === 'logo' ? '' : $field . '-') . $hash . '.' . $extension;
        $destination = public_path($relativePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::LOGO_STORAGE_FAILED);
        }

        $alreadyExisted = is_file($destination);

        if (file_put_contents($destination, $contents) === false) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::LOGO_STORAGE_FAILED);
        }

        // Fail closed: never point the row at a file not confirmed correct.
        $written = file_get_contents($destination);

        if ($written === false || hash('sha256', $written) !== $hash) {
            if (! $alreadyExisted) {
                @unlink($destination);
            }

            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::LOGO_STORAGE_FAILED);
        }

        return [$relativePath, ! $alreadyExisted];
    }

    /** The one directory this Agency's assets may live in. */
    public static function directoryFor(Workspace $agency): string
    {
        return 'images/branding/agency/' . Str::lower((string) $agency->uid);
    }

    /** Deletes only a file inside THIS Agency's own directory, never anything else. */
    private function deleteOwnedFile(Workspace $agency, ?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        $prefix = self::directoryFor($agency) . '/';

        if (! str_starts_with($relativePath, $prefix) || str_contains($relativePath, '..')) {
            return;
        }

        $full = public_path($relativePath);

        if (is_file($full)) {
            @unlink($full);
        }
    }
}
