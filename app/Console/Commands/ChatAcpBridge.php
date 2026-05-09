<?php

namespace App\Console\Commands;

use App\MenuFilterDefinitions;
use App\Models\ChatSession;
use App\Models\ChatTurn;
use App\Models\Food;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('chat:acp-bridge {--idle-timeout=900} {--poll=1} {--turn-timeout=90} {--standby-without-key}')]
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

            if ($this->option('standby-without-key')) {
                $this->warn('Chat ACP bridge is standing by without an API key. Restart after adding a key.');

                while (true) {
                    sleep(max((int) $this->option('poll'), 1));
                }
            }

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
                    'reply' => $this->fallbackConnectionReply($turn),
                    'cart_actions' => [],
                    'filter_action' => null,
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
        $acpSessionId = $this->ensureAcpSession($chatSession);
        $replyLanguage = $this->detectMessageLanguage($turn->user_message);

        $responseText = $this->prompt(
            $acpSessionId,
            $this->buildTurnPrompt($turn),
            (int) $this->option('turn-timeout'),
        );
        $rawResponse = $this->decodeJsonEnvelope($responseText, $replyLanguage);
        $cartActions = $this->validatedCartActions($rawResponse['cart_actions'] ?? []);
        $filterAction = $this->validatedFilterAction($rawResponse['filter_action'] ?? null);

        $turn->forceFill([
            'status' => 'completed',
            'reply' => $this->normalizedReply($rawResponse, $responseText, $replyLanguage),
            'cart_actions' => $cartActions,
            'filter_action' => $filterAction,
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
            $this->buildWarmPrompt(),
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
            $this->agentCommand($binary),
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

    /**
     * @return array<int, string>
     */
    private function agentCommand(string $binary): array
    {
        $command = [$binary];
        $model = trim((string) config('services.codex_acp.model'));
        $reasoningEffort = trim((string) config('services.codex_acp.reasoning_effort'));

        if ($model !== '') {
            $this->appendConfigOverride($command, 'model', $model);
        }

        if ($reasoningEffort !== '') {
            $this->appendConfigOverride($command, 'model_reasoning_effort', $reasoningEffort);
        }

        return $command;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function appendConfigOverride(array &$command, string $key, string $value): void
    {
        $command[] = '-c';
        $command[] = $key.'='.json_encode($value, JSON_THROW_ON_ERROR);
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

    private function buildWarmPrompt(): string
    {
        return implode("\n", [
            'You are a bilingual menu assistant for An Uong AI.',
            'Infer the reply language from the customer latest message on every turn. English message -> English reply. Vietnamese message -> Vietnamese reply. Mixed message -> use the dominant language in that latest message.',
            'Do not rely on a stored chat language. The same chat session can switch between English and Vietnamese without being restarted.',
            'Only suggest dishes and filters from the menu and allowed filter data below.',
            'When replying in English and mentioning dishes, use the menu item "name" value.',
            'When replying in Vietnamese and mentioning dishes, prefer "vietnamese_name" when present.',
            'When customers clearly choose dishes, return cart_actions with menu_code and quantity_delta. If quantity is missing, default to 1. If the request is unclear, unavailable, or outside the menu, do not add to the cart.',
            'When customers ask to show/filter menu items, return filter_action using only allowed categories and property keys. If no filter change is requested, filter_action must be null.',
            'Examples:',
            'Customer: add one beef pho -> reply in English, add the matching pho item.',
            'Customer: thêm một phở bò -> reply in Vietnamese, add the matching pho item.',
            'Customer: show healthy food -> filter_action category food, property_keys ["healthy"].',
            'Customer: hiện món thanh nhẹ -> filter_action category food, property_keys ["healthy"].',
            'All future replies must be pure JSON, no Markdown, in the exact format: {"reply":"...","cart_actions":[{"menu_code":"pho_bo_01","quantity_delta":1}],"filter_action":null}',
            'filter_action shape when present: {"category":"food","property_keys":["healthy"]}',
            '',
            'ALLOWED_FILTERS_JSON:',
            $this->allowedFiltersJson(),
            '',
            'MENU_JSON:',
            $this->menuJson(),
        ]);
    }

    private function buildTurnPrompt(ChatTurn $turn): string
    {
        return implode("\n", [
            'Customer latest message:',
            $turn->user_message,
            '',
            'Current cart JSON:',
            json_encode($this->cartContextForPrompt($turn), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'Current filter JSON:',
            json_encode($this->filterContextForPrompt($turn), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'Infer reply language from the customer latest message only.',
            'English reply: use "name" for dish names. Vietnamese reply: prefer "vietnamese_name" for dish names.',
            'For filter requests, return filter_action with allowed category/property_keys. For no filter change, return null.',
            '',
            'Return JSON only: {"reply":"...","cart_actions":[{"menu_code":"...","quantity_delta":1}],"filter_action":null}',
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
                'vietnamese_description' => $food->vietnamese_description,
                'ingredients' => $food->ingredients,
                'price_vnd' => $food->price_vnd,
                'subcategory' => $food->subcategory,
                'protein' => $food->protein,
                'spiciness' => $food->spiciness,
                'halal_friendly' => $food->halal_friendly,
                'contains_pork' => $food->contains_pork,
                'contains_beef' => $food->contains_beef,
                'contains_seafood' => $food->contains_seafood,
                'contains_nuts' => $food->contains_nuts,
                'tourist_favorite' => $food->tourist_favorite,
                'adventurous' => $food->adventurous,
                'healthy' => $food->healthy,
                'quick_meal' => $food->quick_meal,
                'heavy_meal' => $food->heavy_meal,
                'shareable' => $food->shareable,
                'keywords' => $food->keywords,
                'recommendation_reason' => $food->recommendation_reason,
            ])
            ->values()
            ->toJson(JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function allowedFiltersJson(): string
    {
        return json_encode([
            'categories' => MenuFilterDefinitions::categoryKeys(),
            'property_filters' => MenuFilterDefinitions::propertyFilters(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
     * @return array{category: string|null, property_keys: array<int, string>}
     */
    private function filterContextForPrompt(ChatTurn $turn): array
    {
        $filterContext = is_array($turn->filter_context) ? $turn->filter_context : [];
        $category = $filterContext['category'] ?? null;
        $propertyKeys = $filterContext['property_keys'] ?? [];
        $allowedPropertyKeys = MenuFilterDefinitions::propertyKeys();

        return [
            'category' => is_string($category) && in_array($category, MenuFilterDefinitions::categoryKeys(), true) ? $category : null,
            'property_keys' => is_array($propertyKeys)
                ? array_values(array_filter(
                    $propertyKeys,
                    fn (mixed $key): bool => is_string($key) && in_array($key, $allowedPropertyKeys, true),
                ))
                : [],
        ];
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
            'filter_action' => null,
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

    private function fallbackConnectionReply(ChatTurn $turn): string
    {
        return $this->detectMessageLanguage($turn->user_message) === 'en'
            ? 'I could not connect to the ordering assistant. Please try again later.'
            : 'Mình chưa kết nối được trợ lý gọi món. Vui lòng thử lại sau.';
    }

    private function detectMessageLanguage(?string $message): string
    {
        $message = mb_strtolower((string) $message);

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

    /**
     * @return array{category: string, property_keys: array<int, string>}|null
     */
    private function validatedFilterAction(mixed $rawAction): ?array
    {
        if (! is_array($rawAction)) {
            return null;
        }

        $category = $rawAction['category'] ?? null;
        $propertyKeys = $rawAction['property_keys'] ?? [];

        if (! is_string($category) || ! in_array($category, MenuFilterDefinitions::categoryKeys(), true)) {
            return null;
        }

        if (! is_array($propertyKeys)) {
            return null;
        }

        $allowedPropertyKeys = MenuFilterDefinitions::propertyKeys();
        $validatedPropertyKeys = collect($propertyKeys)
            ->filter(fn (mixed $propertyKey): bool => is_string($propertyKey) && in_array($propertyKey, $allowedPropertyKeys, true))
            ->unique()
            ->values()
            ->all();

        if (count($validatedPropertyKeys) !== count($propertyKeys)) {
            return null;
        }

        return [
            'category' => $category,
            'property_keys' => $validatedPropertyKeys,
        ];
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
