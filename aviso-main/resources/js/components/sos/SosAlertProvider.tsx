import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { toast } from '@/lib/toast';
import { useSosAlarm } from '@/hooks/use-sos-alarm';
import { type PageProps } from '@/types';
import {
    type SosAlertPayload,
    type SosAlertResolvedPayload,
} from '@/types/models';

interface SosAlertContextValue {
    /** Every SOS alert still awaiting resolution, newest first. */
    alerts: SosAlertPayload[];
    /** Mark one resolved. Clears it locally first, restores it if the request fails. */
    resolveAlert: (id: string | number) => Promise<void>;
}

const SosAlertContext = createContext<SosAlertContextValue | null>(null);

/** The `sos` prop shared with every admin page by HandleInertiaRequests. */
type SharedSosProps = PageProps<{
    sos?: { pending: SosAlertPayload[] } | null;
}>;

/**
 * The single owner of the `riders.live` subscription, the unresolved-alert list,
 * and the alarm audio.
 *
 * It has to be single. Both the live map and the SOS history page used to
 * subscribe independently and both called `Echo.leave('riders.live')` on
 * unmount, which tears down the whole channel — so two subscribers could never
 * coexist. Everything now flows through this one subscription, and pages read it
 * through `useSosAlerts()`.
 *
 * Mount it in a persistent layout: its alarm and its socket must outlive any
 * single page, which is the whole point of making alerts visible everywhere.
 */
export function SosAlertProvider({ children }: { children: ReactNode }) {
    const { props } = usePage<SharedSosProps>();
    const seeded = props.sos?.pending;

    // Seeded from shared props so an in-progress emergency is correct on the
    // very first paint — Reverb only pushes new events, it never replays one
    // that is already active.
    const [alerts, setAlerts] = useState<SosAlertPayload[]>(() => seeded ?? []);

    const { startAlarm, stopAlarm, announceRider } = useSosAlarm();

    // Reconcile with the server on every navigation: a resolve performed in
    // another tab, or an alert raised while this tab was backgrounded, shows up
    // here. Keyed on the serialized ids so an unchanged list is a no-op.
    const seededKey = (seeded ?? []).map(a => a.id).join(',');
    useEffect(() => {
        if (!seeded) return;
        setAlerts(seeded);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [seededKey]);

    // Resume the alarm when the page loads mid-emergency, and silence it the
    // moment the last alert clears. startAlarm() queues itself if the browser
    // has not yet granted audio.
    useEffect(() => {
        if (alerts.length > 0) startAlarm();
        else stopAlarm();
    }, [alerts.length, startAlarm, stopAlarm]);

    const subscribedRef = useRef(false);
    useEffect(() => {
        // React 19 StrictMode double-invokes effects in development; without
        // this guard the channel would be subscribed twice and every alert
        // handled twice.
        if (subscribedRef.current) return;
        subscribedRef.current = true;

        // Private, not public: Reverb asks the backend to authorise this browser
        // against routes/channels.php before delivering anything, because the
        // payloads carry rider contact details and home addresses.
        const channel = window.Echo.private('riders.live');

        channel.listen('.emergency.triggered', (data: SosAlertPayload) => {
            setAlerts(prev => {
                // Match on alert id, never on rider code. Matching by rider used
                // to let a newly created duplicate overwrite an existing entry in
                // place, which made a fresh alert look like a resolved one
                // coming back.
                const index = prev.findIndex(a => a.id === data.id);
                if (index !== -1) {
                    const next = [...prev];
                    next[index] = data;
                    return next;
                }
                return [data, ...prev];
            });

            announceRider(data.rider_name ?? data.rider_code);
        });

        channel.listen('.emergency.resolved', (data: SosAlertResolvedPayload) => {
            setAlerts(prev => prev.filter(a => a.id !== data.id));
        });

        // Keep the pin following the rider after they trigger an SOS.
        channel.listen('.location.updated', (data: { rider_code: string; current_lat: string; current_lng: string }) => {
            setAlerts(prev =>
                prev.map(a =>
                    a.rider_code === data.rider_code
                        ? { ...a, latitude: parseFloat(data.current_lat), longitude: parseFloat(data.current_lng) }
                        : a,
                ),
            );
        });

        return () => {
            subscribedRef.current = false;
            window.Echo.leave('riders.live');
        };
    }, [announceRider]);

    const resolveAlert = useCallback(async (id: string | number) => {
        const numericId = Number(id);

        // Clear it optimistically so the banner and the map react instantly,
        // then persist. A failed request puts it back, because losing track of a
        // still-unresolved emergency is far worse than a brief flicker.
        let removed: SosAlertPayload | undefined;
        setAlerts(prev => {
            removed = prev.find(a => a.id === numericId);
            return prev.filter(a => a.id !== numericId);
        });

        try {
            await axios.put(route('sos-alerts.resolve', numericId));
        } catch {
            if (removed) {
                const restored = removed;
                setAlerts(prev =>
                    prev.some(a => a.id === restored.id) ? prev : [restored, ...prev],
                );
            }
            toast.error({
                title: 'Failed to resolve emergency',
                description: 'The alert is still marked as pending. Please try again.',
            });
        }
    }, []);

    return (
        <SosAlertContext.Provider value={{ alerts, resolveAlert }}>
            {children}
        </SosAlertContext.Provider>
    );
}

export function useSosAlerts(): SosAlertContextValue {
    const context = useContext(SosAlertContext);
    if (!context) {
        throw new Error('useSosAlerts must be used inside a SosAlertProvider (mounted in AdminLayout).');
    }
    return context;
}
