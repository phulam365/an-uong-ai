<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $language = $this->normalizeLanguage($request->string('language')->toString());

        $chatSession = ChatSession::query()->firstOrCreate(
            ['laravel_session_id' => $request->session()->getId()],
            [
                'status' => 'pending',
                'last_used_at' => now(),
            ],
        );

        $metadata = is_array($chatSession->metadata) ? $chatSession->metadata : [];

        $chatSession->forceFill([
            'metadata' => [
                ...$metadata,
                'language' => $language,
            ],
            'last_used_at' => now(),
        ])->save();

        return response()->json([
            'session_id' => $chatSession->id,
            'status' => $chatSession->status,
        ]);
    }

    private function normalizeLanguage(?string $language): string
    {
        return $language === 'en' ? 'en' : 'vi';
    }
}
