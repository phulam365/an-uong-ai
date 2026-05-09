<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use App\Models\ChatTurn;
use App\Models\Food;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('chat:acp-bridge {--idle-timeout=900} {--poll=1} {--turn-timeout=90}')]
#[Description('Run the local Codex ACP bridge for menu chat turns')]
class ChatAcpBridge extends Command
{
    /**
     * @var resource|null
     */
    private mixed $process = null;

    /**
     * @var array<int, resource>
     */
    private array $pipes = [];

    private int $nextRequestId = 1;

    /**
     * @var array<string, bool>
     */
    private array $loadedAcpSessions = [];

    private ?string $capturingSessionId = null;

    private string $capturedText = '';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->hasApiKey()) {
            $this->error('Set CODEX_API_KEY or OPENAI_API_KEY before running chat:acp-bridge.');

            return self::FAILURE;
        }

        while (true) {
            $this->closeIdleSessions();

            $turn = ChatTurn::query()
                ->pending()
                ->with('chatSession')
                ->oldest()
                ->first();

            if (! $turn) {
                sleep(max((int) $this->option('poll'), 1));

                continue;
            }

            try {
                $this->processTurn($turn);
            } catch (Throwable $exception) {
                $turn->forceFill([
                    'status' => 'failed',
                    'reply' => 'Mình chưa kết nối được trợ lý gọi món. Vui lòng thử lại sau.',
                    'cart_actions' => [],
                    'error' => $exception->getMessage(),
                    'completed_at' => now(),
                ])->save();

                $turn->chatSession?->forceFill([
                    'status' => 'error',
                    'error' => $exception->getMessage(),
                ])->save();

                $this->error($exception->getMessage());
            }
        }
    }

    private function processTurn(ChatTurn $turn): void
    {
        $turn->forceFill(['status' => 'processing'])->save();

        $chatSession = $turn->chatSession()->firstOrFail();
        $language = $this->resolveChatLanguage($chatSession);
        $acpSessionId = $this->ensureAcpSession($chatSession);

        $responseText = $this->prompt(
            $acpSessionId,
            $this->buildTurnPrompt($turn, $language),
            (int) $this->option('turn-timeout'),
        );
        $rawResponse = $this->decodeJsonEnvelope($responseText, $language);
        $cartActions = $this->validatedCartActions($rawResponse['cart_actions'] ?? []);

        $turn->forceFill([
            'status' => 'completed',
            'reply' => $this->normalizedReply($rawResponse, $responseText, $language),
            'cart_actions' => $cartActions,
            'raw_response' => $rawResponse,
            'error' => null,
            'completed_at' => now(),
        ])->save();

        $chatSession->forceFill([
            'status' => 'ready',
            'last_used_at' => now(),
            'error' => null,
        ])->save();
    }

    private function ensureAcpSession(ChatSession $chatSession): string
    {
        $this->ensureAgent();

        if ($chatSession->acp_session_id && isset($this->loadedAcpSessions[$chatSession->acp_session_id])) {
            return $chatSession->acp_session_id;
        }

        if ($chatSession->acp_session_id) {
            try {
                $this->rpc('session/load', [
                    'sessionId' => $chatSession->acp_session_id,
                    'cwd' => $this->workspacePath($chatSession),
                    'mcpServers' => [],
                ], 30);

                $this->loadedAcpSessions[$chatSession->acp_session_id] = true;

                return $chatSession->acp_session_id;
            } catch (Throwable) {
                $chatSession->forceFill(['acp_session_id' => null])->save();
            }
        }

        $result = $this->rpc('session/new', [
            'cwd' => $this->workspacePath($chatSession),
            'mcpServers' => [],
        ], 30);

        $acpSessionId = (string) data_get($result, 'sessionId');

        if ($acpSessionId === '') {
            throw new \RuntimeException('Codex ACP did not return a session ID.');
        }

        $this->loadedAcpSessions[$acpSessionId] = true;
        $chatSession->forceFill([
            'acp_session_id' => $acpSessionId,
            'status' => 'warming',
            'last_used_at' => now(),
        ])->save();

        $this->setReadOnlyMode($acpSessionId);
        $this->prompt(
            $acpSessionId,
            $this->buildWarmPrompt($chatSession),
            (int) $this->option('turn-timeout'),
        );

        $chatSession->forceFill([
            'status' => 'ready',
            'warmed_at' => now(),
            'error' => null,
        ])->save();

        return $acpSessionId;
    }

    private function ensureAgent(): void
    {
        if ($this->isProcessRunning()) {
            return;
        }

        $binary = (string) config('services.codex_acp.binary');

        if (! is_file($binary)) {
            throw new \RuntimeException("Codex ACP binary was not found at {$binary}. Run npm install first.");
        }

        File::ensureDirectoryExists($this->agentHomePath());
        File::ensureDirectoryExists(storage_path('app/private/chat-acp-workspaces'));

        $baseEnvironment = getenv();
        $baseEnvironment = is_array($baseEnvironment) ? $baseEnvironment : [];

        $environment = array_filter([
            ...$baseEnvironment,
            'CODEX_API_KEY' => config('services.codex_acp.codex_api_key'),
            'OPENAI_API_KEY' => config('services.codex_acp.openai_api_key'),
            'CODEX_HOME' => $this->agentHomePath(),
            'HOME' => $this->agentHomePath(),
        ], fn (mixed $value): bool => $value !== null && $value !== false);

        $this->process = proc_open(
            [$binary],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            base_path(),
            $environment,
        );

        if (! is_resource($this->process)) {
            throw new \RuntimeException('Unable to start Codex ACP.');
        }

        $this->loadedAcpSessions = [];
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->rpc('initialize', [
            'protocolVersion' => 1,
            'clientCapabilities' => (object) [],
            'clientInfo' => [
                'name' => 'an-uong-ai-menu',
                'title' => 'An Uong AI Menu',
                'version' => '1.0.0',
            ],
        ], 10);
    }

    private function isProcessRunning(): bool
    {
        if (! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return (bool) ($status['running'] ?? false);
    }

    private function setReadOnlyMode(string $acpSessionId): void
    {
        try {
            $this->rpc('session/set_config_option', [
                'sessionId' => $acpSessionId,
                'configId' => 'mode',
                'value' => 'read-only',
            ], 10);
        } catch (Throwable $exception) {
            $this->warn("Unable to set Codex ACP read-only mode: {$exception->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>|null
     */
    private function rpc(string $method, array $params, int $timeoutSeconds): ?array
    {
        $id = $this->nextRequestId++;

        $this->writeMessage([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            foreach ($this->readMessages() as $message) {
                if (($message['id'] ?? null) === $id) {
                    if (array_key_exists('error', $message)) {
                        throw new \RuntimeException((string) data_get($message, 'error.message', 'Codex ACP request failed.'));
                    }

                    /** @var array<string, mixed>|null $result */
                    $result = $message['result'] ?? null;

                    return $result;
                }

                $this->handleAgentMessage($message);
            }

            usleep(25_000);
        }

        throw new \RuntimeException("Codex ACP request timed out: {$method}.");
    }

    private function prompt(string $acpSessionId, string $prompt, int $timeoutSeconds): string
    {
        $this->capturingSessionId = $acpSessionId;
        $this->capturedText = '';

        $this->rpc('session/prompt', [
            'sessionId' => $acpSessionId,
            'prompt' => [
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ], $timeoutSeconds);

        $text = trim($this->capturedText);
        $this->capturingSessionId = null;
        $this->capturedText = '';

        return $text;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readMessages(): array
    {
        $messages = [];

        while (($line = fgets($this->pipes[1])) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        while (($line = fgets($this->pipes[2])) !== false) {
            $line = trim($line);

            if ($line !== '') {
                $this->line("<fg=gray>{$line}</>");
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleAgentMessage(array $message): void
    {
        if (($message['method'] ?? null) === 'session/update') {
            $this->captureSessionUpdate($message);

            return;
        }

        if (($message['method'] ?? null) === 'session/request_permission' && isset($message['id'])) {
            $this->writeMessage([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => [
                    'outcome' => [
                        'outcome' => 'selected',
                        'optionId' => $this->rejectPermissionOption($message),
                    ],
                ],
            ]);

            return;
        }

        if (isset($message['id'], $message['method'])) {
            $this->writeMessage([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'error' => [
                    'code' => -32601,
                    'message' => 'Client capability is not available.',
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function captureSessionUpdate(array $message): void
    {
        if ($this->capturingSessionId === null) {
            return;
        }

        if (data_get($message, 'params.sessionId') !== $this->capturingSessionId) {
            return;
        }

        if (data_get($message, 'params.update.sessionUpdate') !== 'agent_message_chunk') {
            return;
        }

        $text = data_get($message, 'params.update.content.text');

        if (is_string($text)) {
            $this->capturedText .= $text;
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function rejectPermissionOption(array $message): string
    {
        $options = data_get($message, 'params.options', []);

        if (is_array($options)) {
            foreach ($options as $option) {
                if (is_array($option) && str_starts_with((string) ($option['kind'] ?? ''), 'reject')) {
                    return (string) $option['optionId'];
                }
            }

            if (isset($options[0]['optionId'])) {
                return (string) $options[0]['optionId'];
            }
        }

        return 'reject-once';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function writeMessage(array $message): void
    {
        fwrite($this->pipes[0], json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function resolveChatLanguage(ChatSession $chatSession): string
    {
        return (string) data_get($chatSession->metadata, 'language') === 'en' ? 'en' : 'vi';
    }

    private function buildWarmPrompt(ChatSession $chatSession): string
    {
        $language = $this->resolveChatLanguage($chatSession);

        if ($language === 'en') {
            return implode("\n", [
                'You are a menu assistant for An Uong AI.',
                'Always reply in English, concise and friendly.',
                'Only suggest dishes from the menu data below.',
                'When customers clearly choose dishes, return cart_actions with menu_code and quantity_delta. If quantity is missing, default to 1.',
                'If the request is unclear, the dish is unavailable, or outside the menu, do not add to the cart.',
                'All future replies must be pure JSON, no Markdown, in the exact format: {"reply":"...","cart_actions":[{"menu_code":"pho_bo_01","quantity_delta":1}]}',
                '',
                'MENU_JSON:',
                $this->menuJson(),
            ]);
        }

        return implode("\n", [
            'Bạn là trợ lý gọi món cho An Uong AI.',
            'Luôn trả lời bằng tiếng Việt, ngắn gọn, thân thiện.',
            'Chỉ tư vấn các món trong dữ liệu menu dưới đây.',
            'Khi khách chọn/gọi món rõ ràng, trả về cart_actions với menu_code và quantity_delta. Nếu khách không nói số lượng, mặc định là 1.',
            'Nếu yêu cầu mơ hồ, món không có, hoặc không liên quan menu, không thêm giỏ hàng.',
            'Mọi phản hồi sau này phải là JSON thuần, không Markdown, đúng dạng: {"reply":"...","cart_actions":[{"menu_code":"pho_bo_01","quantity_delta":1}]}',
            '',
            'MENU_JSON:',
            $this->menuJson(),
        ]);
    }

    private function buildTurnPrompt(ChatTurn $turn, string $language): string
    {
        if ($language === 'en') {
            return implode("\n", [
                'Customer message:',
                $turn->user_message,
                '',
                'Current cart JSON:',
                json_encode($this->cartContextForPrompt($turn), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                '',
                'Return JSON only: {"reply":"...","cart_actions":[{"menu_code":"...","quantity_delta":1}]}',
            ]);
        }

        return implode("\n", [
            'Tin nhắn khách:',
            $turn->user_message,
            '',
            'Giỏ hàng hiện tại JSON:',
            json_encode($this->cartContextForPrompt($turn), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'Trả về duy nhất JSON: {"reply":"...","cart_actions":[{"menu_code":"...","quantity_delta":1}]}',
        ]);
    }

    private function menuJson(): string
    {
        return Food::query()
            ->availableForMenu()
            ->get()
            ->map(fn (Food $food): array => [
                'menu_code' => $food->menu_code,
                'name' => $food->name,
                'vietnamese_name' => $food->vietnamese_name,
                'category' => $food->category->value,
                'description' => $food->description,
                'ingredients' => $food->ingredients,
                'price_vnd' => $food->price_vnd,
                'subcategory' => $food->subcategory,
                'protein' => $food->protein,
                'spiciness' => $food->spiciness,
                'vegetarian' => $food->vegetarian,
                'contains_pork' => $food->contains_pork,
                'contains_beef' => $food->contains_beef,
                'contains_seafood' => $food->contains_seafood,
                'contains_nuts' => $food->contains_nuts,
                'contains_dairy' => $food->contains_dairy,
                'beginner_friendly' => $food->beginner_friendly,
                'tourist_favorite' => $food->tourist_favorite,
                'healthy' => $food->healthy,
                'comfort_food' => $food->comfort_food,
                'keywords' => $food->keywords,
                'recommendation_reason' => $food->recommendation_reason,
            ])
            ->values()
            ->toJson(JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cartContextForPrompt(ChatTurn $turn): array
    {
        $cart = collect($turn->cart_context ?? []);
        $foods = Food::query()
            ->whereIn('id', $cart->pluck('food_id')->all())
            ->get()
            ->keyBy('id');

        return $cart
            ->map(function (array $item) use ($foods): ?array {
                $food = $foods->get((int) $item['food_id']);

                if (! $food instanceof Food) {
                    return null;
                }

                return [
                    'menu_code' => $food->menu_code,
                    'name' => $food->name,
                    'vietnamese_name' => $food->vietnamese_name,
                    'quantity' => (int) $item['quantity'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonEnvelope(string $responseText, string $language): array
    {
        $json = trim($responseText);
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $json) ?? $json;

        $decoded = json_decode($json, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $json, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'reply' => $responseText !== '' ? $responseText : $this->fallbackReply($language),
            'cart_actions' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    private function normalizedReply(array $rawResponse, string $responseText, string $language): string
    {
        $reply = $rawResponse['reply'] ?? null;

        if (is_string($reply) && trim($reply) !== '') {
            return trim($reply);
        }

        if ($responseText !== '') {
            return trim($responseText);
        }

        return $this->fallbackReply($language);
    }

    private function fallbackReply(string $language): string
    {
        return $language === 'en'
            ? 'I could not generate a good response. Please try again.'
            : 'Mình chưa có phản hồi phù hợp.';
    }

    /**
     * @return array<int, array{food_id: int, menu_code: string, quantity_delta: int}>
     */
    private function validatedCartActions(mixed $rawActions): array
    {
        if (! is_array($rawActions)) {
            return [];
        }

        $actions = collect($rawActions)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->values();

        $foodsByMenuCode = Food::query()
            ->availableForMenu()
            ->whereIn('menu_code', $actions->pluck('menu_code')->filter()->all())
            ->get()
            ->keyBy('menu_code');

        return $actions
            ->map(function (array $action) use ($foodsByMenuCode): ?array {
                $menuCode = $action['menu_code'] ?? null;
                $delta = $action['quantity_delta'] ?? null;

                if (! is_string($menuCode) || ! is_numeric($delta)) {
                    return null;
                }

                $food = $foodsByMenuCode->get($menuCode);

                if (! $food instanceof Food) {
                    return null;
                }

                $quantityDelta = max(min((int) $delta, 20), -20);

                if ($quantityDelta === 0) {
                    return null;
                }

                return [
                    'food_id' => $food->id,
                    'menu_code' => $food->menu_code,
                    'quantity_delta' => $quantityDelta,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function closeIdleSessions(): void
    {
        if (! $this->isProcessRunning()) {
            return;
        }

        $deadline = now()->subSeconds(max((int) $this->option('idle-timeout'), 60));

        ChatSession::query()
            ->whereNotNull('acp_session_id')
            ->where('last_used_at', '<', $deadline)
            ->get()
            ->each(function (ChatSession $chatSession): void {
                try {
                    $this->rpc('session/close', [
                        'sessionId' => $chatSession->acp_session_id,
                    ], 5);
                } catch (Throwable) {
                    //
                }

                unset($this->loadedAcpSessions[(string) $chatSession->acp_session_id]);

                $chatSession->forceFill([
                    'acp_session_id' => null,
                    'status' => 'idle',
                ])->save();
            });
    }

    private function workspacePath(ChatSession $chatSession): string
    {
        $path = storage_path("app/private/chat-acp-workspaces/{$chatSession->id}");

        File::ensureDirectoryExists($path);

        return $path;
    }

    private function agentHomePath(): string
    {
        return storage_path('app/private/chat-acp-home');
    }

    private function hasApiKey(): bool
    {
        return filled(config('services.codex_acp.codex_api_key'))
            || filled(config('services.codex_acp.openai_api_key'));
    }
}
