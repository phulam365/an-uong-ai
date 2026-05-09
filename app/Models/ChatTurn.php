<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('chat_turns')]
#[Fillable([
    'chat_session_id',
    'status',
    'user_message',
    'cart_context',
    'filter_context',
    'reply',
    'cart_actions',
    'filter_action',
    'display_action',
    'raw_response',
    'error',
    'completed_at',
])]
class ChatTurn extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'cart_actions' => '[]',
    ];

    /**
     * @return BelongsTo<ChatSession, $this>
     */
    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    /**
     * @param  Builder<ChatTurn>  $query
     * @return Builder<ChatTurn>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cart_context' => 'array',
            'filter_context' => 'array',
            'cart_actions' => 'array',
            'filter_action' => 'array',
            'display_action' => 'array',
            'raw_response' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
