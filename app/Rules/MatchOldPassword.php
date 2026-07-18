<?php

    namespace App\Rules;


    use Closure;
    use Illuminate\Contracts\Validation\ValidationRule;
    use Illuminate\Support\Facades\Hash;

    class MatchOldPassword implements ValidationRule
    {
        /**
         * Create a new rule instance.
         *
         * @return void
         */
        public function __construct()
        {
            //
        }

        /**
         * @param string  $attribute
         * @param mixed   $value
         * @param Closure $fail
         */
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if ( ! Hash::check($value, auth()->user()->password)) {
                $fail(__('locale.customer.old_password_not_matched', ['attribute' => $attribute]));
            }
        }

    }
