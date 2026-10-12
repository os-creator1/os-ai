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
            // Any number of goals; none is allowed too ("set up later").
            'primary_goals' => ['nullable', 'array'],
            'primary_goals.*' => ['distinct', new Enum(BusinessGoal::class)],
            // One optional "start here" goal; it must be one of the selected goals.
            'priority_goal' => ['nullable', new Enum(BusinessGoal::class), 'in_array:primary_goals.*'],
        ];
    }
}
