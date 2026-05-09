import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    show as showChatMessage,
    store as storeChatMessage,
} from '@/actions/App/Http/Controllers/ChatMessageController';
import ChatSessionController from '@/actions/App/Http/Controllers/ChatSessionController';
import { success as orderSuccess } from '@/routes/order';

interface Category {
    key: string;
    labels: LocalizedText;
    count: number;
}

interface PropertyFilter {
    key: string;
    labels: LocalizedText;
    count: number;
}

interface Food {
    id: number;
    name: string;
    vietnamese_name: string | null;
    slug: string;
    category: string;
    category_labels: LocalizedText;
    description: string | null;
    vietnamese_description: string | null;
    ingredients: Ingredient[];
    taste: 'normal' | 'sweet' | 'spicy';
    taste_labels: LocalizedText;
    how_made: string | null;
    vietnamese_how_made: string | null;
    formatted_price: string;
    price_vnd: number;
    image_url: string;
    property_keys: string[];
    property_labels: LocalizedText[];
}

interface Ingredient {
    name: string;
    vietnamese_name: string;
    quantity_grams: number;
}

interface MenuProps {
    language?: MenuLanguage;
    categories: Category[];
    propertyFilters: PropertyFilter[];
    foods: Food[];
}

interface CartItem {
    food: Food;
    quantity: number;
}

interface ChatCartAction {
    food_id: number;
    menu_code: string;
    quantity_delta: number;
}

interface ChatFilterAction {
    category: string;
    property_keys: string[];
}

interface ChatDisplayAction {
    type: 'show_items';
    title: string;
    food_ids: number[];
}

interface ChatTurnResponse {
    turn_id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    reply: string | null;
    cart_actions: ChatCartAction[];
    filter_action: ChatFilterAction | null;
    display_action: ChatDisplayAction | null;
    error: string | null;
}

interface ChatMessage {
    id: string;
    role: 'assistant' | 'user';
    text: string;
}

type MenuLanguage = 'vi' | 'en';
type LocalizedText = Record<MenuLanguage, string>;

const ALL_CATEGORY_KEY = 'all';

const uiText: Record<
    MenuLanguage,
    {
        pageTitle: string;
        heroTitle: string;
        openCart: string;
        openCartWithItems: (count: number) => string;
        categories: string;
        filterBy: string;
        clear: string;
        clearFilters: string;
        itemCount: (count: number) => string;
        emptyTitle: string;
        emptyBody: string;
        menuLanguage: string;
        decreaseQuantity: string;
        increaseQuantity: string;
        closeDetails: string;
        currentOrder: string;
        cart: string;
        closeCart: string;
        unit: string;
        quantity: string;
        lineTotal: string;
        emptyCartTitle: string;
        emptyCartBody: string;
        total: string;
        orderItems: (count: number) => string;
        chatAssistantLabel: string;
        chatKicker: string;
        chatTitle: string;
        closeChat: string;
        openChat: string;
        chatButtonCta: string;
        sendMessage: string;
        chatPlaceholder: string;
        chatTyping: string;
        chatWelcome: string;
        chatSessionError: string;
        chatSendError: string;
        chatNoResponse: string;
        chatTimeout: string;
        chatTimeoutError: string;
        ingredients: string;
        taste: string;
        howMade: string;
        grams: string;
    }
