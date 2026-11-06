import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NOTIFICATION_CATEGORIES, phToday, phWeekStart } from '@/lib/notifications';
import { type AdminNotificationCategory } from '@/types/models';

export interface NotificationFilterValues {
    category: AdminNotificationCategory | null;
    unread: boolean;
    from: string | null;
    to: string | null;
}

interface NotificationFiltersProps {
    filters: NotificationFilterValues;
    onChange: (next: NotificationFilterValues) => void;
}

type DateRange = 'all' | 'today' | 'week' | 'custom';

function rangeOf(filters: NotificationFilterValues): DateRange {
    if (!filters.from && !filters.to) return 'all';
    if (filters.from === phToday() && filters.to === phToday()) return 'today';
    if (filters.from === phWeekStart() && filters.to === phToday()) return 'week';
    return 'custom';
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <Button
            type="button"
            size="sm"
            variant={active ? 'default' : 'outline'}
            aria-pressed={active}
            onClick={onClick}
            className="rounded-full"
        >
            {children}
        </Button>
    );
}

/** Category chips, Unread / All, and the date range of the Notifications page. */
export function NotificationFilters({ filters, onChange }: NotificationFiltersProps) {
    const range = rangeOf(filters);
    const [showCustom, setShowCustom] = useState(range === 'custom');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const setRange = (next: DateRange) => {
        if (next === 'custom') {
            setShowCustom(true);
            return;
        }
        setShowCustom(false);
        const today = phToday();
        onChange({
            ...filters,
            from: next === 'today' ? today : next === 'week' ? phWeekStart() : null,
            to: next === 'all' ? null : today,
        });
    };

    const applyCustom = (e: React.FormEvent) => {
        e.preventDefault();
        onChange({ ...filters, from: from || null, to: to || null });
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Category">
                <Chip active={filters.category === null} onClick={() => onChange({ ...filters, category: null })}>
                    All
                </Chip>
                {NOTIFICATION_CATEGORIES.map(c => (
                    <Chip
                        key={c.value}
                        active={filters.category === c.value}
                        onClick={() => onChange({ ...filters, category: c.value })}
                    >
                        {c.label}
                    </Chip>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                <div className="flex gap-2" role="group" aria-label="Read status">
                    <Chip active={!filters.unread} onClick={() => onChange({ ...filters, unread: false })}>
                        All
                    </Chip>
                    <Chip active={filters.unread} onClick={() => onChange({ ...filters, unread: true })}>
                        Unread
                    </Chip>
                </div>

                <div className="flex flex-wrap gap-2" role="group" aria-label="Date">
                    <Chip active={range === 'all' && !showCustom} onClick={() => setRange('all')}>Any date</Chip>
                    <Chip active={range === 'today' && !showCustom} onClick={() => setRange('today')}>Today</Chip>
                    <Chip active={range === 'week' && !showCustom} onClick={() => setRange('week')}>This week</Chip>
                    <Chip active={showCustom || range === 'custom'} onClick={() => setRange('custom')}>Pick dates</Chip>
                </div>
            </div>

            {showCustom && (
                <form onSubmit={applyCustom} className="flex flex-wrap items-end gap-3">
                    <div className="space-y-1">
                        <Label htmlFor="notif-from">From</Label>
                        <Input id="notif-from" type="date" value={from} max={to || phToday()} onChange={e => setFrom(e.target.value)} className="w-44" />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="notif-to">To</Label>
                        <Input id="notif-to" type="date" value={to} min={from || undefined} max={phToday()} onChange={e => setTo(e.target.value)} className="w-44" />
                    </div>
                    <Button type="submit" size="sm" variant="secondary">Apply</Button>
                </form>
            )}
        </div>
    );
}
