<?php

namespace App\Http\Requests\Contacts;

use App\Models\ContactGroups;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property mixed customer_id
 * @property mixed name
 */
class UpdateContactGroup extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('update_contact_group');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $routeContact = $this->route('contact');

        if ($routeContact instanceof ContactGroups) {
            // API route (out of this contract's scope) — unchanged, model already bound.
            $id = $routeContact->id;
        } else {
            // Web customer route, post-remediation — raw uid string; resolve the caller's own owned group.
            $contact = ContactGroups::where('uid', $routeContact)
                ->where('customer_id', $this->user()->id)
                ->first();

            abort_unless($contact, 404);

            $id = $contact->id;
        }

        $customer_id = $this->user()->id;
        $name        = $this->name;

        return [
                'name'           => ['required',
                        Rule::unique('contact_groups')->where(function ($query) use ($customer_id, $name) {
                            return $query->where('customer_id', $customer_id)->where('name', $name);
                        })->ignore($id)],
        ];
    }

    /**
     * custom message
     *
     * @return string[]
     */
    public function messages(): array
    {
        return [
                'name.unique' => __('locale.contacts.contact_group_available', ['name' => $this->name]),
        ];
    }
}
