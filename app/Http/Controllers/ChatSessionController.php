<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $chatSession = ChatSession::query()->firstOrCreate(
            ['laravel_session_id' => $request->session()->getId()],
            [
                'status' => 'pending',
                'last_used_at' => now(),
            ],
        );

        $chatSession->forceFill([
            'last_used_at' => now(),
        ])->save();

        return response()->json([
            'session_id' => $chatSession->id,
            'status' => $chatSession->status,
        ]);
    }
}