> = {
    vi: {
        pageTitle: 'Thực đơn',
        heroTitle: 'Món ăn và đồ uống sẵn sàng để chọn',
        openCart: 'Mở giỏ hàng',
        openCartWithItems: (count) => `Mở giỏ hàng, ${count} món`,
        categories: 'Danh mục',
        filterBy: 'Lọc theo',
        clear: 'Xóa',
        clearFilters: 'Xóa bộ lọc',
        itemCount: (count) => `${count} món`,
        emptyTitle: 'Không có món phù hợp',
        emptyBody: 'Thử danh mục khác hoặc bỏ bớt thuộc tính đã chọn.',
        menuLanguage: 'Ngôn ngữ thực đơn',
        decreaseQuantity: 'Giảm số lượng',
        increaseQuantity: 'Tăng số lượng',
        closeDetails: 'Đóng chi tiết',
        currentOrder: 'Đơn hiện tại',
        cart: 'Giỏ hàng',
        closeCart: 'Đóng giỏ hàng',
        unit: 'Đơn giá',
        quantity: 'Số lượng',
        lineTotal: 'Tạm tính',
        emptyCartTitle: 'Giỏ hàng đang trống',
        emptyCartBody: 'Thêm món từ thực đơn để xem tại đây.',
        total: 'Tổng cộng',
        orderItems: (count) => `Đặt ${count} món`,
        chatAssistantLabel: 'Trợ lý gọi món',
        chatKicker: 'Trò chuyện',
        chatTitle: 'Trợ lý gọi món',
        closeChat: 'Đóng trò chuyện',
        openChat: 'Mở trò chuyện',
        chatButtonCta: 'Hỏi về món ăn',
        sendMessage: 'Gửi tin nhắn',
        chatPlaceholder: 'Nhập món hoặc khẩu vị...',
        chatTyping: 'Đang trả lời...',
        chatWelcome:
            'Bạn muốn ăn gì hôm nay? Mình có thể gợi ý và thêm món vào giỏ.',
        chatSessionError: 'Mình chưa mở được phiên chat. Vui lòng thử lại.',
        chatSendError: 'Mình chưa gửi được tin nhắn. Vui lòng thử lại.',
        chatNoResponse: 'Mình chưa có phản hồi phù hợp.',
        chatTimeout: 'Mình vẫn đang chờ kết nối trợ lý. Vui lòng thử lại sau.',
        chatTimeoutError: 'Quá thời gian chờ phản hồi từ trợ lý.',
        ingredients: 'Thành phần',
        taste: 'Khẩu vị',
        howMade: 'Cách làm',
        grams: 'g',
    },
    en: {
        pageTitle: 'Menu',
        heroTitle: 'Food and drinks ready to browse',
        openCart: 'Open cart',
        openCartWithItems: (count) =>
            `Open cart, ${count} ${count === 1 ? 'item' : 'items'}`,
        categories: 'Categories',
        filterBy: 'Filter by',
        clear: 'Clear',
        clearFilters: 'Clear filters',
        itemCount: (count) => `${count} ${count === 1 ? 'item' : 'items'}`,
        emptyTitle: 'No matching menu items',
        emptyBody:
            'Try a different category or remove one of the selected properties.',
        menuLanguage: 'Menu language',
        decreaseQuantity: 'Decrease quantity',
        increaseQuantity: 'Increase quantity',
        closeDetails: 'Close details',
        currentOrder: 'Current order',
        cart: 'Cart',
        closeCart: 'Close cart',
        unit: 'Unit',
        quantity: 'Quantity',
        lineTotal: 'Line total',
        emptyCartTitle: 'Your cart is empty',
        emptyCartBody: 'Add food from the menu to see it here.',
        total: 'Total',
        orderItems: (count) =>
            `Order ${count} ${count === 1 ? 'item' : 'items'}`,
        chatAssistantLabel: 'Chat ordering assistant',
        chatKicker: 'Chat',
        chatTitle: 'Ordering assistant',
        closeChat: 'Close chat',
        openChat: 'Open chat',
        chatButtonCta: 'Ask about dishes',
        sendMessage: 'Send message',
        chatPlaceholder: 'Enter a dish or craving...',
        chatTyping: 'Replying...',
        chatWelcome:
            'What would you like today? I can suggest dishes and add them to your cart.',
        chatSessionError:
            'I could not open the chat session. Please try again.',
        chatSendError: 'I could not send the message. Please try again.',
        chatNoResponse: 'I do not have a useful response yet.',
        chatTimeout:
            'I am still waiting for the assistant connection. Please try again later.',
        chatTimeoutError: 'Timed out waiting for the assistant response.',
        ingredients: 'Ingredients',
        taste: 'Taste',
        howMade: 'How It Is Made',
        grams: 'g',
    },
};

