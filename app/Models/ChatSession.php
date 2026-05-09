<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table('chat_sessions')]
#[Fillable([
    'laravel_session_id',
    'acp_session_id',
    'status',
    'warmed_at',
    'last_used_at',
    'error',
    'metadata',
])]
class ChatSession extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return HasMany<ChatTurn, $this>
     */
    public function turns(): HasMany
    {
        return $this->hasMany(ChatTurn::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'warmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
