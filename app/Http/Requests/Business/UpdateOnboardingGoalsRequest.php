<?php

namespace App\Http\Requests\Business;

use App\Enums\Business\BusinessGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateOnboardingGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'primary_goals' => ['required', 'array', 'min:1', 'max:2'],
            'primary_goals.*' => ['distinct', new Enum(BusinessGoal::class)],
        ];
    }
}
