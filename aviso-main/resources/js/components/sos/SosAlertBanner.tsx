import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useSosAlerts } from '@/components/sos/SosAlertProvider';

/** Coarse "how long ago", refreshed on a slow tick — seconds precision is noise here. */
function elapsedLabel(triggeredAt: string): string {
    const secs = Math.floor((Date.now() - new Date(triggeredAt).getTime()) / 1000);
    if (secs < 60)    return `${secs}s ago`;
    if (secs < 3600)  return `${Math.floor(secs / 60)}m ago`;
    if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`;
    return `${Math.floor(secs / 86400)}d ago`;
}

/**
 * Site-wide emergency banner. Without it an admin only discovers an SOS if they
 * happen to be sitting on the live map, which is where the alert used to be
 * announced and nowhere else.
 *
 * Hidden on the live map itself, which already carries a richer "SOS Alerts N
 * ACTIVE" card directly above the map.
 */
export function SosAlertBanner() {
    const { alerts } = useSosAlerts();
    const { url } = usePage();

    const latest = alerts[0];
    const [elapsed, setElapsed] = useState(() => (latest ? elapsedLabel(latest.triggered_at) : ''));

    useEffect(() => {
        if (!latest) return;
        const update = () => setElapsed(elapsedLabel(latest.triggered_at));
        update();
        const timer = setInterval(update, 10_000);
        return () => clearInterval(timer);
    }, [latest?.triggered_at, latest]);

    if (alerts.length === 0 || url.startsWith('/map')) return null;

    const others = alerts.length - 1;

    return (
        <div
            role="alert"
            aria-live="assertive"
            className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg border border-red-500/60 bg-red-50/60 px-4 py-3 shadow-sm dark:bg-red-950/25"
        >
            <div className="relative shrink-0 rounded-lg bg-red-100 p-2 dark:bg-red-900/40">
                <span className="absolute inset-0 animate-ping rounded-lg bg-red-400 opacity-25" />
                <ShieldAlert className="h-5 w-5 text-red-600" />
            </div>

            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 text-sm leading-none font-semibold">
                    SOS Alert
                    <span className="inline-flex animate-pulse items-center rounded-full bg-red-500 px-2 py-0.5 text-[11px] font-bold text-white">
                        {alerts.length} ACTIVE
                    </span>
                </p>
                <p className="mt-1 text-xs font-medium text-red-600 dark:text-red-400">
                    {latest.rider_name || latest.rider_code} needs immediate assistance
                    <span className="text-muted-foreground font-normal"> · {elapsed}</span>
                    {others > 0 ? (
                        <span className="text-muted-foreground font-normal">
                            {' '}· and {others} more
                        </span>
                    ) : null}
                </p>
            </div>

            <Button
                size="sm"
                variant="destructive"
                className="shrink-0"
                onClick={() => router.visit(route('map', { alert: latest.id }))}
            >
                View on Live Map
            </Button>
        </div>
    );
}
