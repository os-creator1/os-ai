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

    // One explicit wall-clock budget for a whole assistant turn: compaction, every model call and every tool call.
    // A turn that cannot finish inside it stops, says so, and stays retryable. The per-conversation lock lease is
    // derived from it (budget + margin), so the lease always outlives the work it protects.
    'turn_budget_seconds' => (int) env('ASSISTANT_TURN_BUDGET_SECONDS', 75),
    'turn_lock_margin_seconds' => 45,
    // A model call is only started if at least this many seconds of the budget remain (the route's provider timeout).
    'min_seconds_for_model_call' => 30,

    // Execution lease for an approved action. A claim that outlives it may be re-claimed ONLY by the fenced protocol
    // in AssistantActionService (a live executor holds the proposal's row lock, so it cannot be superseded).
    'action_lease_seconds' => 60,

    // The input envelope: the serialized request must fit the route's max_input_tokens, measured with the gateway's own
    // estimator, minus this headroom (basis points of the route ceiling kept free).
    'input_headroom_bps' => 500,

    // Retention. Conversations (messages, summary, facts) are deleted this many days after their last message; the
    // customer can delete one at any time. 90 by default. A positive value is bounded to 1..3650; 0 means "keep until the
    // customer deletes it (or the account is deleted)" — an explicit, opt-in policy that is NOT the default, because keeping
    // customers' conversations indefinitely is a privacy/data-protection decision for the operator, not a code default.
    // The disclosure shown to customers (AssistantRetention::disclosure) always states whichever policy is in force.
    // Action audit facts are kept, scrubbed of text and PII.
    'retention_days' => (int) env('ASSISTANT_RETENTION_DAYS', 90),

    // History. Conversations listed per page in the history panel, and messages loaded per page when scrolling up.
    'history_page_size' => 20,
    'message_page_size' => 40,
    'message_page_max' => 60,

    // How long a prepared reply stays openable (pre-filled) in Follow-ups after it was handed off.
    'handoff_ttl_minutes' => 60,

    // How long a first message (no conversation named) waits for an identical in-flight first message to finish creating its
    // conversation, so the pair cannot both create one. Creation takes milliseconds; this is only a safety bound.
    'first_message_lock_wait_seconds' => 3,

    // How long a customer's "Delete conversation" waits for a reply that is still being worked on before answering "busy".
    'delete_wait_seconds' => (int) env('ASSISTANT_DELETE_WAIT_SECONDS', 4),
];
