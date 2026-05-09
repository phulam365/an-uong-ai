<?php

namespace App\Console\Commands;

use App\Enums\FoodCategory;
use App\MenuFilterDefinitions;
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
    private const PROMPT_VERSION = 4;

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
                    'display_action' => null,
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
            $this->buildTurnPrompt($turn, $replyLanguage),
            (int) $this->option('turn-timeout'),
        );
        $rawResponse = $this->decodeJsonEnvelope($responseText, $replyLanguage);
        $cartActions = $this->validatedCartActions($rawResponse['cart_actions'] ?? []);
        $filterAction = $this->validatedFilterAction($rawResponse['filter_action'] ?? null);
        $displayAction = $this->validatedDisplayAction($rawResponse['display_action'] ?? null);

        $turn->forceFill([
            'status' => 'completed',
            'reply' => $this->normalizedReply($rawResponse, $responseText, $replyLanguage),
            'cart_actions' => $cartActions,
            'filter_action' => $filterAction,
            'display_action' => $displayAction,
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

        if ($this->sessionNeedsPromptRefresh($chatSession)) {
            $this->forgetAcpSession($chatSession);
        }

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

        $metadata = is_array($chatSession->metadata) ? $chatSession->metadata : [];

        $chatSession->forceFill([
            'status' => 'ready',
            'warmed_at' => now(),
            'metadata' => [
                ...$metadata,
                'prompt_version' => self::PROMPT_VERSION,
            ],
            'error' => null,
        ])->save();

        return $acpSessionId;
    }

    private function sessionNeedsPromptRefresh(ChatSession $chatSession): bool
    {
        $metadata = is_array($chatSession->metadata) ? $chatSession->metadata : [];

        return ($metadata['prompt_version'] ?? null) !== self::PROMPT_VERSION;
    }

    private function forgetAcpSession(ChatSession $chatSession): void
    {
        if ($chatSession->acp_session_id && isset($this->loadedAcpSessions[$chatSession->acp_session_id])) {
            try {
                $this->rpc('session/close', [
                    'sessionId' => $chatSession->acp_session_id,
                ], 5);
            } catch (Throwable) {
                //
            }

            unset($this->loadedAcpSessions[$chatSession->acp_session_id]);
        }

        $chatSession->forceFill([
            'acp_session_id' => null,
            'status' => 'pending',
            'warmed_at' => null,
        ])->save();
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

        File::ensureDirectoryExists(storage_path('app/private/chat-acp-workspaces'));

        $baseEnvironment = getenv();
        $environment = is_array($baseEnvironment) ? $baseEnvironment : [];

        if (filled(config('services.codex_acp.codex_api_key'))) {
            $environment['CODEX_API_KEY'] = (string) config('services.codex_acp.codex_api_key');
        }

        if (filled(config('services.codex_acp.openai_api_key'))) {
            $environment['OPENAI_API_KEY'] = (string) config('services.codex_acp.openai_api_key');
        }

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
            'Only suggest dishes and filters from the menu and allowed filter data included in each turn.',
            'The menu data intentionally does not include slugs. Never mention, invent, or expose food slugs.',
            'Each turn includes menu item names, ingredient names, taste labels, preparation text, and filter labels only in the inferred reply language.',
            'Use the provided localized menu item "name" value when mentioning dishes.',
            'When mentioning a dish name in the reply string, wrap the visible dish name in double asterisks, for example **Beef Pho**.',
            'When listing ingredients in the reply string, put each ingredient on its own line.',
            'When customers clearly choose dishes, return cart_actions with menu_code and quantity_delta. If quantity is missing, default to 1. If the request is unclear, unavailable, or outside the menu, do not add to the cart.',
            'When customers explicitly ask to switch category, such as show drinks, return filter_action using only allowed categories and property keys.',
            'When customers ask for recommendations, search, preferences, dietary needs, flavor, ingredients, caffeine, or time-of-day suggestions such as breakfast, return display_action with menu_codes in best-match order.',
            'Do not put recommended/search result items in filter_action. Use display_action for those result grids.',
            'If no cart, category, or display change is requested, filter_action and display_action must be null.',
            'Examples:',
            'Customer: add one beef pho -> reply in English, add the matching pho item.',
            'Customer: thêm một phở bò -> reply in Vietnamese, add the matching pho item.',
            'Customer: show drinks -> filter_action category drink, property_keys [].',
            'Customer: món gì ăn sáng được? -> display_action type show_items with title and breakfast menu_codes.',
            'Customer: show healthy food -> display_action type show_items with title and matching healthy menu_codes.',
            'Customer: hiện món thanh nhẹ -> display_action type show_items with title and matching healthy menu_codes.',
            'All future replies must be exactly one pure JSON object, with no Markdown outside the JSON and no second JSON object. The reply string may use **bold dish names**. Use the exact format: {"reply":"...","cart_actions":[{"menu_code":"pho_bo_01","quantity_delta":1}],"filter_action":null,"display_action":null}',
            'filter_action shape when present: {"category":"food","property_keys":["healthy"]}',
            'display_action shape when present: {"type":"show_items","title":"Breakfast picks","menu_codes":["pho_bo_01","hu_tieu_01"]}',
        ]);
    }

    private function buildTurnPrompt(ChatTurn $turn, string $replyLanguage): string
    {
        return implode("\n", [
            'Detected reply language:',
            $replyLanguage === 'en' ? 'English' : 'Vietnamese',
            '',
            'Customer latest message:',
            $turn->user_message,
            '',
            'Current cart JSON:',
            json_encode($this->cartContextForPrompt($turn, $replyLanguage), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'Current filter JSON:',
            json_encode($this->filterContextForPrompt($turn, $replyLanguage), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            '',
            'ALLOWED_FILTERS_JSON:',
            $this->allowedFiltersJson($replyLanguage),
            '',
            'MENU_JSON:',
            $this->menuJson($replyLanguage),
            '',
            'Infer reply language from the customer latest message only.',
            'Never mention, invent, or expose food slugs.',
            'Use only the localized "name" values provided in MENU_JSON for visible dish names.',
            'Use localized ingredient names, taste labels, preparation text, cart item names, and filter labels from the JSON above.',
            'When mentioning dish names in the reply string, wrap each visible dish name in double asterisks, for example **Beef Pho**.',
            'When listing ingredients in the reply string, put each ingredient on its own line.',
            'Explicit ordering belongs in cart_actions.',
            'Explicit category switches belong in filter_action with allowed category/property_keys.',
            'Recommendations, searches, preferences, dietary needs, flavor, ingredients, caffeine, or time-of-day suggestions belong in display_action with menu_codes in best-match order and a localized title.',
            'Do not use display_action for add-to-cart requests. Do not use filter_action for recommendation/search result grids.',
            '',
            'Return exactly one JSON object only: {"reply":"...","cart_actions":[{"menu_code":"...","quantity_delta":1}],"filter_action":null,"display_action":null}',
        ]);
    }

    private function menuJson(string $language): string
    {
        return Food::query()
            ->availableForMenu()
            ->get()
            ->map(fn (Food $food): array => [
                'menu_code' => $food->menu_code,
                'name' => $this->localizedFoodName($food, $language),
                'category' => $food->category->value,
                'category_label' => $this->localizedText($food->category->labels(), $language),
                'description' => $this->localizedFoodDescription($food, $language),
                'ingredients' => $this->localizedIngredients($food, $language),
                'taste' => $food->taste->value,
                'taste_label' => $this->localizedText($food->taste->labels(), $language),
                'how_made' => $this->localizedHowMade($food, $language),
                'price_vnd' => $food->price_vnd,
                'subcategory' => $food->subcategory,
                'protein' => $food->protein,
                'best_time' => $food->best_time,
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

    private function allowedFiltersJson(string $language): string
    {
        return json_encode([
            'categories' => collect(FoodCategory::cases())
                ->map(fn (FoodCategory $category): array => [
                    'key' => $category->value,
                    'label' => $this->localizedText($category->labels(), $language),
                ])
                ->values()
                ->all(),
            'property_filters' => collect(MenuFilterDefinitions::propertyFilters())
                ->map(fn (array $filter): array => [
                    'key' => $filter['key'],
                    'label' => $this->localizedText($filter['labels'], $language),
                ])
                ->values()
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cartContextForPrompt(ChatTurn $turn, string $language): array
    {
        $cart = collect($turn->cart_context ?? []);
        $foods = Food::query()
            ->whereIn('id', $cart->pluck('food_id')->all())
            ->get()
            ->keyBy('id');

        return $cart
            ->map(function (array $item) use ($foods, $language): ?array {
                $food = $foods->get((int) $item['food_id']);

                if (! $food instanceof Food) {
                    return null;
                }

                return [
                    'menu_code' => $food->menu_code,
                    'name' => $this->localizedFoodName($food, $language),
                    'quantity' => (int) $item['quantity'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{category: string|null, category_label: string|null, property_keys: array<int, string>, property_labels: array<int, string>}
     */
    private function filterContextForPrompt(ChatTurn $turn, string $language): array
    {
        $filterContext = is_array($turn->filter_context) ? $turn->filter_context : [];
        $category = $filterContext['category'] ?? null;
        $propertyKeys = $filterContext['property_keys'] ?? [];
        $allowedPropertyKeys = MenuFilterDefinitions::propertyKeys();
        $validatedCategory = is_string($category) && in_array($category, MenuFilterDefinitions::categoryKeys(), true) ? $category : null;
        $validatedPropertyKeys = is_array($propertyKeys)
            ? array_values(array_filter(
                $propertyKeys,
                fn (mixed $key): bool => is_string($key) && in_array($key, $allowedPropertyKeys, true),
            ))
            : [];

        return [
            'category' => $validatedCategory,
            'category_label' => $this->categoryLabel($validatedCategory, $language),
            'property_keys' => $validatedPropertyKeys,
            'property_labels' => $this->propertyLabels($validatedPropertyKeys, $language),
        ];
    }

    private function localizedFoodName(Food $food, string $language): string
    {
        if ($language === 'en') {
            return $food->name;
        }

        return $food->vietnamese_name ?: $food->name;
    }

    private function localizedFoodDescription(Food $food, string $language): ?string
    {
        if ($language === 'en') {
            return $food->description;
        }

        return $food->vietnamese_description ?: $food->description;
    }

    private function localizedHowMade(Food $food, string $language): ?string
    {
        if ($language === 'en') {
            return $food->how_made;
        }

        return $food->vietnamese_how_made ?: $food->how_made;
    }

    /**
     * @return array<int, array{name: string, quantity_grams: int}>
     */
    private function localizedIngredients(Food $food, string $language): array
    {
        $ingredients = is_array($food->ingredients) ? $food->ingredients : [];

        return collect($ingredients)
            ->filter(fn (mixed $ingredient): bool => is_array($ingredient))
            ->map(fn (array $ingredient): array => [
                'name' => $language === 'en'
                    ? (string) ($ingredient['name'] ?? '')
                    : (string) ($ingredient['vietnamese_name'] ?? $ingredient['name'] ?? ''),
                'quantity_grams' => (int) ($ingredient['quantity_grams'] ?? 0),
            ])
            ->filter(fn (array $ingredient): bool => $ingredient['name'] !== '' && $ingredient['quantity_grams'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array{en: string, vi: string}  $labels
     */
    private function localizedText(array $labels, string $language): string
    {
        return $labels[$language] ?? $labels['en'];
    }

    private function categoryLabel(?string $categoryKey, string $language): ?string
    {
        if ($categoryKey === null) {
            return null;
        }

        $category = FoodCategory::tryFrom($categoryKey);

        return $category instanceof FoodCategory
            ? $this->localizedText($category->labels(), $language)
            : null;
    }

    /**
     * @param  array<int, string>  $propertyKeys
     * @return array<int, string>
     */
    private function propertyLabels(array $propertyKeys, string $language): array
    {
        $filtersByKey = collect(MenuFilterDefinitions::propertyFilters())->keyBy('key');

        return collect($propertyKeys)
            ->map(function (string $propertyKey) use ($filtersByKey, $language): ?string {
                $filter = $filtersByKey->get($propertyKey);

                if (! is_array($filter)) {
                    return null;
                }

                return $this->localizedText($filter['labels'], $language);
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

        foreach ($this->jsonObjectCandidates($json) as $candidate) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'reply' => $responseText !== '' ? $responseText : $this->fallbackReply($language),
            'cart_actions' => [],
            'filter_action' => null,
            'display_action' => null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function jsonObjectCandidates(string $text): array
    {
        $candidates = [];
        $start = null;
        $depth = 0;
        $inString = false;
        $isEscaped = false;
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];

            if ($start === null) {
                if ($character === '{') {
                    $start = $index;
                    $depth = 1;
                    $inString = false;
                    $isEscaped = false;
                }

                continue;
            }

            if ($inString) {
                if ($isEscaped) {
                    $isEscaped = false;

                    continue;
                }

                if ($character === '\\') {
                    $isEscaped = true;

                    continue;
                }

                if ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;

                continue;
            }

            if ($character === '{') {
                $depth++;

                continue;
            }

            if ($character !== '}') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                $candidates[] = substr($text, $start, $index - $start + 1);
                $start = null;
            }
        }

        return $candidates;
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

    /**
     * @return array{type: 'show_items', title: string, food_ids: array<int, int>}|null
     */
    private function validatedDisplayAction(mixed $rawAction): ?array
    {
        if (! is_array($rawAction)) {
            return null;
        }

        if (($rawAction['type'] ?? null) !== 'show_items') {
            return null;
        }

        $menuCodes = $rawAction['menu_codes'] ?? [];

        if (! is_array($menuCodes)) {
            return null;
        }

        $validatedMenuCodes = collect($menuCodes)
            ->filter(fn (mixed $menuCode): bool => is_string($menuCode) && trim($menuCode) !== '')
            ->map(fn (string $menuCode): string => trim($menuCode))
            ->unique()
            ->take(12)
            ->values();

        if ($validatedMenuCodes->isEmpty()) {
            return null;
        }

        $foodsByMenuCode = Food::query()
            ->availableForMenu()
            ->whereIn('menu_code', $validatedMenuCodes->all())
            ->get()
            ->keyBy('menu_code');

        $foodIds = $validatedMenuCodes
            ->map(function (string $menuCode) use ($foodsByMenuCode): ?int {
                $food = $foodsByMenuCode->get($menuCode);

                return $food instanceof Food ? $food->id : null;
            })
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        if ($foodIds === []) {
            return null;
        }

        $title = $rawAction['title'] ?? null;
        $title = is_string($title) && trim($title) !== ''
            ? mb_substr(trim($title), 0, 120)
            : 'Recommended items';

        return [
            'type' => 'show_items',
            'title' => $title,
            'food_ids' => $foodIds,
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
}