export default function Menu({
    language: initialLanguage = 'vi',
    categories,
    foods,
}: MenuProps) {
    const categoryOptions = useMemo<Category[]>(
        () => [
            {
                key: ALL_CATEGORY_KEY,
                labels: { en: 'All', vi: 'Tất cả' },
                count: foods.length,
            },
            ...categories,
        ],
        [categories, foods.length],
    );
    const [activeCategory, setActiveCategory] =
        useState<string>(ALL_CATEGORY_KEY);
    const [activePropertyKeys, setActivePropertyKeys] = useState<string[]>([]);
    const [aiResults, setAiResults] = useState<ChatDisplayAction | null>(null);
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [selectedFood, setSelectedFood] = useState<Food | null>(null);
    const [isCartOpen, setIsCartOpen] = useState(false);
    const [language, setLanguage] = useState<MenuLanguage>(initialLanguage);
    const t = uiText[language];

    const changeLanguage = (nextLanguage: MenuLanguage): void => {
        setLanguage(nextLanguage);

        const url = new URL(window.location.href);
        url.searchParams.set('language', nextLanguage);
        window.history.replaceState(window.history.state, '', url);
    };

    const visibleFoods = useMemo(
        () =>
            foods.filter(
                (food) =>
                    matchesActiveCategory(food, activeCategory) &&
                    activePropertyKeys.every((propertyKey) =>
                        food.property_keys.includes(propertyKey),
                    ),
            ),
        [activeCategory, activePropertyKeys, foods],
    );

    const foodsById = useMemo(
        () => new Map(foods.map((food) => [food.id, food])),
        [foods],
    );

    const displayedFoods = useMemo(() => {
        if (!aiResults) {
            return visibleFoods;
        }

        return aiResults.food_ids
            .map((foodId) => foodsById.get(foodId))
            .filter((food): food is Food => food !== undefined);
    }, [aiResults, foodsById, visibleFoods]);

    const cartItems = useMemo<CartItem[]>(
        () =>
            foods
                .map((food) => ({
                    food,
                    quantity: quantities[food.id] ?? 0,
                }))
                .filter((item) => item.quantity > 0),
        [foods, quantities],
    );

    const cartTotalVnd = useMemo(
        () =>
            cartItems.reduce(
                (total, item) => total + item.food.price_vnd * item.quantity,
                0,
            ),
        [cartItems],
    );

    const cartItemCount = cartItems.length;

    useEffect(() => {
        if (!selectedFood && !isCartOpen) {
            return;
        }

        const originalOverflow = document.body.style.overflow;
        const handleKeyDown = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                if (isCartOpen) {
                    setIsCartOpen(false);

                    return;
                }

                setSelectedFood(null);
            }
        };

        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = originalOverflow;
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [isCartOpen, selectedFood]);

    const updateQuantity = (foodId: number, change: number): void => {
        setQuantities((current) => {
            const nextQuantity = Math.max((current[foodId] ?? 0) + change, 0);

            return {
                ...current,
                [foodId]: nextQuantity,
            };
        });
    };

    const selectCategory = (category: string): void => {
        setAiResults(null);
        setActiveCategory(category);
        setActivePropertyKeys([]);
    };

    return (
        <>
            <Head title={t.pageTitle} />
            <main className="min-h-screen bg-paper text-ink">
                <div className="border-b border-border bg-surface/95 shadow-sm">
                    <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:px-6 lg:px-8">
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                <img
                                    src="/logo.webp"
                                    alt={t.heroTitle}
                                    className="h-16 w-auto max-w-[min(70vw,480px)] object-contain sm:h-20"
                                />
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                <LanguageSwitch
                                    language={language}
                                    onChange={changeLanguage}
                                />
                                <button
                                    type="button"
                                    aria-label={
                                        cartItemCount > 0
                                            ? t.openCartWithItems(cartItemCount)
                                            : t.openCart
                                    }
                                    onClick={() => setIsCartOpen(true)}
                                    className="relative grid h-10 w-10 place-items-center rounded-full border border-border bg-paper text-olive shadow-sm transition hover:-translate-y-0.5 hover:border-brass hover:text-olive-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                                >
                                    <CartIcon />
                                    {cartItemCount > 0 ? (
                                        <span className="absolute -top-1.5 -right-1.5 grid min-h-5 min-w-5 place-items-center rounded-full border-2 border-surface bg-wine px-1 text-[11px] leading-none font-semibold text-white">
                                            {cartItemCount > 99
                                                ? '99+'
                                                : cartItemCount}
                                        </span>
                                    ) : null}
                                </button>
                            </div>
                        </div>

                        <div className="flex gap-2 overflow-x-auto pb-1 lg:hidden">
                            {categoryOptions.map((category) => (
                                <CategoryButton
                                    key={category.key}
                                    category={category}
                                    language={language}
                                    isActive={activeCategory === category.key}
                                    onClick={() => selectCategory(category.key)}
                                />
                            ))}
                        </div>
                    </div>
                </div>

                <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:px-8">
                    <aside className="sticky top-6 hidden h-fit rounded-lg border border-border bg-surface p-3 shadow-sm lg:block">
                        <p className="mb-3 px-2 text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                            {t.categories}
                        </p>
                        <div className="flex flex-col gap-2">
                            {categoryOptions.map((category) => (
                                <CategoryButton
                                    key={category.key}
                                    category={category}
                                    language={language}
                                    isActive={activeCategory === category.key}
                                    onClick={() => selectCategory(category.key)}
                                />
                            ))}
                        </div>
                    </aside>

                    <div className="flex flex-col gap-4">
                        <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                                {aiResults ? (
                                    <h2 className="truncate text-lg font-semibold">
                                        {aiResults.title}
                                    </h2>
                                ) : null}
                                <p className="text-sm font-semibold text-muted">
                                    {t.itemCount(displayedFoods.length)}
                                </p>
                            </div>
                            {aiResults ? (
                                <button
                                    type="button"
                                    onClick={() => setAiResults(null)}
                                    className="shrink-0 rounded-full border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-wine shadow-sm transition hover:border-wine hover:text-wine-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                                >
                                    {t.clear}
                                </button>
                            ) : null}
                        </div>

                        {displayedFoods.length > 0 ? (
                            <section className="grid grid-cols-1 gap-4 min-[430px]:grid-cols-2 xl:grid-cols-3">
                                {displayedFoods.map((food) => (
                                    <ProductCard
                                        key={food.id}
                                        food={food}
                                        language={language}
                                        quantity={quantities[food.id] ?? 0}
                                        onOpen={() => setSelectedFood(food)}
                                        onIncrement={() =>
                                            updateQuantity(food.id, 1)
                                        }
                                        onDecrement={() =>
                                            updateQuantity(food.id, -1)
                                        }
                                    />
                                ))}
                            </section>
                        ) : (
                            <section className="rounded-lg border border-border bg-surface px-5 py-12 text-center shadow-sm">
                                <p className="text-base font-semibold">
                                    {t.emptyTitle}
                                </p>
                                <p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-muted">
                                    {t.emptyBody}
                                </p>
                            </section>
                        )}
                    </div>
                </div>
            </main>

            {isCartOpen ? (
                <CartDrawer
                    items={cartItems}
                    language={language}
                    totalVnd={cartTotalVnd}
                    cartItemCount={cartItemCount}
                    onClose={() => setIsCartOpen(false)}
                    onIncrement={(foodId) => updateQuantity(foodId, 1)}
                    onDecrement={(foodId) => updateQuantity(foodId, -1)}
                />
            ) : null}

            {selectedFood ? (
                <ProductModal
                    food={selectedFood}
                    language={language}
                    quantity={quantities[selectedFood.id] ?? 0}
                    onClose={() => setSelectedFood(null)}
                    onIncrement={() => updateQuantity(selectedFood.id, 1)}
                    onDecrement={() => updateQuantity(selectedFood.id, -1)}
                />
            ) : null}

            <MenuChat
                quantities={quantities}
                activeCategory={activeCategory}
                activePropertyKeys={activePropertyKeys}
                language={language}
                onCartAction={(foodId, quantityDelta) =>
                    updateQuantity(foodId, quantityDelta)
                }
                onFilterAction={(filterAction) => {
                    setAiResults(null);
                    setActiveCategory(filterAction.category);
                    setActivePropertyKeys(filterAction.property_keys);
                }}
                onDisplayAction={(displayAction) => {
                    setActivePropertyKeys([]);
                    setAiResults(displayAction);
                }}
            />
        </>
    );
}

