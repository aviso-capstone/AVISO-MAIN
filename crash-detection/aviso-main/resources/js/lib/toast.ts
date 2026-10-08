import { sileo } from 'sileo';

/**
 * The key used to store pending toast messages in sessionStorage.
 */
const PENDING_TOAST_KEY = 'aviso_pending_toast';

/**
 * Strongly typed interface for toast notifications.
 */
interface ToastOptions {
    title: string;
    description?: string;
}

/**
 * Type of toasts supported by the helper.
 */
type ToastType = 'success' | 'error' | 'warning' | 'info';

/**
 * Interface for the payload stored in sessionStorage.
 */
interface PendingToastPayload extends ToastOptions {
    type: ToastType;
}

/**
 * The global toast helper wrapper around Sileo.
 * This is the single source of truth for toast usages across the application.
 */
export const toast = {
    success: (options: ToastOptions | string) => {
        const payload = typeof options === 'string' ? { title: options } : options;
        sileo.success(payload);
    },

    error: (options: ToastOptions | string) => {
        const payload = typeof options === 'string' ? { title: options } : options;
        sileo.error(payload);
    },

    warning: (options: ToastOptions | string) => {
        const payload = typeof options === 'string' ? { title: options } : options;
        // If sileo has a specific warning method, use it, otherwise fallback to info/custom.
        // Assuming sileo has warning or info.
        if (typeof sileo.warning === 'function') {
            sileo.warning(payload);
        } else {
            sileo.info(payload); 
        }
    },

    info: (options: ToastOptions | string) => {
        const payload = typeof options === 'string' ? { title: options } : options;
        sileo.info(payload);
    },

    /**
     * Store a toast message in sessionStorage to be displayed after a redirect or full page reload.
     */
    setPending: (type: ToastType, options: ToastOptions | string) => {
        const payload: PendingToastPayload = {
            type,
            ...(typeof options === 'string' ? { title: options } : options)
        };
        sessionStorage.setItem(PENDING_TOAST_KEY, JSON.stringify(payload));
    },

    /**
     * Checks sessionStorage for any pending toasts, displays them, and clears the storage.
     * Call this once at the root layout of the application.
     */
    consumePending: () => {
        const pending = sessionStorage.getItem(PENDING_TOAST_KEY);
        if (pending) {
            try {
                const payload: PendingToastPayload = JSON.parse(pending);
                
                switch (payload.type) {
                    case 'success':
                        toast.success({ title: payload.title, description: payload.description });
                        break;
                    case 'error':
                        toast.error({ title: payload.title, description: payload.description });
                        break;
                    case 'warning':
                        toast.warning({ title: payload.title, description: payload.description });
                        break;
                    case 'info':
                        toast.info({ title: payload.title, description: payload.description });
                        break;
                }
            } catch (e) {
                console.error("Failed to parse pending toast", e);
            } finally {
                sessionStorage.removeItem(PENDING_TOAST_KEY);
            }
        }
    }
};
