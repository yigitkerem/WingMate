import type Echo from 'laravel-echo';
import type Pusher from 'pusher-js';
import type { Auth } from '@/types/auth';

declare global {
    type BrowserSpeechRecognition = {
        lang: string;
        interimResults: boolean;
        maxAlternatives: number;
        onstart: (() => void) | null;
        onend: (() => void) | null;
        onerror: (() => void) | null;
        onresult:
            | ((event: {
                  results: ArrayLike<ArrayLike<{ transcript: string }>>;
              }) => void)
            | null;
        start: () => void;
    };

    type BrowserSpeechRecognitionConstructor =
        new () => BrowserSpeechRecognition;

    interface Window {
        Echo: Echo<'reverb'>;
        Pusher: typeof Pusher;
        SpeechRecognition?: BrowserSpeechRecognitionConstructor;
        webkitSpeechRecognition?: BrowserSpeechRecognitionConstructor;
    }
}

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
