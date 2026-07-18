<?php

    namespace App\Http\Requests\Campaigns;

    use Illuminate\Foundation\Http\FormRequest;

    class GenerateAIMessageRequest extends FormRequest
    {
        /**
         * Determine if the user is authorized to make this request.
         */
        public function authorize(): bool
        {
            return true;
        }

        /**
         * Get the validation rules that apply to the request.
         *
         * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
         */
        public function rules(): array
        {
            return [
                'goal'     => 'required|string|max:255',
                'tone'     => 'required|string|max:50',
                'audience' => 'required|string|max:255',
            ];
        }

    }
