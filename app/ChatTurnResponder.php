<?php

namespace App;

use App\Models\ChatTurn;

class ChatTurnResponder
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ChatTurn $turn): array
    {
        return [
            'turn_id' => $turn->id,
            'status' => $turn->status,
            'reply' => $turn->reply,
            'cart_actions' => $turn->cart_actions ?? [],
            'filter_action' => $turn->filter_action,
            'display_action' => $turn->display_action,
            'error' => $turn->error,
        ];
    }
}
