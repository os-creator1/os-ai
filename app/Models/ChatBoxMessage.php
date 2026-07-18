<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ChatBoxMessage extends Model
{
    protected $fillable = [
        'box_id',
        'message',
        'media_url',
        'sms_type',
        'direction',
        'sending_server_id',
        'send_by',
    ];

    protected static function booted()
    {
        static::created(function ($message) {

            // Only inbound messages
            if ($message->direction !== 'inbound') return;

            // Get phone from related chat box
            $chatBox = $message->chatBox()->first();
            if(!$chatBox || !$chatBox->from) return;

            $phone = $chatBox->from;

            // Find or create conversation
            $conversation = DB::table('cg_ai_conversations')->where('phone', $phone)->first();

            if (!$conversation) {
                $conversationId = DB::table('cg_ai_conversations')->insertGetId([
                    'phone' => $phone,
                    'created_at' => now()
                ]);
            } else {
                $conversationId = $conversation->id;
            }

            // Store inbound message
            DB::table('cg_ai_messages')->insert([
                'conversation_id' => $conversationId,
                'role' => 'user',
                'content' => $message->message,
                'created_at' => now()
            ]);
        });
    }

    public function chatBox()
    {
        return $this->belongsTo(ChatBox::class, 'box_id', 'id');
    }
}
