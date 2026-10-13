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
        'trial_body' => "The share of your trial's included AI set aside for conversations with the assistant has been used.\n\nUpgrade to a paid MotionGrove plan for a much higher monthly allowance. Your other MotionGrove features will keep working.",
    ],
    'unavailable' => [
        'title' => 'AI is not available on this account right now',
        'body' => "AI features are not included while your account is in its current state. Your other MotionGrove features keep working.",
        'trial_expired_body' => "Your free trial has ended, so its included AI is no longer available. Choose a paid plan to continue using the AI assistant. Your other MotionGrove features keep working.",
    ],
    'nearing_limit' => 'You have used most of your included AI. Everything else keeps working.',
    'provider_failure' => [
        'title' => "I couldn't complete that just now",
        'body' => "Something went wrong reaching the assistant. Nothing was changed. Please try again in a moment.",
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
