import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
});
import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sileo';
import 'sileo/styles.css';
import { router } from '@inertiajs/react';
import { ErrorBoundary } from '@/components/ErrorBoundary';
import { ThemeProvider } from '@/components/ThemeProvider';
import { toast } from '@/lib/toast';
import { useTheme } from 'next-themes';

import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

function ThemedToaster() {
    const { resolvedTheme } = useTheme();
    const isDark = resolvedTheme === 'dark';
    return (
        <Toaster
            position="top-center"
            theme={isDark ? 'light' : 'dark'}
            options={{
                roundness: 16,
                fill: isDark ? '#ffffff' : '#09090b',
            }}
        />
    );
}

const appName = import.meta.env.VITE_APP_NAME || 'AVISO';

type FlashProps = { success?: string; error?: string; info?: string } | undefined;

function showFlash(flash: FlashProps) {
    if (flash?.success) toast.success({ title: flash.success });
    if (flash?.error) toast.error({ title: flash.error });
    if (flash?.info) toast.info({ title: flash.info });
}

// First page load (e.g. "Welcome back" after login): Inertia only reports it
// through "navigate".
let firstPageShown = false;
router.on('navigate', (event) => {
    // Consume any pending session-storage toasts first
    toast.consumePending();

    if (firstPageShown) return;
    firstPageShown = true;
    showFlash(event.detail.page.props.flash as FlashProps);
});

// Every visit after that. "success" also fires when the server sends the admin
// back to the same page (deactivate, edit, mark all read…), where Inertia
// replaces the page and skips "navigate", so those messages were never shown.
router.on('success', (event) => {
    showFlash(event.detail.page.props.flash as FlashProps);
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <ThemeProvider>
                    <ThemedToaster />
                    <App {...props} />
                </ThemeProvider>
            </ErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
