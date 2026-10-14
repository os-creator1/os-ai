<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantMessage extends Model
{
    public const UPDATED_AT = null;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    protected $table = 'assistant_messages';

    protected $fillable = ['conversation_id', 'uid', 'client_message_id', 'role', 'kind', 'body', 'payload'];

    protected $casts = ['conversation_id' => 'integer', 'payload' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
