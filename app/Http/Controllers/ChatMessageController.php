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
        /** @var array{message: string, cart?: array<int, array{food_id: int, quantity: int}>, filter_context?: array{category: string, property_keys?: array<int, string>}} $validated */
        $validated = $request->validated();

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

        $assistantConfigurationError = $this->assistantConfigurationError();

        $turn = $chatSession->turns()->create([
            'status' => $assistantConfigurationError ? 'failed' : 'pending',
            'user_message' => $validated['message'],
            'cart_context' => $validated['cart'] ?? [],
            'filter_context' => $validated['filter_context'] ?? null,
            'reply' => $this->assistantUnavailableReply($validated['message'], $assistantConfigurationError),
            'cart_actions' => [],
            'filter_action' => null,
            'error' => $assistantConfigurationError,
            'completed_at' => $assistantConfigurationError ? now() : null,
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

    private function assistantConfigurationError(): ?string
    {
        if (! filled(config('services.codex_acp.codex_api_key')) && ! filled(config('services.codex_acp.openai_api_key'))) {
            return 'Set CODEX_API_KEY or OPENAI_API_KEY to enable the ordering assistant.';
        }

        $binary = (string) config('services.codex_acp.binary');

        if (! is_file($binary)) {
            return "Codex ACP binary was not found at {$binary}. Run npm install first.";
        }

        return null;
    }

    private function assistantUnavailableReply(string $message, ?string $assistantConfigurationError): ?string
    {
        if (! $assistantConfigurationError) {
            return null;
        }

        return $this->detectMessageLanguage($message) === 'vi'
            ? 'Trợ lý gọi món chưa được cấu hình. Vui lòng thêm CODEX_API_KEY hoặc OPENAI_API_KEY rồi khởi động lại môi trường dev.'
            : 'The ordering assistant is not configured yet. Add CODEX_API_KEY or OPENAI_API_KEY, then restart the dev environment.';
    }

    private function detectMessageLanguage(string $message): string
    {
        $message = mb_strtolower($message);

        if (preg_match('/[ăâđêôơưáàảãạắằẳẵặấầẩẫậéèẻẽẹếềểễệíìỉĩịóòỏõọốồổỗộớờởỡợúùủũụứừửữựýỳỷỹỵ]/u', $message) === 1) {
            return 'vi';
        }

        $vietnameseMarkers = [
            'anh',
            'cho',
            'chay',
            'com',
            'cua',
            'do',
            'em',
            'ga',
            'goi',
            'hien',
            'khong',
            'mon',
            'mot',
            'nuoc',
            'pho',
            'thit',
            'them',
            'toi',
            'tra',
        ];

        $words = preg_split('/[^a-z]+/u', $message) ?: [];
        $matches = count(array_intersect($vietnameseMarkers, $words));

        return $matches >= 2 ? 'vi' : 'en';
    }
}
