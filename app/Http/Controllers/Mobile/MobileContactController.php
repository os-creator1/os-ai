<?php

namespace App\Http\Controllers\Mobile;

use App\Library\Mobile\MobileBusinessAccess;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only Contact profile. Business scope + `view_contact` + `crm` entitlement + the Contact's Location rule. */
class MobileContactController extends MobileController
{
    public function show(Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CRM, 'view_contact');

        if ($resolved === null) {
            return $this->notFound();
        }

        [, $business] = $resolved;
        $contact = Contacts::query()->where('uid', $uid)->where('business_id', $business->id)->first();

        if ($contact !== null && $contact->location_id !== null) {
            $location = $contact->location;

            if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) $this->actor($request)->id, $location)) {
                $contact = null;
            }
        }

        if ($contact === null) {
            return $this->notFound();
        }

        $fields = DB::table('contacts_custom_field as value')
            ->join('contact_group_fields as field', 'field.id', '=', 'value.field_id')
            ->where('value.contact_id', $contact->id)->where('field.visible', true)
            ->orderBy('field.id')->orderBy('value.id')
            ->get(['field.tag', 'field.label', 'value.value']);

        $byTag = [];
        foreach ($fields as $f) {
            $byTag[$f->tag] ??= (string) $f->value;
        }

        $name = trim(($byTag['FIRST_NAME'] ?? '') . ' ' . ($byTag['LAST_NAME'] ?? ''));

        return response()->json(['data' => [
            'uid' => $contact->uid,
            'display_name' => $name !== '' ? $name : null,
            'phone' => '+' . $contact->phone,
            'email' => $byTag['EMAIL'] ?? null,
            'subscribed' => $contact->status !== Contacts::STATUS_UNSUBSCRIBE,
            'fields' => $fields->map(fn ($f) => ['tag' => $f->tag, 'label' => $f->label, 'value' => $f->value])->values(),
            'created_at' => $contact->created_at?->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }
}
