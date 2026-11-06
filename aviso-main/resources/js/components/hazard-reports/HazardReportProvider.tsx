import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
    type ReactNode,
} from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { toast } from '@/lib/toast';
import { type PageProps } from '@/types';
import {
    type HazardReportPayload,
    type HazardReportReviewedPayload,
} from '@/types/models';

interface HazardReportContextValue {
    /** Riders' "hazard no longer there" reports waiting for review, newest first. */
    reports: HazardReportPayload[];
    /** Hazard log ids resolved during this session (their pins leave the map at once). */
    resolvedHazardIds: ReadonlySet<number>;
    /** The hazard is gone: resolve it. Rejects (after a toast) if it fails. */
    confirmReport: (id: number) => Promise<void>;
    /** The hazard is still there: close the report. Rejects if it fails. */
    rejectReport: (id: number) => Promise<void>;
}

const HazardReportContext = createContext<HazardReportContextValue | null>(null);

/** The `hazardReports` prop shared with every admin page by HandleInertiaRequests. */
type SharedHazardReportProps = PageProps<{
    hazardReports?: { pending: HazardReportPayload[] } | null;
}>;

/** Drop the closed reports and remember the resolved hazards. */
function applyReview(
    review: HazardReportReviewedPayload,
    setReports: (fn: (prev: HazardReportPayload[]) => HazardReportPayload[]) => void,
    setResolved: (fn: (prev: Set<number>) => Set<number>) => void,
) {
    const closed = new Set([review.report_id, ...review.closed_report_ids]);
    setReports(prev => prev.filter(r => !closed.has(r.id)));
    if (review.resolved_hazard_ids.length > 0) {
        setResolved(prev => new Set([...prev, ...review.resolved_hazard_ids]));
    }
}

/**
 * Riders' "hazard no longer there" reports for the whole admin session: the
 * pending list (Live Map panel, sidebar badge), a toast when a new one arrives,
 * and the confirm / reject actions.
 *
 * It listens on the same private `riders.live` channel the SOS provider owns,
 * and only stops its own two listeners on unmount — it must never call
 * `Echo.leave`, which would tear the SOS subscription down as well.
 */
export function HazardReportProvider({ children }: { children: ReactNode }) {
    const { props } = usePage<SharedHazardReportProps>();
    const seeded = props.hazardReports?.pending;

    const [reports, setReports] = useState<HazardReportPayload[]>(() => seeded ?? []);
    const [resolvedHazardIds, setResolvedHazardIds] = useState<Set<number>>(() => new Set());

    // Reconcile with the server on every navigation (a review done in another tab).
    const seededKey = (seeded ?? []).map(r => r.id).join(',');
    useEffect(() => {
        if (!seeded) return;
        setReports(seeded);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [seededKey]);

    useEffect(() => {
        const channel = window.Echo.private('riders.live');

        channel.listen('.hazard.report_submitted', (data: HazardReportPayload) => {
            setReports(prev => (prev.some(r => r.id === data.id) ? prev : [data, ...prev]));
            toast.info({
                title: 'Hazard reported as no longer there',
                description: `${data.rider_name ?? data.rider_code} sent a photo for ${data.hazard?.haz_code ?? 'a hazard'}. Check it on the Live Map.`,
            });
        });

        channel.listen('.hazard.report_reviewed', (data: HazardReportReviewedPayload) => {
            applyReview(data, setReports, setResolvedHazardIds);
        });

        return () => {
            channel.stopListening('.hazard.report_submitted');
            channel.stopListening('.hazard.report_reviewed');
        };
    }, []);

    // The server answers with the same review the broadcast carries, so the map
    // updates at once even when the socket is slow; applying it twice is harmless.
    const review = useCallback(async (id: number, action: 'confirm' | 'reject') => {
        try {
            const { data } = await axios.post<{ review: HazardReportReviewedPayload }>(
                route(`hazard-reports.${action}`, id),
            );
            applyReview(data.review, setReports, setResolvedHazardIds);
        } catch (error) {
            toast.error({
                title: action === 'confirm' ? 'Failed to resolve the hazard' : 'Failed to close the report',
                description: 'It may have been reviewed already. Please refresh and try again.',
            });
            throw error;
        }
    }, []);

    const confirmReport = useCallback(async (id: number) => {
        await review(id, 'confirm');
        toast.success({ title: 'Hazard resolved', description: 'Its pin is removed from the admin and rider maps.' });
    }, [review]);

    const rejectReport = useCallback(async (id: number) => {
        await review(id, 'reject');
        toast.success({ title: 'Report closed', description: 'The hazard stays on the map.' });
    }, [review]);

    return (
        <HazardReportContext.Provider value={{ reports, resolvedHazardIds, confirmReport, rejectReport }}>
            {children}
        </HazardReportContext.Provider>
    );
}

export function useHazardReports(): HazardReportContextValue {
    const context = useContext(HazardReportContext);
    if (!context) {
        throw new Error('useHazardReports must be used inside a HazardReportProvider (mounted in AdminLayout).');
    }
    return context;
}
