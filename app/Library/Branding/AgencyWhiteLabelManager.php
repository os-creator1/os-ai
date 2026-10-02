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

    /** Is white label included in this Agency's plan right now? (Read-only.) */
    public function isEntitled(Workspace $agency): bool
    {
        return $this->entitlements->decideForWorkspace($agency, PlatformFeature::WhiteLabel->value)->allowed;
    }

    /**
     * @param  array{display_name?: mixed, tagline?: mixed, accent_color?: mixed, support_email?: mixed, is_enabled?: mixed}  $data
     *
     * @throws AgencyWhiteLabelException
     */
    public function save(
        int $actorUserId,
        Workspace $agency,
        array $data,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
    ): AgencyWhiteLabelSetting {
        // Validated before any lock or file write: a bad value costs nothing.
        $values = $this->normalize($data);

        if ($logo !== null) {
            $this->assertLogoIsValid($logo);

            // No file is written for an actor who is not the owner. This is a
            // cheap early refusal, not the authority: the owner is read again
            // from the locked row inside the transaction below.
            if ((int) Workspace::query()->whereKey($agency->id)->value('owner_user_id') !== $actorUserId) {
                throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::NOT_OWNER);
            }
        }

        [$newLogoPath, $newLogoFileCreated] = $logo !== null ? $this->storeLogo($agency, $logo) : [null, false];
        $previousLogoPath = null;

        try {
            $setting = DB::transaction(function () use ($actorUserId, $agency, $values, $newLogoPath, $removeLogo, &$previousLogoPath): AgencyWhiteLabelSetting {
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
                $before = $existing !== null
                    ? $existing->only(['display_name', 'tagline', 'accent_color', 'support_email', 'is_enabled', 'logo_path'])
                    : ['display_name' => null, 'tagline' => null, 'accent_color' => null, 'support_email' => null, 'is_enabled' => false, 'logo_path' => null];

                $after = $before;
                $after['display_name'] = $values['display_name'];
                $after['tagline'] = $values['tagline'];
                $after['accent_color'] = $values['accent_color'];
                $after['support_email'] = $values['support_email'];
                $after['is_enabled'] = $values['is_enabled'];

                if ($newLogoPath !== null) {
                    $after['logo_path'] = $newLogoPath;
                } elseif ($removeLogo) {
                    $after['logo_path'] = null;
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

                $previousLogoPath = $before['logo_path'];

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
            // orphan. A file that already existed (an identical logo already
            // in use) is never touched.
            if ($newLogoFileCreated) {
                $this->deleteOwnedFile($agency, $newLogoPath);
            }

            throw $e;
        }

        // Swap, then delete — never delete-then-write. Only after the commit
        // that stopped pointing at the old file.
        if ($previousLogoPath !== null && $previousLogoPath !== $setting->logo_path) {
            $this->deleteOwnedFile($agency, $previousLogoPath);
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

        return [
            'display_name' => $name,
            'tagline' => $tagline,
            'accent_color' => $accent === '' ? null : $accent,
            'support_email' => $email === '' ? null : $email,
            'is_enabled' => filter_var($data['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
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

        if (array_keys($changes) === ['logo_path']) {
            return $changes['logo_path'][1] === null
                ? AgencyWhiteLabelChange::TYPE_LOGO_REMOVED
                : AgencyWhiteLabelChange::TYPE_LOGO_REPLACED;
        }

        return AgencyWhiteLabelChange::TYPE_UPDATED;
    }

    /** @throws AgencyWhiteLabelException */
    private function assertLogoIsValid(UploadedFile $file): void
    {
        $failure = null;

        (new ValidBrandingImageRule('logo'))->validate('logo', $file, function (string $message) use (&$failure): void {
            $failure = $message;
        });

        if ($failure !== null) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LOGO, str_replace(':attribute', 'logo', $failure));
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LOGO, 'The logo must be 2 MB or smaller.');
        }
    }

    /**
     * @return array{0: string, 1: bool} the relative path, and whether THIS call
     *         created the file (false when an identical one already existed)
     *
     * @throws AgencyWhiteLabelException
     */
    private function storeLogo(Workspace $agency, UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false || $contents === '') {
            throw AgencyWhiteLabelException::because(AgencyWhiteLabelException::INVALID_LOGO);
        }

        $extension = ValidBrandingImageRule::detectExtension($contents);
        $hash = hash('sha256', $contents);
        $relativeDirectory = self::directoryFor($agency);
        $directory = public_path($relativeDirectory);
        $relativePath = $relativeDirectory . '/' . $hash . '.' . $extension;
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
