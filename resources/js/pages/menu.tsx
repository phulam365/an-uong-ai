import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import type { MouseEvent } from 'react';

interface Category {
    key: string;
    label: string;
    count: number;
}

interface Food {
    id: number;
    name: string;
    slug: string;
    category: string;
    category_label: string;
    ingredients: string;
    formatted_price: string;
    price_vnd: number;
    image_url: string;
}

interface MenuProps {
    categories: Category[];
    foods: Food[];
}

export default function Menu({ categories, foods }: MenuProps) {
    const [activeCategory, setActiveCategory] = useState<string>(
        () => categories[0]?.key ?? 'food',
    );
    const [quantities, setQuantities] = useState<Record<number, number>>({});
    const [selectedFood, setSelectedFood] = useState<Food | null>(null);

    const visibleFoods = useMemo(
        () => foods.filter((food) => food.category === activeCategory),
        [activeCategory, foods],
    );

    const selectedCount = useMemo(
        () =>
            Object.values(quantities).reduce(
                (total, quantity) => total + quantity,
                0,
            ),
        [quantities],
    );

    useEffect(() => {
        if (!selectedFood) {
            return;
        }

        const originalOverflow = document.body.style.overflow;
        const handleKeyDown = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                setSelectedFood(null);
            }
        };

        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.body.style.overflow = originalOverflow;
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [selectedFood]);

    const updateQuantity = (foodId: number, change: number): void => {
        setQuantities((current) => {
            const nextQuantity = Math.max((current[foodId] ?? 0) + change, 0);

            return {
                ...current,
                [foodId]: nextQuantity,
            };
        });
    };

    const stopAndUpdateQuantity = (
        event: MouseEvent<HTMLButtonElement>,
        foodId: number,
        change: number,
    ): void => {
        event.stopPropagation();
        updateQuantity(foodId, change);
    };

    return (
        <>
            <Head title="Menu" />
            <main className="min-h-screen bg-[#f8f3e6] text-[#21170f]">
                <div className="border-b-4 border-[#d71920] bg-[#ffc72c]">
                    <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-4 sm:px-6 lg:px-8">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold tracking-[0.18em] text-[#8a1116] uppercase">
                                    An Uong AI Menu
                                </p>
                                <h1 className="text-2xl font-black sm:text-3xl">
                                    Food and drinks ready to browse
                                </h1>
                            </div>
                            <div className="rounded-md bg-[#21170f] px-4 py-2 text-sm font-bold text-white">
                                {selectedCount} selected
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

                <div className="mx-auto grid max-w-7xl gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:px-8">
                    <aside className="sticky top-5 hidden h-fit rounded-md border-2 border-[#21170f] bg-white p-3 shadow-[6px_6px_0_#d71920] lg:block">
                        <p className="mb-3 px-2 text-xs font-black tracking-[0.16em] text-[#8a1116] uppercase">
                            Categories
                        </p>
                        <div className="flex flex-col gap-2">
                            {categories.map((category) => (
                                <CategoryButton
                                    key={category.key}
                                    category={category}
                                    isActive={
                                        activeCategory === category.key
                                    }
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
                                quantity={quantities[food.id] ?? 0}
                                onOpen={() => setSelectedFood(food)}
                                onIncrement={(event) =>
                                    stopAndUpdateQuantity(event, food.id, 1)
                                }
                                onDecrement={(event) =>
                                    stopAndUpdateQuantity(event, food.id, -1)
                                }
                            />
                        ))}
                    </section>
                </div>
            </main>

            {selectedFood ? (
                <ProductModal
                    food={selectedFood}
                    quantity={quantities[selectedFood.id] ?? 0}
                    onClose={() => setSelectedFood(null)}
                    onIncrement={() => updateQuantity(selectedFood.id, 1)}
                    onDecrement={() => updateQuantity(selectedFood.id, -1)}
                />
            ) : null}
        </>
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
            className={`flex min-w-32 items-center justify-between gap-3 rounded-md border-2 px-3 py-2 text-left text-sm font-black transition ${
                isActive
                    ? 'border-[#21170f] bg-[#d71920] text-white shadow-[3px_3px_0_#21170f]'
                    : 'border-[#f1d68b] bg-white text-[#21170f] hover:border-[#21170f]'
            }`}
        >
            <span>{category.label}</span>
            <span
                className={`rounded-full px-2 py-0.5 text-xs ${
                    isActive
                        ? 'bg-[#ffc72c] text-[#21170f]'
                        : 'bg-[#fff5d0] text-[#8a1116]'
                }`}
            >
                {category.count}
            </span>
        </button>
    );
}

