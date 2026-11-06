import { AlertTriangle, ArrowUpRight, Check, Siren, UserCog, Users, type LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { categoryLabel, dayLabel, groupNotifications, timeLabel } from '@/lib/notifications';
import { type AdminNotificationCategory, type AppNotification } from '@/types/models';

const CATEGORY_STYLE: Record<AdminNotificationCategory, { icon: LucideIcon; tone: string }> = {
    emergency:      { icon: Siren,         tone: 'bg-red-500/10 text-red-600 dark:text-red-400' },
    hazard_reports: { icon: AlertTriangle, tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
    accounts:       { icon: Users,         tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' },
    my_account:     { icon: UserCog,       tone: 'bg-purple-500/10 text-purple-600 dark:text-purple-400' },
};

interface NotificationGroupListProps {
    notifications: AppNotification[];
    onOpen: (notification: AppNotification) => void;
    onMarkRead: (notification: AppNotification) => void;
}

function NotificationRow({
    notification,
    compact,
    onOpen,
    onMarkRead,
}: {
    notification: AppNotification;
    compact: boolean;
    onOpen: (n: AppNotification) => void;
    onMarkRead: (n: AppNotification) => void;
}) {
    const unread = notification.read_at === null;

    return (
        <div className={cn('flex items-start gap-3 py-3', compact && 'py-2')}>
            <span
                aria-hidden
                className={cn('mt-2 h-2 w-2 shrink-0 rounded-full', unread ? 'bg-primary' : 'bg-transparent')}
            />
            <div className="min-w-0 flex-1">
                <p className={cn('text-sm leading-snug', unread ? 'font-semibold text-foreground' : 'text-foreground/80')}>
                    {notification.title}
                </p>
                <p className="mt-0.5 text-sm text-muted-foreground text-pretty">{notification.body}</p>
                <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                    {timeLabel(notification.created_at)}
                    {unread && <span className="sr-only"> · Unread</span>}
                </p>
            </div>
            <div className="flex shrink-0 items-center gap-1">
                {unread && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-muted-foreground"
                        onClick={() => onMarkRead(notification)}
                        aria-label={`Mark "${notification.title}" as read`}
                        title="Mark as read"
                    >
                        <Check className="h-4 w-4" />
                    </Button>
                )}
                {notification.link && (
                    <Button variant="outline" size="sm" className="gap-1" onClick={() => onOpen(notification)}>
                        Open
                        <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </Button>
                )}
            </div>
        </div>
    );
}

/**
 * Notifications by day, and inside a day the ones about the same thing (one
 * SOS, one hazard, one account) stacked in one card, newest first.
 */
export function NotificationGroupList({ notifications, onOpen, onMarkRead }: NotificationGroupListProps) {
    const days = groupNotifications(notifications);

    return (
        <div className="space-y-8">
            {days.map(day => (
                <section key={day.dateKey} aria-labelledby={`day-${day.dateKey}`}>
                    <h2 id={`day-${day.dateKey}`} className="mb-3 text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                        {dayLabel(day.dateKey)}
                    </h2>
                    <div className="space-y-3">
                        {day.groups.map(group => {
                            const latest = group.items[0];
                            const { icon: Icon, tone } = CATEGORY_STYLE[latest.category] ?? CATEGORY_STYLE.accounts;
                            const unreadInGroup = group.items.filter(n => n.read_at === null).length;

                            return (
                                <Card key={group.key} className="border-border/50 px-4 py-1 shadow-sm">
                                    <div className="flex items-start gap-3">
                                        <span className={cn('mt-3 flex h-9 w-9 shrink-0 items-center justify-center rounded-full', tone)}>
                                            <Icon className="h-4 w-4" aria-hidden="true" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 pt-3">
                                                <Badge variant="secondary" className="font-normal">
                                                    {categoryLabel(latest.category)}
                                                </Badge>
                                                {group.items.length > 1 && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {group.items.length} updates
                                                        {unreadInGroup > 0 && ` · ${unreadInGroup} unread`}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="divide-y divide-border/50">
                                                {group.items.map((notification, index) => (
                                                    <NotificationRow
                                                        key={notification.id}
                                                        notification={notification}
                                                        compact={index > 0}
                                                        onOpen={onOpen}
                                                        onMarkRead={onMarkRead}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </Card>
                            );
                        })}
                    </div>
                </section>
            ))}
        </div>
    );
}
