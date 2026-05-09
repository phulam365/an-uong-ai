<?php

namespace App\Http\Controllers;

use App\ChatTurnResponder;
use App\Http\Requests\StoreChatMessageRequest;
use App\Models\ChatSession;
use App\Models\ChatTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function store(StoreChatMessageRequest $request, ChatTurnResponder $responder): JsonResponse
    {
        /** @var array{message: string, cart?: array<int, array{food_id: int, quantity: int}>} $validated */
        $validated = $request->validated();
        $language = $this->normalizeLanguage($validated['language'] ?? null);

        $chatSession = ChatSession::query()->firstOrCreate(
            ['laravel_session_id' => $request->session()->getId()],
            [
                'status' => 'pending',
                'last_used_at' => now(),
            ],
        );

        $currentLanguage = $this->normalizeLanguage($chatSession->metadata['language'] ?? null);
        $metadata = is_array($chatSession->metadata) ? $chatSession->metadata : [];

        $chatSession->forceFill([
            'last_used_at' => now(),
            'metadata' => [
                ...$metadata,
                'language' => $language,
            ],
        ]);

        if ($currentLanguage !== $language && $chatSession->acp_session_id !== null) {
            $chatSession->acp_session_id = null;
        }

        $chatSession->save();

        $turn = $chatSession->turns()->create([
            'status' => 'pending',
            'user_message' => $validated['message'],
            'cart_context' => $validated['cart'] ?? [],
            'cart_actions' => [],
        ]);

        $turn = $this->waitForTurn($turn);

        return response()->json($responder->toArray($turn));
    }

    public function show(Request $request, ChatTurn $turn, ChatTurnResponder $responder): JsonResponse
    {
        abort_unless(
            $turn->chatSession()->where('laravel_session_id', $request->session()->getId())->exists(),
            404,
        );

        return response()->json($responder->toArray($turn->refresh()));
    }

    private function waitForTurn(ChatTurn $turn): ChatTurn
    {
        $deadline = microtime(true) + 4;

        do {
            $turn->refresh();

            if ($turn->status !== 'pending' && $turn->status !== 'processing') {
                return $turn;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        return $turn->refresh();
    }

    private function normalizeLanguage(?string $language): string
    {
        return $language === 'en' ? 'en' : 'vi';
    }
}