function MenuChat({
    quantities,
    activeCategory,
    activePropertyKeys,
    language,
    onCartAction,
    onFilterAction,
    onDisplayAction,
}: {
    quantities: Record<number, number>;
    activeCategory: string;
    activePropertyKeys: string[];
    language: MenuLanguage;
    onCartAction: (foodId: number, quantityDelta: number) => void;
    onFilterAction: (filterAction: ChatFilterAction) => void;
    onDisplayAction: (displayAction: ChatDisplayAction) => void;
}) {
    const t = uiText[language];
    const [isOpen, setIsOpen] = useState(false);
    const hasBootstrappedSession = useRef(false);
    const [input, setInput] = useState('');
    const [isSending, setIsSending] = useState(false);
    const [messages, setMessages] = useState<ChatMessage[]>(() => [
        welcomeChatMessage(language),
    ]);

    useEffect(() => {
        if (!isOpen || hasBootstrappedSession.current) {
            return;
        }

        hasBootstrappedSession.current = true;

        void postJson(ChatSessionController.url()).catch(() => {
            setMessages((current) => [
                ...current,
                {
                    id: createMessageId(),
                    role: 'assistant',
                    text: t.chatSessionError,
                },
            ]);
        });
    }, [isOpen, t.chatSessionError]);

    const submitMessage = async (): Promise<void> => {
        const message = input.trim();

        if (!message || isSending) {
            return;
        }

        setInput('');
        setIsSending(true);
        setMessages((current) => [
            ...current,
            {
                id: createMessageId(),
                role: 'user',
                text: message,
            },
        ]);

        try {
            const response = await postJson<ChatTurnResponse>(
                storeChatMessage.url(),
                {
                    message,
                    cart: cartPayload(quantities),
                    filter_context: filterContextPayload(
                        activeCategory,
                        activePropertyKeys,
                    ),
                },
            );

            await handleTurnResponse(response);
        } catch {
            setMessages((current) => [
                ...current,
                {
                    id: createMessageId(),
                    role: 'assistant',
                    text: t.chatSendError,
                },
            ]);
        } finally {
            setIsSending(false);
        }
    };

    const handleTurnResponse = async (
        response: ChatTurnResponse,
    ): Promise<void> => {
        if (response.status === 'pending' || response.status === 'processing') {
            const completedResponse = await pollTurn(
                response.turn_id,
                language,
            );
            applyChatResponse(completedResponse);

            return;
        }

        applyChatResponse(response);
    };

    const applyChatResponse = (response: ChatTurnResponse): void => {
        setMessages((current) => [
            ...current,
            {
                id: createMessageId(),
                role: 'assistant',
                text: response.reply || response.error || t.chatNoResponse,
            },
        ]);

        response.cart_actions.forEach((action) => {
            onCartAction(action.food_id, action.quantity_delta);
        });

        if (response.display_action) {
            onDisplayAction(response.display_action);

            return;
        }

        if (response.filter_action) {
            onFilterAction(response.filter_action);
        }
    };

    return (
        <div className="fixed right-4 bottom-4 z-40 flex flex-col items-end gap-3">
            {isOpen ? (
                <section
                    aria-label={t.chatAssistantLabel}
                    className="flex h-[520px] max-h-[calc(100vh-7rem)] w-[calc(100vw-2rem)] max-w-[380px] flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-2xl"
                >
                    <div className="flex items-center justify-between gap-3 border-b border-border bg-paper px-4 py-3">
                        <div className="min-w-0">
                            <p className="text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                                {t.chatKicker}
                            </p>
                            <h2 className="truncate text-base font-semibold">
                                {t.chatTitle}
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label={t.closeChat}
                            onClick={() => setIsOpen(false)}
                            className="grid h-9 w-9 shrink-0 place-items-center rounded-full border border-border bg-surface text-olive transition hover:border-brass hover:text-wine focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                        >
                            <CloseIcon />
                        </button>
                    </div>

                    <div className="flex flex-1 flex-col gap-3 overflow-y-auto p-3">
                        {messages.map((message) => (
                            <div
                                key={message.id}
                                className={`max-w-[86%] rounded-lg px-3 py-2 text-sm leading-5 ${
                                    message.role === 'user'
                                        ? 'ml-auto bg-olive text-white'
                                        : 'mr-auto border border-border bg-paper text-ink'
                                }`}
                            >
                                {renderChatText(message.text)}
                            </div>
                        ))}
                        {isSending ? (
                            <div className="mr-auto rounded-lg border border-border bg-paper px-3 py-2 text-sm text-muted">
                                {t.chatTyping}
                            </div>
                        ) : null}
                    </div>

                    <form
                        className="border-t border-border bg-paper p-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void submitMessage();
                        }}
                    >
                        <div className="grid grid-cols-[1fr_44px] gap-2">
                            <input
                                value={input}
                                onChange={(event) =>
                                    setInput(event.target.value)
                                }
                                placeholder={t.chatPlaceholder}
                                className="h-11 min-w-0 rounded-full border border-border bg-surface px-4 text-sm transition outline-none placeholder:text-muted focus:border-olive focus:ring-4 focus:ring-wine/20"
                            />
                            <button
                                type="submit"
                                aria-label={t.sendMessage}
                                disabled={isSending || input.trim() === ''}
                                className="grid h-11 w-11 place-items-center rounded-full border border-olive bg-olive text-white shadow-sm transition hover:bg-olive-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none disabled:cursor-not-allowed disabled:border-border disabled:bg-border"
                            >
                                <SendIcon />
                            </button>
                        </div>
                    </form>
                </section>
            ) : null}

            <button
                type="button"
                aria-label={isOpen ? t.closeChat : t.openChat}
                onClick={() => setIsOpen((current) => !current)}
                className="relative inline-flex h-14 max-w-[calc(100vw-2rem)] items-center gap-2 rounded-full border border-orange-600 bg-orange-500 px-4 text-white shadow-xl transition hover:-translate-y-0.5 hover:border-orange-700 hover:bg-orange-600 focus-visible:ring-4 focus-visible:ring-orange-500/25 focus-visible:outline-none"
            >
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 rounded-full border border-orange-300 motion-safe:animate-ping"
                />
                <span className="relative grid h-6 w-6 shrink-0 place-items-center">
                    {isOpen ? <CloseIcon /> : <ChatIcon />}
                </span>
                <span className="relative text-sm font-semibold whitespace-nowrap">
                    {t.chatButtonCta}
                </span>
            </button>
        </div>
    );
}

