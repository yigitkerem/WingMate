import { createInertiaApp } from '@inertiajs/react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { StrictMode, useState } from 'react';
import type { ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import type { Root } from 'react-dom/client';
import { WingoChat } from '@/components/public-flight/wingo-chat';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

declare global {
    interface Window {
        dynamicPricerInertiaRoot?: Root;
    }
}

if (typeof window !== 'undefined') {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Dynamic Pricer';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'flight-search':
            case name === 'flight-results':
                return PageShell;
            case name.startsWith('auth/'):
                return [PageShell, AuthLayout];
            case name.startsWith('settings/'):
                return [PageShell, AppLayout, SettingsLayout];
            default:
                return [PageShell, AppLayout];
        }
    },
    setup({ el, App, props }) {
        if (!el) {
            return;
        }

        const app = (
            <StrictMode>
                <TooltipProvider delayDuration={0}>
                    <App {...props} />
                    <Toaster />
                </TooltipProvider>
            </StrictMode>
        );

        window.dynamicPricerInertiaRoot ??= createRoot(el);
        window.dynamicPricerInertiaRoot.render(app);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

function PageShell({ children }: { children: ReactNode }) {
    return (
        <>
            {children}
            <GlobalWingoChat />
        </>
    );
}

function GlobalWingoChat() {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <WingoChat
            isOpen={isOpen}
            onOpen={() => setIsOpen(true)}
            onClose={() => setIsOpen(false)}
        />
    );
}
