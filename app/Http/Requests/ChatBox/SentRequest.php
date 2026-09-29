<?php

    namespace App\Http\Requests\ChatBox;

    use App\Library\Messaging\ManagedDispatchDelegate;
    use App\Models\Business;
    use App\Rules\Phone;
    use Illuminate\Foundation\Http\FormRequest;

    class SentRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         *
         * @return bool
         */
        public function authorize(): bool
        {
            return $this->user()->can('chat_box');
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * Customer Experience Slice 3 correction — a managed Business's
         * compose form no longer submits 'sender_id' at all (the outbound
         * sender is resolved server-side from the Business's own managed
         * identity, never from this request), so requiring it unconditionally
         * rejected every managed send with a 422 before the controller ever
         * ran. This is a best-effort classification only, exactly like
         * ChatBoxController's own isManaged() checks — the controller's
         * tenancy-verified Business remains the only authority for what the
         * send actually does; a wrong guess here only changes which
         * validation error a malformed request receives, never what gets sent.
         *
         * @return array
         */
        public function rules(): array
        {
            $businessUid = $this->route('businessUid');
            $businessId = is_string($businessUid) ? Business::query()->where('uid', $businessUid)->value('id') : null;
            $isManaged = $businessId !== null && ManagedDispatchDelegate::isManaged((int) $businessId);

            return [
                'sender_id' => $isManaged ? ['nullable'] : ['required', new Phone($this->sender_id)],
                'recipient' => 'required',
                'message'   => 'required',
                'idempotency_token' => 'required|uuid',
            ];
        }

    }