function LanguageSwitch({
    language,
    onChange,
}: {
    language: MenuLanguage;
    onChange: (language: MenuLanguage) => void;
}) {
    const t = uiText[language];

    return (
        <div
            aria-label={t.menuLanguage}
            className="grid h-10 grid-cols-2 overflow-hidden rounded-full border border-border bg-paper p-0.5 shadow-sm"
            role="group"
        >
            {(['vi', 'en'] as const).map((option) => (
                <button
                    key={option}
                    type="button"
                    aria-pressed={language === option}
                    onClick={() => onChange(option)}
                    className={`min-w-10 rounded-full px-2.5 text-sm font-semibold transition focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none ${
                        language === option
                            ? 'bg-olive text-white shadow-sm'
                            : 'text-olive hover:bg-surface'
                    }`}
                >
                    {option === 'vi' ? 'VI' : 'EN'}
                </button>
            ))}
        </div>
    );
}

function CategoryButton({
    category,
    language,
    isActive,
    onClick,
}: {
    category: Category;
    language: MenuLanguage;
    isActive: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={isActive}
            onClick={onClick}
            className={`flex min-w-32 items-center justify-between gap-3 rounded-full border px-3 py-2 text-left text-sm font-semibold transition focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none lg:rounded-lg ${
                isActive
                    ? 'border-olive bg-olive text-white shadow-sm'
                    : 'border-border bg-paper text-ink hover:border-brass hover:bg-surface'
            }`}
        >
            <span>{localizedText(category.labels, language)}</span>
            <span
                className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                    isActive ? 'bg-brass text-ink' : 'bg-surface text-wine'
                }`}
            >
                {category.count}
            </span>
        </button>
    );
}

function ProductCard({
    food,
    language,
    quantity,
    onOpen,
    onIncrement,
    onDecrement,
}: {
    food: Food;
    language: MenuLanguage;
    quantity: number;
    onOpen: () => void;
    onIncrement: () => void;
    onDecrement: () => void;
}) {
    const foodName = getFoodName(food, language);
    const foodDescription = getFoodDescription(food, language);

    return (
        <article
            role="button"
            tabIndex={0}
            onClick={onOpen}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    onOpen();
                }
            }}
            className="group grid cursor-pointer grid-rows-[220px_1fr] overflow-hidden rounded-lg border border-border bg-surface shadow-sm transition outline-none hover:-translate-y-0.5 hover:border-brass hover:shadow-md focus-visible:ring-4 focus-visible:ring-wine/20"
        >
            <div className="relative overflow-hidden bg-paper">
                <img
                    src={food.image_url}
                    alt={foodName}
                    className="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                    loading="lazy"
                />
                <span className="absolute top-3 left-3 rounded-full border border-white/70 bg-surface/95 px-3 py-1 text-xs font-semibold text-olive shadow-sm backdrop-blur-sm">
                    {localizedText(food.category_labels, language)}
                </span>
            </div>

            <div className="flex min-h-56 flex-col gap-3 p-4">
                <div className="flex flex-1 flex-col gap-2">
                    <h2 className="text-lg leading-tight font-semibold">
                        {foodName}
                    </h2>
                    <p className="line-clamp-2 text-sm leading-5 text-muted">
                        {foodDescription}
                    </p>
                    {food.property_labels.length > 0 ? (
                        <div className="flex flex-wrap gap-1.5">
                            {food.property_labels.slice(0, 3).map((labels) => (
                                <span
                                    key={localizedText(labels, 'en')}
                                    className="rounded-full border border-border bg-paper px-2 py-0.5 text-[11px] leading-5 font-semibold text-olive"
                                >
                                    {localizedText(labels, language)}
                                </span>
                            ))}
                        </div>
                    ) : null}
                </div>

                <div className="flex flex-col gap-3">
                    <p className="text-base font-semibold text-wine">
                        {food.formatted_price}
                    </p>
                </div>

                <QuantityStepper
                    language={language}
                    quantity={quantity}
                    onIncrement={onIncrement}
                    onDecrement={onDecrement}
                />
            </div>
        </article>
    );
}

function QuantityStepper({
    language,
    quantity,
    onIncrement,
    onDecrement,
}: {
    language: MenuLanguage;
    quantity: number;
    onIncrement: () => void;
    onDecrement: () => void;
}) {
    const t = uiText[language];

    return (
        <div className="grid h-11 grid-cols-[44px_1fr_44px] overflow-hidden rounded-full border border-border bg-paper">
            <button
                type="button"
                aria-label={t.decreaseQuantity}
                disabled={quantity === 0}
                onClick={(event) => {
                    event.stopPropagation();
                    onDecrement();
                }}
                className="grid place-items-center text-lg font-semibold text-olive transition focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none enabled:hover:bg-brass/25 disabled:cursor-not-allowed disabled:text-border"
            >
                -
            </button>
            <div className="flex items-center justify-center border-x border-border text-sm font-semibold">
                {quantity}
            </div>
            <button
                type="button"
                aria-label={t.increaseQuantity}
                onClick={(event) => {
                    event.stopPropagation();
                    onIncrement();
                }}
                className="grid place-items-center text-lg font-semibold text-olive transition hover:bg-brass/25 focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
            >
                +
            </button>
        </div>
    );
}

function ProductModal({
    food,
    language,
    quantity,
    onClose,
    onIncrement,
    onDecrement,
}: {
    food: Food;
    language: MenuLanguage;
    quantity: number;
    onClose: () => void;
    onIncrement: () => void;
    onDecrement: () => void;
}) {
    const foodName = getFoodName(food, language);
    const foodDescription = getFoodDescription(food, language);
    const t = uiText[language];

    return (
        <div
            role="presentation"
            onClick={onClose}
            className="fixed inset-0 z-50 flex items-end bg-ink/60 p-3 backdrop-blur-sm sm:items-center sm:justify-center"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="product-modal-title"
                onClick={(event) => event.stopPropagation()}
                className="grid h-[92vh] w-full max-w-3xl overflow-hidden rounded-lg border border-border bg-surface shadow-2xl sm:h-[min(760px,92vh)] sm:grid-cols-[minmax(0,0.95fr)_minmax(0,1fr)]"
            >
                <div className="h-64 bg-paper sm:h-full">
                    <img
                        src={food.image_url}
                        alt={foodName}
                        className="h-full w-full object-cover"
                    />
                </div>

                <div className="flex min-h-0 flex-col gap-4 overflow-y-auto p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                                {localizedText(food.category_labels, language)}
                            </p>
                            <h2
                                id="product-modal-title"
                                className="mt-1 text-2xl leading-tight font-semibold"
                            >
                                {foodName}
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label={t.closeDetails}
                            onClick={onClose}
                            className="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-border bg-paper text-olive transition hover:border-brass hover:text-wine focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                        >
                            <CloseIcon />
                        </button>
                    </div>

                    <p className="leading-6 text-muted">{foodDescription}</p>

                    {food.property_labels.length > 0 ? (
                        <div className="flex flex-wrap gap-2">
                            {food.property_labels.map((labels) => (
                                <span
                                    key={localizedText(labels, 'en')}
                                    className="rounded-full border border-border bg-paper px-3 py-1 text-xs font-semibold text-olive"
                                >
                                    {localizedText(labels, language)}
                                </span>
                            ))}
                        </div>
                    ) : null}

                    <div className="grid gap-4">
                        <DetailSection title={t.ingredients}>
                            <ul className="grid gap-2">
                                {food.ingredients.map((ingredient) => (
                                    <li
                                        key={`${ingredient.name}-${ingredient.quantity_grams}`}
                                        className="flex items-center justify-between gap-3 rounded-lg border border-border bg-paper px-3 py-2 text-sm"
                                    >
                                        <span className="font-semibold">
                                            {getIngredientName(
                                                ingredient,
                                                language,
                                            )}
                                        </span>
                                        <span className="shrink-0 text-muted">
                                            {ingredient.quantity_grams}
                                            {t.grams}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </DetailSection>

                        <DetailSection title={t.taste}>
                            <span className="inline-flex w-fit rounded-full border border-brass/60 bg-brass/20 px-3 py-1 text-sm font-semibold text-olive">
                                {localizedText(food.taste_labels, language)}
                            </span>
                        </DetailSection>

                        <DetailSection title={t.howMade}>
                            <p className="text-sm leading-6 text-muted">
                                {getFoodHowMade(food, language)}
                            </p>
                        </DetailSection>
                    </div>

                    <div className="mt-auto flex flex-col gap-4">
                        <div className="rounded-lg border border-wine/15 bg-wine px-4 py-3 text-xl font-semibold text-white shadow-sm">
                            {food.formatted_price}
                        </div>
                        <QuantityStepper
                            language={language}
                            quantity={quantity}
                            onIncrement={onIncrement}
                            onDecrement={onDecrement}
                        />
                    </div>
                </div>
            </section>
        </div>
    );
}

function DetailSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-2">
            <h3 className="text-xs font-semibold tracking-[0.14em] text-olive uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

function CartDrawer({
    items,
    language,
    totalVnd,
    cartItemCount,
    onClose,
    onIncrement,
    onDecrement,
}: {
    items: CartItem[];
    language: MenuLanguage;
    totalVnd: number;
    cartItemCount: number;
    onClose: () => void;
    onIncrement: (foodId: number) => void;
    onDecrement: (foodId: number) => void;
}) {
    const t = uiText[language];

    return (
        <div
            role="presentation"
            onClick={onClose}
            className="fixed inset-0 z-50 flex justify-end bg-ink/60 backdrop-blur-sm"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="cart-drawer-title"
                onClick={(event) => event.stopPropagation()}
                className="flex h-full w-full max-w-md flex-col border-l border-border bg-surface shadow-2xl"
            >
                <div className="flex items-start justify-between gap-4 border-b border-border bg-paper p-4">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                            {t.currentOrder}
                        </p>
                        <h2
                            id="cart-drawer-title"
                            className="mt-1 text-2xl leading-tight font-semibold"
                        >
                            {t.cart}
                        </h2>
                    </div>
                    <button
                        type="button"
                        aria-label={t.closeCart}
                        onClick={onClose}
                        className="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-border bg-surface text-olive transition hover:border-brass hover:text-wine focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                    >
                        <CloseIcon />
                    </button>
                </div>

                {items.length > 0 ? (
                    <div className="flex-1 overflow-y-auto p-4">
                        <div className="flex flex-col gap-3">
                            {items.map(({ food, quantity }) => (
                                <article
                                    key={food.id}
                                    className="rounded-lg border border-border bg-paper p-3 shadow-sm"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <h3 className="leading-tight font-semibold">
                                                {getFoodName(food, language)}
                                            </h3>
                                            <p className="mt-1 text-xs font-semibold text-olive">
                                                {t.unit}: {food.formatted_price}
                                            </p>
                                        </div>
                                        <p className="shrink-0 text-sm font-semibold text-wine">
                                            {formatVnd(
                                                food.price_vnd * quantity,
                                            )}
                                        </p>
                                    </div>

                                    <div className="mt-3 grid gap-2">
                                        <QuantityStepper
                                            language={language}
                                            quantity={quantity}
                                            onIncrement={() =>
                                                onIncrement(food.id)
                                            }
                                            onDecrement={() =>
                                                onDecrement(food.id)
                                            }
                                        />
                                        <div className="flex items-center justify-between gap-3 text-xs font-semibold text-muted">
                                            <span>
                                                {t.quantity}: {quantity}
                                            </span>
                                            <span>
                                                {t.lineTotal}:{' '}
                                                {formatVnd(
                                                    food.price_vnd * quantity,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-1 items-center justify-center p-6 text-center">
                        <div className="max-w-64">
                            <div className="mx-auto grid h-14 w-14 place-items-center rounded-full border border-border bg-paper text-wine shadow-sm">
                                <CartIcon />
                            </div>
                            <p className="mt-4 text-lg font-semibold">
                                {t.emptyCartTitle}
                            </p>
                            <p className="mt-2 text-sm leading-5 text-muted">
                                {t.emptyCartBody}
                            </p>
                        </div>
                    </div>
                )}

                <div className="border-t border-border bg-paper p-4">
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm font-semibold tracking-[0.14em] text-olive uppercase">
                            {t.total}
                        </span>
                        <span className="text-xl font-semibold text-wine">
                            {formatVnd(totalVnd)}
                        </span>
                    </div>
                    {items.length > 0 ? (
                        <Link
                            href={orderSuccess.url({
                                query: { language },
                            })}
                            className="mt-4 flex h-12 items-center justify-center rounded-full border border-wine bg-wine px-5 text-sm font-semibold text-white shadow-sm transition hover:border-wine-dark hover:bg-wine-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                        >
                            {t.orderItems(cartItemCount)}
                        </Link>
                    ) : null}
                </div>
            </section>
        </div>
    );
}

function CartIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2.4"
        >
            <path d="M6 6h15l-1.5 8.5H8L6 3H3" />
            <path d="M8 19.5h.01" />
            <path d="M18 19.5h.01" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2"
        >
            <path d="M18 6 6 18" />
            <path d="m6 6 12 12" />
        </svg>
    );
}

function ChatIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="h-6 w-6"
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2"
        >
            <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" />
        </svg>
    );
}

function SendIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="h-5 w-5"
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2.2"
        >
            <path d="m22 2-7 20-4-9-9-4Z" />
            <path d="M22 2 11 13" />
        </svg>
    );
}

function getFoodName(food: Food, language: MenuLanguage): string {
    if (language === 'en') {
        return food.name;
    }

    return food.vietnamese_name || food.name;
}

function getFoodDescription(food: Food, language: MenuLanguage): string {
    if (language === 'en') {
        return food.description || '';
    }

    return food.vietnamese_description || food.description || '';
}

function getFoodHowMade(food: Food, language: MenuLanguage): string {
    if (language === 'en') {
        return food.how_made || '';
    }

    return food.vietnamese_how_made || food.how_made || '';
}

function getIngredientName(
    ingredient: Ingredient,
    language: MenuLanguage,
): string {
    if (language === 'en') {
        return ingredient.name;
    }

    return ingredient.vietnamese_name || ingredient.name;
}

function localizedText(labels: LocalizedText, language: MenuLanguage): string {
    return labels[language] || labels.en;
}

function matchesActiveCategory(food: Food, activeCategory: string): boolean {
    return (
        activeCategory === ALL_CATEGORY_KEY || food.category === activeCategory
    );
}

function welcomeChatMessage(language: MenuLanguage): ChatMessage {
    return {
        id: `welcome-${language}`,
        role: 'assistant',
        text: uiText[language].chatWelcome,
    };
}

function renderChatText(text: string) {
    const lines = text.split('\n');

    return lines.map((line, lineIndex) => (
        <span key={`${lineIndex}-${line}`}>
            {renderChatLine(line)}
            {lineIndex < lines.length - 1 ? <br /> : null}
        </span>
    ));
}

function renderChatLine(line: string) {
    return line.split(/(\*\*[^*]+\*\*)/g).map((part, partIndex) => {
        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
            return (
                <strong key={partIndex} className="font-semibold">
                    {part.slice(2, -2)}
                </strong>
            );
        }

        return part;
    });
}

function formatVnd(value: number): string {
    return new Intl.NumberFormat('vi-VN', {
        currency: 'VND',
        maximumFractionDigits: 0,
        style: 'currency',
    }).format(value);
}

function cartPayload(quantities: Record<number, number>) {
    return Object.entries(quantities)
        .map(([foodId, quantity]) => ({
            food_id: Number(foodId),
            quantity,
        }))
        .filter((item) => item.quantity > 0);
}

function filterContextPayload(
    activeCategory: string,
    activePropertyKeys: string[],
) {
    return {
        category: activeCategory === ALL_CATEGORY_KEY ? null : activeCategory,
        property_keys: activePropertyKeys,
    };
}

async function pollTurn(
    turnId: number,
    language: MenuLanguage,
): Promise<ChatTurnResponse> {
    const deadline = Date.now() + 45_000;

    while (Date.now() < deadline) {
        await delay(1_000);

        const response = await getJson<ChatTurnResponse>(
            showChatMessage.url(turnId),
        );

        if (response.status !== 'pending' && response.status !== 'processing') {
            return response;
        }
    }

    return {
        turn_id: turnId,
        status: 'failed',
        reply: uiText[language].chatTimeout,
        cart_actions: [],
        filter_action: null,
        display_action: null,
        error: uiText[language].chatTimeoutError,
    };
}

async function postJson<T = unknown>(url: string, body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeader(),
        },
        body: body === undefined ? '{}' : JSON.stringify(body),
    });

    const payload = (await response.json()) as T;

    if (!response.ok) {
        throw payload;
    }

    return payload;
}

async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    const payload = (await response.json()) as T;

    if (!response.ok) {
        throw payload;
    }

    return payload;
}

function csrfHeader(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    if (!token) {
        return {};
    }

    return {
        'X-XSRF-TOKEN': decodeURIComponent(token),
    };
}

function createMessageId(): string {
    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function delay(milliseconds: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });
}
