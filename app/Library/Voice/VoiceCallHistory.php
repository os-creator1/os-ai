<?php

namespace App\Library\Voice;

use App\Models\Business;
use App\Models\Contacts;
use App\Models\User;
use App\Models\VoiceCall;
use Illuminate\Support\Collection;

/**
 * Read model for a person's calls. A call belongs to a person when it is linked to the Contact OR its
 * counterpart number is the person's number — always within one Business, so another Business's calls
 * with the same number are never shown.
 */
final class VoiceCallHistory
{
    /** @param  list<string>  $numberVariants E.164 numbers of the person */
    public function forPerson(Business $business, ?Contacts $contact, array $numberVariants, int $limit): Collection
    {
        return VoiceCall::query()
            ->where('business_id', (int) $business->id)
            ->where(function ($q) use ($contact, $numberVariants) {
                $any = false;

                if ($contact !== null) {
                    $q->where('contact_id', (int) $contact->id);
                    $any = true;
                }

                if ($numberVariants !== []) {
                    $any ? $q->orWhereIn('counterpart_number', $numberVariants) : $q->whereIn('counterpart_number', $numberVariants);
                    $any = true;
                }

                if (! $any) {
                    $q->whereRaw('1 = 0');
                }
            })
            ->orderByDesc('initiated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public static function durationLabel(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return $seconds >= 3600
            ? sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))
            : sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    /** @return array{title: string, detail: ?string, tone: string} */
    public static function describe(VoiceCall $call): array
    {
        $status = $call->statusEnum();
        $direction = $call->direction === VoiceCall::DIRECTION_INBOUND ? 'Incoming' : 'Outgoing';
        $parts = [];

        if (($d = self::durationLabel($call->duration_seconds)) !== null) {
            $parts[] = $d;
        }

        $parts[] = ($call->direction === VoiceCall::DIRECTION_INBOUND ? 'to ' : 'from ') . ($call->direction === VoiceCall::DIRECTION_INBOUND ? $call->to_number : $call->from_number);

        if ($call->note !== null && $call->note !== '') {
            $parts[] = 'Note: ' . $call->note;
        }

        return [
            'title' => $direction . ' call · ' . $status->label(),
            'detail' => implode(' · ', $parts),
            'tone' => match (true) {
                $status === \App\Enums\Voice\VoiceCallStatus::Completed => 'success',
                $status->isFinal() => 'warning',
                default => 'neutral',
            },
        ];
    }
}
