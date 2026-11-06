import { useEffect, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { BellOff, CheckCheck, ChevronLeft, ChevronRight } from 'lucide-react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { toast } from '@/lib/toast';
import { useNotifications } from '@/components/notifications/NotificationProvider';
import {
    NotificationFilters,
    type NotificationFilterValues,
} from './components/notifications/NotificationFilters';
import { NotificationGroupList } from './components/notifications/NotificationGroupList';
import { type AppNotification, type PaginatedData } from '@/types/models';

interface NotificationsPageProps {
    notifications: PaginatedData<AppNotification>;
    filters: NotificationFilterValues;
}

/** Only the filters that are set go into the URL. */
function toQuery(filters: NotificationFilterValues): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.category) query.category = filters.category;
    if (filters.unread) query.unread = '1';
    if (filters.from) query.from = filters.from;
    if (filters.to) query.to = filters.to;
    return query;
}

export default function NotificationsPage({ notifications, filters }: NotificationsPageProps) {
    const { unread, setUnread } = useNotifications();
    // Read marks applied at once; the next page load brings the server's truth.
    const [items, setItems] = useState(notifications.data);
    useEffect(() => setItems(notifications.data), [notifications.data]);

    const applyFilters = (next: NotificationFilterValues) => {
        router.get(route('notifications.index'), toQuery(next), { preserveScroll: true, preserveState: true });
    };

    const markReadLocally = (id: string) => {
        setItems(prev => prev.map(n => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)));
        setUnread(prev => prev - 1);
    };

    const markRead = async (notification: AppNotification) => {
        if (notification.read_at !== null) return;
        markReadLocally(notification.id);
        try {
            await axios.post(route('notifications.read', notification.id));
        } catch {
            toast.error({ title: 'Could not mark it as read', description: 'Please try again.' });
            router.reload({ only: ['notifications'] });
        }
    };

    const open = async (notification: AppNotification) => {
        if (!notification.link) return;
        if (notification.read_at === null) {
            markReadLocally(notification.id);
            // Best-effort: opening the item matters more than the read mark.
            await axios.post(route('notifications.read', notification.id)).catch(() => undefined);
        }
        router.visit(notification.link);
    };

    const markAllRead = () => {
        router.post(route('notifications.read-all'), {}, {
            preserveScroll: true,
            onSuccess: () => setUnread(0),
        });
    };

    const hasFilters = Object.keys(toQuery(filters)).length > 0;

    return (
        <>
            <Head title="Notifications" />

            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 className="text-3xl font-heading font-bold tracking-tight">Notifications</h1>
                    <p className="mt-1 text-muted-foreground">
                        Emergencies, hazard reports and account changes.
                        {unread > 0 && <> You have <span className="font-semibold text-foreground tabular-nums">{unread}</span> unread.</>}
                    </p>
                </div>
                <Button variant="outline" onClick={markAllRead} disabled={unread === 0} className="gap-2">
                    <CheckCheck className="h-4 w-4" aria-hidden="true" />
                    Mark all as read
                </Button>
            </div>

            <Card className="mb-6 border-border/50 p-4 shadow-sm">
                <NotificationFilters filters={filters} onChange={applyFilters} />
            </Card>

            {items.length === 0 ? (
                <Card className="flex flex-col items-center justify-center gap-2 border-border/50 py-16 text-center shadow-sm">
                    <BellOff className="h-8 w-8 text-muted-foreground" aria-hidden="true" />
                    <p className="font-medium">{hasFilters ? 'No notifications match these filters.' : 'No notifications yet.'}</p>
                    <p className="text-sm text-muted-foreground">
                        {hasFilters ? 'Try another category or date.' : 'New SOS alerts, hazard reports and account changes will show here.'}
                    </p>
                </Card>
            ) : (
                <NotificationGroupList notifications={items} onOpen={open} onMarkRead={markRead} />
            )}

            {notifications.last_page > 1 && (
                <div className="mt-6 flex items-center justify-between text-sm text-muted-foreground">
                    <span className="tabular-nums">
                        Page {notifications.current_page} of {notifications.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={notifications.current_page === 1}
                            onClick={() => router.get(route('notifications.index'), { ...toQuery(filters), page: notifications.current_page - 1 })}
                        >
                            <ChevronLeft className="mr-1 h-4 w-4" aria-hidden="true" />
                            Newer
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={notifications.current_page === notifications.last_page}
                            onClick={() => router.get(route('notifications.index'), { ...toQuery(filters), page: notifications.current_page + 1 })}
                        >
                            Older
                            <ChevronRight className="ml-1 h-4 w-4" aria-hidden="true" />
                        </Button>
                    </div>
                </div>
            )}
        </>
    );
}

NotificationsPage.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