function ProductCard({
    food,
    quantity,
    onOpen,
    onIncrement,
    onDecrement,
}: {
    food: Food;
    quantity: number;
    onOpen: () => void;
    onIncrement: (event: MouseEvent<HTMLButtonElement>) => void;
    onDecrement: (event: MouseEvent<HTMLButtonElement>) => void;
}) {
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
            className="group grid cursor-pointer grid-rows-[160px_1fr] overflow-hidden rounded-md border-2 border-[#21170f] bg-white shadow-[5px_5px_0_#ffc72c] outline-none transition hover:-translate-y-0.5 hover:shadow-[7px_7px_0_#d71920] focus-visible:ring-4 focus-visible:ring-[#d71920]/35"
        >
            <div className="relative overflow-hidden bg-[#fff0bb]">
                <img
                    src={food.image_url}
                    alt={food.name}
                    className="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                    loading="lazy"
                />
                <span className="absolute top-3 left-3 rounded-full bg-white px-3 py-1 text-xs font-black text-[#8a1116] shadow">
                    {food.category_label}
                </span>
            </div>

            <div className="flex min-h-56 flex-col gap-3 p-4">
                <div className="flex flex-1 flex-col gap-2">
                    <h2 className="text-lg leading-tight font-black">
                        {food.name}
                    </h2>
                    <p className="line-clamp-2 text-sm leading-5 text-[#66513d]">
                        {food.ingredients}
                    </p>
                </div>

                <div className="flex items-center justify-between gap-3">
                    <p className="text-base font-black text-[#d71920]">
                        {food.formatted_price}
                    </p>
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            onOpen();
                        }}
                        className="rounded-md border-2 border-[#21170f] px-3 py-1 text-sm font-black hover:bg-[#ffc72c]"
                    >
                        View
                    </button>
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
    onIncrement: (event: MouseEvent<HTMLButtonElement>) => void;
    onDecrement: (event: MouseEvent<HTMLButtonElement>) => void;
}) {
    return (
        <div className="grid h-11 grid-cols-[44px_1fr_44px] overflow-hidden rounded-md border-2 border-[#21170f] bg-[#fff9e8]">
            <button
                type="button"
                aria-label="Decrease quantity"
                disabled={quantity === 0}
                onClick={onDecrement}
                className="text-xl font-black disabled:cursor-not-allowed disabled:text-[#b7a68d] enabled:hover:bg-[#ffc72c]"
            >
                -
            </button>
            <div className="flex items-center justify-center border-x-2 border-[#21170f] text-sm font-black">
                {quantity}
            </div>
            <button
                type="button"
                aria-label="Increase quantity"
                onClick={onIncrement}
                className="text-xl font-black hover:bg-[#ffc72c]"
            >
                +
            </button>
        </div>
    );
}

function ProductModal({
    food,
    quantity,
    onClose,
    onIncrement,
    onDecrement,
}: {
    food: Food;
    quantity: number;
    onClose: () => void;
    onIncrement: () => void;
    onDecrement: () => void;
}) {
    return (
        <div
            role="presentation"
            onClick={onClose}
            className="fixed inset-0 z-50 flex items-end bg-black/55 p-3 sm:items-center sm:justify-center"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="product-modal-title"
                onClick={(event) => event.stopPropagation()}
                className="grid max-h-[92vh] w-full max-w-3xl overflow-hidden rounded-md border-2 border-[#21170f] bg-white shadow-[8px_8px_0_#ffc72c] sm:grid-cols-[minmax(0,0.95fr)_minmax(0,1fr)]"
            >
                <div className="h-64 bg-[#fff0bb] sm:h-full">
                    <img
                        src={food.image_url}
                        alt={food.name}
                        className="h-full w-full object-cover"
                    />
                </div>

                <div className="flex flex-col gap-4 p-5">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-black tracking-[0.16em] text-[#8a1116] uppercase">
                                {food.category_label}
                            </p>
                            <h2
                                id="product-modal-title"
                                className="mt-1 text-2xl leading-tight font-black"
                            >
                                {food.name}
                            </h2>
                        </div>
                        <button
                            type="button"
                            aria-label="Close details"
                            onClick={onClose}
                            className="grid h-10 w-10 shrink-0 place-items-center rounded-md border-2 border-[#21170f] text-xl font-black hover:bg-[#ffc72c]"
                        >
                            x
                        </button>
                    </div>

                    <p className="leading-6 text-[#66513d]">
                        {food.ingredients}
                    </p>

                    <div className="mt-auto flex flex-col gap-4">
                        <div className="rounded-md bg-[#d71920] px-4 py-3 text-xl font-black text-white">
                            {food.formatted_price}
                        </div>
                        <QuantityStepper
                            quantity={quantity}
                            onIncrement={(event) => {
                                event.stopPropagation();
                                onIncrement();
                            }}
                            onDecrement={(event) => {
                                event.stopPropagation();
                                onDecrement();
                            }}
                        />
                    </div>
                </div>
            </section>
        </div>
    );
}
