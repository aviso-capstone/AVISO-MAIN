import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
    type ReactNode,
} from 'react';
import { router, usePage } from '@inertiajs/react';
import { toast } from '@/lib/toast';
import { type PageProps } from '@/types';
import { type AdminNotificationCategory, type AppNotificationBroadcast } from '@/types/models';

interface NotificationContextValue {
    /** Unread notifications of the logged-in admin (sidebar badge). */
    unread: number;
    /** Lower the badge right away after marking items read on the page. */
    setUnread: (next: number | ((prev: number) => number)) => void;
}

const NotificationContext = createContext<NotificationContextValue | null>(null);

/** The `notificationCount` prop shared with every admin page by HandleInertiaRequests. */
type SharedNotificationProps = PageProps<{
    notificationCount?: { unread: number } | null;
}>;

/**
 * Emergency and hazard reports already have their own banner, alarm and toast,
 * so only these categories pop up a toast of their own.
 */
const TOAST_CATEGORIES: AdminNotificationCategory[] = ['accounts', 'my_account'];

/**
 * The admin's own notifications for the whole session: the unread badge, kept
 * live on the private `App.Models.User.{id}` channel, and a toast for the
 * account notifications.
 */
export function NotificationProvider({ children }: { children: ReactNode }) {
    const { props } = usePage<SharedNotificationProps>();
    const userId = props.auth?.user?.id;
    // A new object on every full page response; kept as-is by partial reloads.
    const shared = props.notificationCount;

    const [unread, setUnread] = useState<number>(() => shared?.unread ?? 0);

    // Every page load brings the true count (e.g. read in another tab).
    useEffect(() => {
        if (shared) setUnread(shared.unread);
    }, [shared]);

    useEffect(() => {
        if (!userId) return;

        const channelName = `App.Models.User.${userId}`;
        const channel = window.Echo.private(channelName);

        channel.notification((data: AppNotificationBroadcast) => {
            setUnread(prev => prev + 1);

            if (TOAST_CATEGORIES.includes(data.category)) {
                toast.info({ title: data.title, description: data.body });
            }

            // Keep an open Notifications page current without a manual refresh.
            if (window.location.pathname === '/notifications') {
                router.reload({ only: ['notifications'] });
            }
        });

        return () => {
            window.Echo.leave(channelName);
        };
    }, [userId]);

    const update = useCallback((next: number | ((prev: number) => number)) => {
        setUnread(prev => Math.max(0, typeof next === 'function' ? next(prev) : next));
    }, []);

    return (
        <NotificationContext.Provider value={{ unread, setUnread: update }}>
            {children}
        </NotificationContext.Provider>
    );
}

export function useNotifications(): NotificationContextValue {
    const context = useContext(NotificationContext);
    if (!context) {
        throw new Error('useNotifications must be used inside NotificationProvider');
    }
    return context;
}
