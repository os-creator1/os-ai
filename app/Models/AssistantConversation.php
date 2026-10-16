<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ask MotionGrove — one customer's conversation inside ONE Business. See the migration for the scoping rule. */
class AssistantConversation extends Model
{
    protected $table = 'assistant_conversations';

    protected $fillable = [
        'uid', 'workspace_id', 'business_id', 'user_id', 'title', 'summary',
        'summary_through_message_id', 'message_count', 'last_message_at',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'business_id' => 'integer',
        'user_id' => 'integer',
        'summary_through_message_id' => 'integer',
        'message_count' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uid';
    }
}
