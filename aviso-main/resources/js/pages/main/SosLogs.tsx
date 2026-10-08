import { useEffect, useRef, type ReactNode } from "react";
import { Head, router } from "@inertiajs/react";
import AdminLayout from "@/layouts/AdminLayout";
import { toast } from "@/lib/toast";
import { useSosAlerts } from "@/components/sos/SosAlertProvider";
import { SosStats } from "./components/sos/SosStats";
import { SosTable } from "./components/sos/SosTable";
import { EmergencyHotlinesPanel } from "./components/sos/EmergencyHotlinesPanel";
import {
    type PaginatedData,
    type SosRiderSummary,
} from "@/types/models";

interface PageProps {
    riders: PaginatedData<SosRiderSummary>;
    stats: {
        total_today: number;
        total_week: number;
        pending: number;
        resolved: number;
    };
    filters: {
        search?: string;
        status?: string;
    };
}

export default function SosLogs({ riders, stats, filters }: PageProps) {
    // The global provider owns the only riders.live subscription — this page
    // used to open a second one and tear the shared channel down on unmount.
    // Reacting to the alert count keeps the table and stats in step with both
    // incoming alerts and resolutions made elsewhere.
    const { alerts } = useSosAlerts();
    const alertCount = alerts.length;
    const previousCountRef = useRef(alertCount);

    useEffect(() => {
        if (alertCount === previousCountRef.current) return;

        if (alertCount > previousCountRef.current) {
            const newest = alerts[0];
            toast.info({
                title: "New SOS Alert",
                description: `${newest?.rider_name ?? newest?.username ?? "A rider"} triggered an emergency.`,
            });
        }

        previousCountRef.current = alertCount;
        router.reload({ only: ["riders", "stats"] });
        // `alerts` is read only to name the newest rider; the count is the trigger.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [alertCount]);

    return (
        <>
            <Head title="SOS Alert History" />

            {/* Header */}
            <div className="flex justify-between items-end mb-6">
                <div>
                    <h1 className="text-3xl font-heading font-bold tracking-tight">
                        SOS Alert History
                    </h1>
                    <p className="text-muted-foreground mt-1">
                        Rider-triggered emergency alerts and dispatch status.
                    </p>
                </div>
            </div>

            <SosStats stats={stats} />

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2">
                    <SosTable riders={riders} filters={filters} />
                </div>
                <EmergencyHotlinesPanel />
            </div>
        </>
    );
}

SosLogs.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
