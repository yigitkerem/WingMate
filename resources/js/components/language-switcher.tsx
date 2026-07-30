import { Languages } from 'lucide-react';
import { switchLocale, useTranslation } from '@/lib/i18n';
import type { Locale } from '@/lib/i18n';

type Props = {
    className?: string;
    variant?: 'light' | 'dark';
};

const locales: Locale[] = ['tr', 'en'];

export function LanguageSwitcher({ className = '', variant = 'dark' }: Props) {
    const { locale, t } = useTranslation();
    const isLight = variant === 'light';

    return (
        <div
            className={`inline-flex items-center gap-1 rounded-md border p-1 ${
                isLight
                    ? 'border-white/25 bg-white/10 text-white'
                    : 'border-slate-200 bg-white text-slate-700'
            } ${className}`}
            aria-label={t('language.switchTo')}
        >
            <Languages className="size-3.5" />
            {locales.map((item) => (
                <button
                    key={item}
                    type="button"
                    className={`rounded px-2 py-1 text-[11px] font-black transition-colors ${
                        item === locale
                            ? isLight
                                ? 'bg-white text-red-900'
                                : 'bg-slate-950 text-white'
                            : isLight
                              ? 'text-white/75 hover:text-white'
                              : 'text-slate-500 hover:text-slate-900'
                    }`}
                    onClick={() => switchLocale(item)}
                >
                    {item.toUpperCase()}
                </button>
            ))}
        </div>
    );
}
