<?php

/*
| Ask MotionGrove — the bounds. Every limit here caps how much work ONE customer request can cause; none of them is
| a spending amount (spending is config/ai.php, enforced by AiGateway).
*/
return [
    // Model calls one customer message may cause (tool selection, optional follow-up, final answer).
    'max_model_iterations' => (int) env('ASSISTANT_MAX_MODEL_ITERATIONS', 3),

    // Tool calls the model may request per step, and in total per message.
    'max_tool_calls_per_step' => 3,
    'max_tool_calls_per_message' => 5,

    // Characters of one tool result handed back to the model, and of a customer question.
    'tool_result_max_chars' => 2200,
    'question_max_chars' => 600,

    // Turns replayed verbatim; older turns are folded into the compacted summary.
    'history_window_messages' => 8,
    // Compact once this many characters of un-summarised older turns exist.
    'compaction_trigger_chars' => 2400,
    'summary_max_chars' => 900,
    // Hard ceiling of stored turns per conversation; at the ceiling a new conversation is started.
    'conversation_max_messages' => 120,

    // How long a prepared action may be approved.
    'proposal_ttl_minutes' => 30,

    // The model's answer text is clipped to this many characters before it is stored or shown.
    'answer_max_chars' => 1600,
];
