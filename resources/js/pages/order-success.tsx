import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { home } from '@/routes';

type OrderLanguage = 'vi' | 'en';
const redirectDelaySeconds = 3;

const orderSuccessText: Record<
    OrderLanguage,
    {
        pageTitle: string;
        kicker: string;
        title: string;
        body: (secondsRemaining: number) => string;
        countdownLabel: string;
        backHome: string;
    }
> = {
    vi: {
        pageTitle: 'Đặt món thành công',
        kicker: 'Hoàn tất đặt món',
        title: 'Đơn của bạn đã được ghi nhận.',
        body: (secondsRemaining) =>
            `Bạn sẽ được chuyển về trang chủ sau ${secondsRemaining} giây.`,
        countdownLabel: 'Đếm ngược chuyển trang',
        backHome: 'Về trang chủ',
    },
    en: {
        pageTitle: 'Order Success',
        kicker: 'Order complete',
        title: 'Your order was placed successfully.',
        body: (secondsRemaining) =>
            `You will be redirected back to the homepage in ${secondsRemaining} ${
                secondsRemaining === 1 ? 'second' : 'seconds'
            }.`,
        countdownLabel: 'Redirect countdown',
        backHome: 'Back to home',
    },
};

export default function OrderSuccess({
    language = 'vi',
}: {
    language?: OrderLanguage;
}) {
    const t = orderSuccessText[language];
    const [secondsRemaining, setSecondsRemaining] =
        useState(redirectDelaySeconds);

    useEffect(() => {
        const countdownTimer = window.setInterval(() => {
            setSecondsRemaining((currentSeconds) =>
                Math.max(currentSeconds - 1, 1),
            );
        }, 1000);

        const redirectTimer = window.setTimeout(() => {
            router.visit(home.url({ query: { language } }), {
                replace: true,
            });
        }, redirectDelaySeconds * 1000);

        return () => {
            window.clearInterval(countdownTimer);
            window.clearTimeout(redirectTimer);
        };
    }, [language]);

    return (
        <>
            <Head title={t.pageTitle} />
            <main className="flex min-h-screen items-center justify-center bg-paper px-4 py-10 text-ink">
                <section className="w-full max-w-xl text-center">
                    <div className="mx-auto grid h-16 w-16 place-items-center rounded-full bg-olive text-white shadow-sm">
                        <CheckIcon />
                    </div>
                    <p className="mt-6 text-xs font-semibold tracking-[0.18em] text-olive uppercase">
                        {t.kicker}
                    </p>
                    <h1 className="mt-2 text-3xl leading-tight font-semibold sm:text-4xl">
                        {t.title}
                    </h1>
                    <p className="mx-auto mt-3 max-w-sm text-sm leading-6 text-muted">
                        {t.body(secondsRemaining)}
                    </p>
                    <div
                        className="mx-auto mt-5 grid h-14 w-14 place-items-center rounded-full border border-olive/20 bg-surface text-2xl font-semibold text-olive shadow-sm"
                        aria-live="polite"
                    >
                        <span className="sr-only">{t.countdownLabel}</span>
                        {secondsRemaining}
                    </div>
                    <Link
                        href={home.url({ query: { language } })}
                        className="mt-8 inline-flex items-center justify-center rounded-full border border-wine bg-wine px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:border-wine-dark hover:bg-wine-dark focus-visible:ring-4 focus-visible:ring-wine/20 focus-visible:outline-none"
                    >
                        {t.backHome}
                    </Link>
                </section>
            </main>
        </>
    );
}

function CheckIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            className="h-8 w-8"
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth="2.4"
        >
            <path d="M20 6 9 17l-5-5" />
        </svg>
    );
}
