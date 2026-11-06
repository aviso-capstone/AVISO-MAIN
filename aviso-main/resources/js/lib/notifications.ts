import { type AdminNotificationCategory, type AppNotification } from '@/types/models';

/** Filter chips of the admin Notifications page, in order. */
export const NOTIFICATION_CATEGORIES: { value: AdminNotificationCategory; label: string }[] = [
    { value: 'emergency', label: 'Emergency' },
    { value: 'hazard_reports', label: 'Hazard reports' },
    { value: 'accounts', label: 'Accounts' },
    { value: 'my_account', label: 'My account' },
];

export function categoryLabel(category: AdminNotificationCategory): string {
    return NOTIFICATION_CATEGORIES.find(c => c.value === category)?.label ?? category;
}

const PH_TIME_ZONE = 'Asia/Manila';

/** "2026-11-07" in Philippine time. */
export function phDateKey(iso: string | Date): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: PH_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(typeof iso === 'string' ? new Date(iso) : iso);
}

/** Today's and this week's (Monday) dates in Philippine time, for the date chips. */
export function phToday(): string {
    return phDateKey(new Date());
}

export function phWeekStart(): string {
    const today = new Date(`${phToday()}T00:00:00`);
    const daysSinceMonday = (today.getDay() + 6) % 7;
    today.setDate(today.getDate() - daysSinceMonday);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
}

/** "Today", "Yesterday" or "Thu, Nov 5, 2026". */
export function dayLabel(dateKey: string): string {
    const today = phToday();
    const yesterday = new Date(`${today}T00:00:00`);
    yesterday.setDate(yesterday.getDate() - 1);

    if (dateKey === today) return 'Today';
    if (dateKey === phDateKey(yesterday)) return 'Yesterday';

    return new Intl.DateTimeFormat('en-PH', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(`${dateKey}T00:00:00`));
}

/** "3:42 PM" in Philippine time. */
export function timeLabel(iso: string): string {
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: PH_TIME_ZONE,
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(iso));
}

export interface NotificationGroup {
    /** Stable React key. */
    key: string;
    /** Newest first. */
    items: AppNotification[];
}

export interface NotificationDay {
    dateKey: string;
    groups: NotificationGroup[];
}

/**
 * Days (newest first), and inside each day the notifications about the same
 * thing (same context_key) stacked into one group, newest group first.
 */
export function groupNotifications(notifications: AppNotification[]): NotificationDay[] {
    const days: NotificationDay[] = [];

    for (const notification of notifications) {
        const dateKey = phDateKey(notification.created_at);
        let day = days.find(d => d.dateKey === dateKey);
        if (!day) {
            day = { dateKey, groups: [] };
            days.push(day);
        }

        const group = notification.context_key
            ? day.groups.find(g => g.items[0].context_key === notification.context_key)
            : undefined;

        if (group) {
            group.items.push(notification);
        } else {
            day.groups.push({ key: notification.id, items: [notification] });
        }
    }

    return days;
}
