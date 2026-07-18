<?php

    namespace App\Rules;

    use App\Models\Keywords;

    use Closure;
    use Illuminate\Contracts\Validation\ValidationRule;

    class UniqueKeyword implements ValidationRule
    {

        protected string $keyword;
        protected int    $user_id;

        /**
         * Create a new rule instance.
         *
         * @return void
         */
        public function __construct($keyword, $user_id)
        {
            $this->keyword = $keyword;
            $this->user_id = $user_id;
        }

        /**
         * @param string  $attribute
         * @param mixed   $value
         * @param Closure $fail
         */
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if (Keywords::where('keyword_name', $this->keyword)->where('user_id', $this->user_id)->exists()) {
                $fail(__('locale.keywords.keyword_availability', ['keyword' => $this->keyword]));
            }
        }

    }
