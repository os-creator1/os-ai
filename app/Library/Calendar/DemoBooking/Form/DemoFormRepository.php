<?php

namespace App\Library\Calendar\DemoBooking\Form;

use App\Models\DemoFormVersion;
use Illuminate\Support\Facades\DB;

/** Reads and writes the Book a Demo form definition. The live form is the newest published row, else the design default. */
class DemoFormRepository
{
    /** @return array{id: ?int, fields: array<int, array<string, mixed>>} */
    public function published(): array
    {
        $row = DemoFormVersion::query()->where('status', DemoFormVersion::PUBLISHED)->orderByDesc('id')->first();
        if ($row === null) {
            return ['id' => null, 'fields' => DemoFormDefaults::fields()];
        }

        return ['id' => (int) $row->id, 'fields' => $this->restore((array) $row->fields)];
    }

    /** The draft being edited, or a copy of what is live when there is none. @return array<int, array<string, mixed>> */
    public function draft(): array
    {
        $row = DemoFormVersion::query()->where('status', DemoFormVersion::DRAFT)->first();

        return $row !== null ? $this->restore((array) $row->fields) : $this->published()['fields'];
    }

    public function hasDraft(): bool
    {
        return DemoFormVersion::query()->where('status', DemoFormVersion::DRAFT)->exists();
    }

    /** @param array<int, array<string, mixed>> $fields already normalized */
    public function saveDraft(array $fields, int $userId): void
    {
        DB::transaction(function () use ($fields, $userId): void {
            $row = DemoFormVersion::query()->where('status', DemoFormVersion::DRAFT)->lockForUpdate()->first()
                ?? new DemoFormVersion(['status' => DemoFormVersion::DRAFT]);
            $row->fill(['fields' => $fields, 'updated_by' => $userId])->save();
        });
    }

    /** Promotes the draft to the live form. The draft row becomes the immutable published version. */
    public function publish(int $userId): ?int
    {
        return DB::transaction(function () use ($userId): ?int {
            $row = DemoFormVersion::query()->where('status', DemoFormVersion::DRAFT)->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $row->fill(['status' => DemoFormVersion::PUBLISHED, 'published_at' => now(), 'updated_by' => $userId])->save();

            return (int) $row->id;
        });
    }

    public function discardDraft(): void
    {
        DemoFormVersion::query()->where('status', DemoFormVersion::DRAFT)->delete();
    }

    /** Definitions saved by an older release still render: every property gets its default. */
    private function restore(array $fields): array
    {
        $base = ['placeholder' => '', 'help' => '', 'required' => false, 'visible' => true, 'half' => false, 'show_optional' => false, 'options' => [], 'system' => false];

        return array_map(static fn (array $f): array => $f + $base, $fields);
    }
}
