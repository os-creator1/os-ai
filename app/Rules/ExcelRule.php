<?php

    namespace App\Rules;


    use Closure;
    use Illuminate\Contracts\Validation\ValidationRule;
    use Illuminate\Http\UploadedFile;

    class ExcelRule implements ValidationRule
    {
        private UploadedFile $file;

        /**
         * @param UploadedFile $file
         */
        public function __construct(UploadedFile $file)
        {
            $this->file = $file;
        }

        /**
         * @param string  $attribute
         * @param mixed   $value
         * @param Closure $fail
         */
        public function validate(string $attribute, mixed $value, Closure $fail): void
        {
            if ($this->file->getClientOriginalExtension() != 'csv' && $this->file->getClientOriginalExtension() != 'xlsx') {
                $fail('The excel file must be a file of type: csv, xlsx.');
            }
        }

    }
