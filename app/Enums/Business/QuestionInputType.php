<?php

namespace App\Enums\Business;

/**
 * Website Guided Generation contract §6.2 -- `question_packs.questions[].
 * input_type` is enum-backed to exactly these five values.
 */
enum QuestionInputType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Boolean = 'boolean';
}
