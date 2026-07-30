import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, loadEnv } from 'vite';
import mkcert from 'vite-plugin-mkcert';

const loopbackOrigins =
    /^https?:\/\/(?:(?:[^:]+\.)?localhost|127\.0\.0\.1|\[::1\])(?::\d+)?$/;

function escapeRegex(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function resolveAppHost(appUrl: string | undefined): string {
    if (!appUrl) {
        return 'localhost';
    }

    try {
        return new URL(appUrl).hostname;
    } catch {
        return 'localhost';
    }
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appHost = resolveAppHost(env.APP_URL);

    return {
        server: {
            host: '0.0.0.0',
            allowedHosts: [appHost],
            cors: {
                origin: [
                    loopbackOrigins,
                    new RegExp(
                        `^https?:\\/\\/${escapeRegex(appHost)}(?::\\d+)?$`,
                    ),
                ],
            },
            hmr: {
                host: appHost,
            },
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
            }),
            inertia(),
            react({
                babel: {
                    plugins: ['babel-plugin-react-compiler'],
                },
            }),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
            mkcert({
                hosts: [appHost],
            }),
        ],
    };
});
