<?php

/*
| Ask MotionGrove — customer-facing copy for the AI allowance states and the
| assistant's fixed messages. Deliberately free of any amount, token count,
| model or provider name: a customer is told a STATE, never an internal cost.
*/
return [
    'trial_limit' => [
        'title' => "You've used your trial's included AI",
        'body' => "You've reached the AI usage limit included with your free trial.\n\nUpgrade to a paid MotionGrove plan to unlock a significantly higher monthly AI allowance and continue using your AI assistant.\n\nYour other MotionGrove features will keep working.",
        'cta' => 'Upgrade plan',
        'owner_only' => "Ask your account owner or an admin to upgrade the plan — they manage the subscription.",
    ],
    'monthly_limit' => [
        'title' => "This month's included AI is used up",
        'body' => "Your plan's included AI allowance for this month has been used.\n\nYour other MotionGrove features keep working, and the assistant returns when your allowance renews on :date.",
    ],
    'interactive_limit' => [
        'title' => 'Assistant conversations are paused for now',
        'body' => "The share of your included AI set aside for conversations with the assistant has been used.\n\nMotionGrove's background work is unaffected and your other features keep working. Conversations resume when your allowance renews on :date.",
        'trial_body' => "The share of your trial's included AI set aside for conversations with the assistant has been used.\n\nMotionGrove's background work is unaffected and your other features keep working.",
    ],
    'unavailable' => [
        'title' => 'AI is not available on this account right now',
        'body' => "AI features are not included while your account is in its current state. Your other MotionGrove features keep working.",
        'trial_expired_body' => "Your free trial has ended, so its included AI is no longer available. Your other MotionGrove features keep working.",
    ],
    'nearing_limit' => 'You have used most of your included AI. Everything else keeps working.',
    'provider_failure' => [
        'title' => "I couldn't complete that just now",
        'body' => "Something went wrong reaching the assistant. Nothing was changed. Please try again in a moment.",
    ],
    'data_notice' => [
        'title' => 'How Ask MotionGrove uses AI',
        'body_until_deleted' => "To answer, Ask MotionGrove uses an AI service run by a third-party provider, and the details relevant to your question (for example appointment details or the wording of a customer message) can be sent to it; names and phone numbers are kept to a minimum. Conversations are saved in MotionGrove until you delete them, so you can come back to them later; you can delete one any time from your chat history. Deleting it removes its messages and summary from MotionGrove; a minimal record of any action you approved (what, when, by whom) may be kept for accountability. MotionGrove cannot delete what the AI provider has already received; that is governed by the provider's own terms.",
        'body' => "To answer, Ask MotionGrove uses an AI service run by a third-party provider, and the details relevant to your question (for example appointment details or the wording of a customer message) can be sent to it; names and phone numbers are kept to a minimum. Conversations are saved in MotionGrove for :days days, and you can delete one any time with Delete conversation. Deleting it removes its messages and summary from MotionGrove; a minimal record of any action you approved (what, when, by whom) may be kept for accountability. MotionGrove cannot delete what the AI provider has already received; that is governed by the provider's own terms.",
    ],
    'timeout' => [
        'title' => 'That took too long',
        'body' => 'I could not finish that in time. Nothing was changed. Please try again.',
    ],
    'too_large' => [
        'title' => 'That is too much to take in at once',
        'body' => 'There is too much to consider for that question in one go. Try asking about one thing at a time — nothing was changed.',
    ],
    'ai_off' => [
        'title' => 'The assistant is switched off',
        'body' => 'AI is not enabled for this platform right now. Everything else keeps working.',
    ],
    'not_entitled' => [
        'title' => 'The assistant is not part of your plan or role',
        'body' => 'Either it is not included in your plan, or your role does not include it. Nothing was changed.',
    ],
];
