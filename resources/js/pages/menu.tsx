import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
    show as showChatMessage,
    store as storeChatMessage,
} from '@/actions/App/Http/Controllers/ChatMessageController';
import ChatSessionController from '@/actions/App/Http/Controllers/ChatSessionController';
import { success as orderSuccess } from '@/routes/order';

interface Category {
    key: string;
    label: string;
    count: number;
}

interface Food {
    id: number;
    name: string;
    vietnamese_name: string | null;
    slug: string;
    category: string;
    category_label: string;
    ingredients: string;
    vietnamese_description: string | null;
    formatted_price: string;
    price_vnd: number;
    image_url: string;
}

interface MenuProps {
    categories: Category[];
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

interface ChatTurnResponse {
    turn_id: number;
    status: 'pending' | 'processing' | 'completed' | 'failed';
    reply: string | null;
    cart_actions: ChatCartAction[];
    error: string | null;
}

interface ChatMessage {
    id: string;
    role: 'assistant' | 'user';
    text: string;
}

type MenuLanguage = 'vi' | 'en';

export default function Menu({ categories, foods }: MenuProps) {
    const [activeCategory, setActiveCategory] = useState<string>(
        () => categories[0]?.key ?? 'food',
    );
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [selectedFood, setSelectedFood] = useState<Food | null>(null);
    const [isCartOpen, setIsCartOpen] = useState(false);
    const [language, setLanguage] = useState<MenuLanguage>('vi');

    const visibleFoods = useMemo(
        () => foods.filter((food) => food.category === activeCategory),
        [activeCategory, foods],
    );

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

    const cartQuantity = useMemo(
        () => cartItems.reduce((total, item) => total + item.quantity, 0),
        [cartItems],
    );

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

    return (
        <>
            <Head title="Menu" />
            <main className="min-h-screen bg-paper text-ink">
                <div className="border-b border-border bg-surface/95 shadow-sm">
                    <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:px-6 lg:px-8">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold tracking-[0.18em] text-olive uppercase">
                                    An Uong AI Menu
                                </p>
                                <h1 className="mt-1 text-2xl leading-tight font-semibold sm:text-3xl">
                                    Food and drinks ready to browse
                                </h1>
                            </div>

                            <div className="flex shrink-0 items-center gap-2">
                                <LanguageSwitch
                                    language={language}
                                    onChange={setLanguage}
                                />
                                <button
                                    type="button"
                                    aria-label={
                                        cartQuantity > 0
                                            ? `Open cart, ${cartQuantity} items`
                                            : 'Open cart'
                                    }
                                    onClick={() => setIsCartOpen(true)}
                                    className="relative grid h-11 w-11 place-items-center rounded-full border border-border bg-paper text-olive shadow-sm transition hover:-translate-y-0.5 hover:border-brass hover:text-olive-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                                >
                                    <CartIcon />
                                    {cartQuantity > 0 ? (
                                        <span className="absolute -top-1.5 -right-1.5 grid min-h-5 min-w-5 place-items-center rounded-full border-2 border-surface bg-wine px-1 text-[11px] leading-none font-semibold text-white">
                                            {cartQuantity > 99
                                                ? '99+'
                                                : cartQuantity}
                                        </span>
                                    ) : null}
                                </button>
                            </div>
                        </div>

                        <div className="flex gap-2 overflow-x-auto pb-1 lg:hidden">
                            {categories.map((category) => (
                                <CategoryButton
                                    key={category.key}
                                    category={category}
                                    isActive={activeCategory === category.key}
                                    onClick={() =>
                                        setActiveCategory(category.key)
                                    }
                                />
                            ))}
                        </div>
                    </div>
                </div>

                <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:px-8">
                    <aside className="sticky top-6 hidden h-fit rounded-lg border border-border bg-surface p-3 shadow-sm lg:block">
                        <p className="mb-3 px-2 text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                            Categories
                        </p>
                        <div className="flex flex-col gap-2">
                            {categories.map((category) => (
                                <CategoryButton
                                    key={category.key}
                                    category={category}
                                    isActive={activeCategory === category.key}
                                    onClick={() =>
                                        setActiveCategory(category.key)
                                    }
                                />
                            ))}
                        </div>
                    </aside>

                    <section className="grid grid-cols-1 gap-4 min-[430px]:grid-cols-2 xl:grid-cols-3">
                        {visibleFoods.map((food) => (
                            <ProductCard
                                key={food.id}
                                food={food}
                                language={language}
                                quantity={quantities[food.id] ?? 0}
                                onOpen={() => setSelectedFood(food)}
                                onIncrement={() => updateQuantity(food.id, 1)}
                                onDecrement={() => updateQuantity(food.id, -1)}
                            />
                        ))}
                    </section>
                </div>
            </main>

            {isCartOpen ? (
                <CartDrawer
                    items={cartItems}
                    language={language}
                    totalVnd={cartTotalVnd}
                    cartQuantity={cartQuantity}
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
                language={language}
                onCartAction={(foodId, quantityDelta) =>
                    updateQuantity(foodId, quantityDelta)
                }
            />
        </>
    );
}

function MenuChat({
    quantities,
    language,
    onCartAction,
}: {
    quantities: Record<number, number>;
    language: MenuLanguage;
    onCartAction: (foodId: number, quantityDelta: number) => void;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const hasBootstrappedSession = useRef(false);
    const sessionLanguage = useRef<MenuLanguage | null>(null);
    const [isSessionReady, setIsSessionReady] = useState(false);
    const [input, setInput] = useState('');
    const [isSending, setIsSending] = useState(false);
    const [messages, setMessages] = useState<ChatMessage[]>([
        {
            id: 'welcome',
            role: 'assistant',
            text: 'Bạn muốn ăn gì hôm nay? Mình có thể gợi ý và thêm món vào giỏ.',
        },
    ]);

    useEffect(() => {
        if (!isOpen || hasBootstrappedSession.current) {
            return;
        }

        hasBootstrappedSession.current = true;

        const requestedLanguage = language;

        void postJson(ChatSessionController.url(), {
            language: requestedLanguage,
        })
            .then(() => {
                sessionLanguage.current = requestedLanguage;
                setIsSessionReady(true);
            })
            .catch(() => {
                setMessages((current) => [
                    ...current,
                    {
                        id: createMessageId(),
                        role: 'assistant',
                        text: 'Mình chưa mở được phiên chat. Vui lòng thử lại.',
                    },
                ]);
            });
    }, [isOpen, language]);

    useEffect(() => {
        if (!isSessionReady || sessionLanguage.current === language) {
            return;
        }

        const requestedLanguage = language;

        void postJson(ChatSessionController.url(), {
            language: requestedLanguage,
        })
            .then(() => {
                sessionLanguage.current = requestedLanguage;
            })
            .catch(() => {
                // Keep the menu responsive if a later language resync fails.
            });
    }, [isSessionReady, language]);

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
                    language,
                },
            );

            await handleTurnResponse(response);
        } catch {
            setMessages((current) => [
                ...current,
                {
                    id: createMessageId(),
                    role: 'assistant',
                    text: 'Mình chưa gửi được tin nhắn. Vui lòng thử lại.',
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
            const completedResponse = await pollTurn(response.turn_id);
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
                text:
                    response.reply ||
                    response.error ||
                    'Mình chưa có phản hồi phù hợp.',
            },
        ]);

        response.cart_actions.forEach((action) => {
            onCartAction(action.food_id, action.quantity_delta);
        });
    };

    return (
        <div className="fixed right-4 bottom-4 z-40 flex flex-col items-end gap-3">
            {isOpen ? (
                <section
                    aria-label="Chat ordering assistant"
                    className="flex h-[520px] max-h-[calc(100vh-7rem)] w-[calc(100vw-2rem)] max-w-[380px] flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-2xl"
                >
                    <div className="flex items-center justify-between gap-3 border-b border-border bg-paper px-4 py-3">
                        <div className="min-w-0">
                            <p className="text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                                Chat
                            </p>
                            <h2 className="truncate text-base font-semibold">
                                Trợ lý gọi món
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label="Close chat"
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
                                {message.text}
                            </div>
                        ))}
                        {isSending ? (
                            <div className="mr-auto rounded-lg border border-border bg-paper px-3 py-2 text-sm text-muted">
                                Đang trả lời...
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
                                placeholder="Nhập món hoặc khẩu vị..."
                                className="h-11 min-w-0 rounded-full border border-border bg-surface px-4 text-sm transition outline-none placeholder:text-muted focus:border-olive focus:ring-4 focus:ring-wine/20"
                            />
                            <button
                                type="submit"
                                aria-label="Send message"
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
                aria-label={isOpen ? 'Close chat' : 'Open chat'}
                onClick={() => setIsOpen((current) => !current)}
                className="grid h-14 w-14 place-items-center rounded-full border border-olive bg-olive text-white shadow-xl transition hover:-translate-y-0.5 hover:bg-olive-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
            >
                {isOpen ? <CloseIcon /> : <ChatIcon />}
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
    return (
        <div
            aria-label="Menu language"
            className="grid h-11 grid-cols-2 overflow-hidden rounded-full border border-border bg-paper p-1 shadow-sm"
            role="group"
        >
            {(['vi', 'en'] as const).map((option) => (
                <button
                    key={option}
                    type="button"
                    aria-pressed={language === option}
                    onClick={() => onChange(option)}
                    className={`min-w-11 rounded-full px-3 text-sm font-semibold transition focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none ${
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
    isActive,
    onClick,
}: {
    category: Category;
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
            <span>{category.label}</span>
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
                    {food.category_label}
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
                </div>

                <div className="flex flex-col gap-3">
                    <p className="text-base font-semibold text-wine">
                        {food.formatted_price}
                    </p>
                </div>

                <QuantityStepper
                    quantity={quantity}
                    onIncrement={onIncrement}
                    onDecrement={onDecrement}
                />
            </div>
        </article>
    );
}

function QuantityStepper({
    quantity,
    onIncrement,
    onDecrement,
}: {
    quantity: number;
    onIncrement: () => void;
    onDecrement: () => void;
}) {
    return (
        <div className="grid h-11 grid-cols-[44px_1fr_44px] overflow-hidden rounded-full border border-border bg-paper">
            <button
                type="button"
                aria-label="Decrease quantity"
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
                aria-label="Increase quantity"
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
                className="grid max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-lg border border-border bg-surface shadow-2xl sm:grid-cols-[minmax(0,0.95fr)_minmax(0,1fr)]"
            >
                <div className="h-64 bg-paper sm:h-full">
                    <img
                        src={food.image_url}
                        alt={foodName}
                        className="h-full w-full object-cover"
                    />
                </div>

                <div className="flex flex-col gap-4 p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.16em] text-olive uppercase">
                                {food.category_label}
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
                            aria-label="Close details"
                            onClick={onClose}
                            className="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-border bg-paper text-olive transition hover:border-brass hover:text-wine focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                        >
                            <CloseIcon />
                        </button>
                    </div>

                    <p className="leading-6 text-muted">{foodDescription}</p>

                    <div className="mt-auto flex flex-col gap-4">
                        <div className="rounded-lg border border-wine/15 bg-wine px-4 py-3 text-xl font-semibold text-white shadow-sm">
                            {food.formatted_price}
                        </div>
                        <QuantityStepper
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

function CartDrawer({
    items,
    language,
    totalVnd,
    cartQuantity,
    onClose,
    onIncrement,
    onDecrement,
}: {
    items: CartItem[];
    language: MenuLanguage;
    totalVnd: number;
    cartQuantity: number;
    onClose: () => void;
    onIncrement: (foodId: number) => void;
    onDecrement: (foodId: number) => void;
}) {
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
                            Current order
                        </p>
                        <h2
                            id="cart-drawer-title"
                            className="mt-1 text-2xl leading-tight font-semibold"
                        >
                            Cart
                        </h2>
                    </div>
                    <button
                        type="button"
                        aria-label="Close cart"
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
                                                Unit: {food.formatted_price}
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
                                            quantity={quantity}
                                            onIncrement={() =>
                                                onIncrement(food.id)
                                            }
                                            onDecrement={() =>
                                                onDecrement(food.id)
                                            }
                                        />
                                        <div className="flex items-center justify-between gap-3 text-xs font-semibold text-muted">
                                            <span>Quantity: {quantity}</span>
                                            <span>
                                                Line total:{' '}
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
                                Your cart is empty
                            </p>
                            <p className="mt-2 text-sm leading-5 text-muted">
                                Add foods from the menu to see them here.
                            </p>
                        </div>
                    </div>
                )}

                <div className="border-t border-border bg-paper p-4">
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm font-semibold tracking-[0.14em] text-olive uppercase">
                            Total
                        </span>
                        <span className="text-xl font-semibold text-wine">
                            {formatVnd(totalVnd)}
                        </span>
                    </div>
                    {items.length > 0 ? (
                        <Link
                            href={orderSuccess.url()}
                            className="mt-4 flex h-12 items-center justify-center rounded-full border border-wine bg-wine px-5 text-sm font-semibold text-white shadow-sm transition hover:border-wine-dark hover:bg-wine-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                        >
                            Order {cartQuantity} item
                            {cartQuantity === 1 ? '' : 's'}
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
        return food.ingredients;
    }

    return food.vietnamese_description || food.ingredients;
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

async function pollTurn(turnId: number): Promise<ChatTurnResponse> {
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
        reply: 'Mình vẫn đang chờ kết nối trợ lý. Vui lòng thử lại sau.',
        cart_actions: [],
        error: 'Timed out waiting for chat turn.',
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
