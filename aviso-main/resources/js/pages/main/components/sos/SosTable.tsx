import { useState } from "react";
import { router } from "@inertiajs/react";
import axios from "axios";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from "@/components/ui/accordion";
import {
    Search,
    Siren,
    MapPin,
    ChevronLeft,
    ChevronRight,
    Loader2,
    CheckCircle2,
} from "lucide-react";
import { toast } from "@/lib/toast";
import { ResolveDialog } from "./ResolveDialog";
import {
    etaLabel,
    minutesBetween,
    statusLabel,
    type ResolveFormData,
} from "./dispatchOptions";
import { reverseGeocode } from "@/lib/geocoding";
import {
    type EmergencyAlert,
    type PaginatedData,
    type SosRiderSummary,
} from "@/types/models";

interface SosTableProps {
    riders: PaginatedData<SosRiderSummary>;
    filters: {
        search?: string;
        status?: string;
    };
}

const statusBadgeClass = (status: string) => {
    switch (status) {
        case "pending":
            return "bg-destructive/10 text-destructive border-destructive/30";
        case "acknowledged":
            return "text-amber-600 bg-amber-100 border-amber-200 dark:bg-amber-900/30 dark:border-amber-900";
        case "resolved":
            return "text-green-600 bg-green-100 border-green-200 dark:bg-green-900/30 dark:border-green-900";
        default:
            return "text-gray-600 bg-gray-100 border-gray-200";
    }
};

const formatDateTime = (value: string) =>
    new Date(value).toLocaleString([], {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });

export function SosTable({ riders, filters }: SosTableProps) {
    const [searchQuery, setSearchQuery] = useState(filters.search || "");
    const [historyByRider, setHistoryByRider] = useState<
        Record<number, EmergencyAlert[]>
    >({});
    const [addressByAlertId, setAddressByAlertId] = useState<
        Record<number, string | null>
    >({});
    const [loadingRiderId, setLoadingRiderId] = useState<number | null>(null);
    // The alert whose resolve dialog is open, with its rider (for the list update).
    const [resolving, setResolving] = useState<{ alert: EmergencyAlert; riderId: number; riderName: string } | null>(null);
    // Controlled so we can fetch history BEFORE the accordion opens — Radix
    // measures content height once at open time, so revealing a taller list
    // asynchronously after opening leaves it clipped at the loading spinner's
    // (shorter) height.
    const [openRiderId, setOpenRiderId] = useState("");

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            route("sos-alerts.index"),
            { ...filters, search: searchQuery },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleValueChange = async (value: string) => {
        if (!value) {
            setOpenRiderId("");
            return;
        }

        const riderId = Number(value);
        if (historyByRider[riderId]) {
            setOpenRiderId(value);
            return;
        }

        setLoadingRiderId(riderId);
        try {
            const { data } = await axios.get(
                route("sos-alerts.history", riderId),
            );
            const alerts: EmergencyAlert[] = data.alerts;

            // Resolve addresses before opening — same reason history is
            // fetched up front: Radix measures content height once, at open
            // time, so anything that changes the content's height afterward
            // (like an address swapping in for a coordinate pair) gets clipped.
            const addresses = await Promise.all(
                alerts.map((alert) =>
                    reverseGeocode(
                        Number(alert.longitude),
                        Number(alert.latitude),
                    ).catch(() => null),
                ),
            );

            setHistoryByRider((prev) => ({ ...prev, [riderId]: alerts }));
            setAddressByAlertId((prev) => {
                const next = { ...prev };
                alerts.forEach((alert, i) => {
                    next[alert.id] = addresses[i];
                });
                return next;
            });
            setOpenRiderId(value);
        } catch {
            toast.error({
                title: "Failed to load SOS history",
                description: "Please try expanding the rider again.",
            });
        } finally {
            setLoadingRiderId(null);
        }
    };

    // Rejects on failure so the dialog stays open and shows the reason.
    const handleResolve = async (data: ResolveFormData) => {
        if (!resolving) return;
        const { alert, riderId } = resolving;
        const { data: res } = await axios.put<{ alert: { outcome_label: string | null; resolved_at: string | null } }>(
            route("sos-alerts.resolve", alert.id),
            data,
        );
        setHistoryByRider((prev) => ({
            ...prev,
            [riderId]: prev[riderId].map((a) =>
                a.id === alert.id
                    ? {
                          ...a,
                          status: "resolved",
                          resolved_at: res.alert.resolved_at ?? new Date().toISOString(),
                          outcome: data.outcome,
                          outcome_label: res.alert.outcome_label,
                          resolution_note: data.resolution_note,
                      }
                    : a,
            ),
        }));
        router.reload({ only: ["riders", "stats"] });
        toast.success({ title: "Alert marked as resolved" });
    };

    return (
        <Card className="border-border/50 shadow-sm">
            {/* Filters */}
            <div className="p-4 border-b flex flex-col sm:flex-row gap-4 items-center bg-muted/20">
                <form onSubmit={submitSearch} className="relative w-full sm:w-72">
                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder="Search rider name or username..."
                        className="pl-9 bg-background"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                    />
                </form>
            </div>

            {/* Header row */}
            <div className="grid grid-cols-[1fr_90px_150px_110px_32px] gap-2 px-4 py-3 bg-muted/30 border-b text-sm font-semibold">
                <span>Rider</span>
                <span className="text-center">SOS Count</span>
                <span>Most Recent</span>
                <span>Status</span>
                <span />
            </div>

            {/* Rider rows */}
            {riders.data.length === 0 ? (
                <div className="h-32 flex items-center justify-center text-muted-foreground text-sm">
                    No SOS alerts found matching your filters.
                </div>
            ) : (
                <Accordion
                    type="single"
                    collapsible
                    value={openRiderId}
                    onValueChange={handleValueChange}
                >
                    {riders.data.map((rider) => {
                        const latest = rider.emergency_alerts[0];
                        const history = historyByRider[rider.id];
                        const isLoading = loadingRiderId === rider.id;

                        return (
                            <AccordionItem
                                key={rider.id}
                                value={String(rider.id)}
                                className="px-4"
                            >
                                <AccordionTrigger
                                    className="hover:no-underline hover:bg-muted/20 rounded-none py-3"
                                    disabled={isLoading}
                                >
                                    <div className="grid grid-cols-[1fr_90px_150px_110px] gap-2 items-center w-full text-sm">
                                        <div>
                                            <p className="font-medium">
                                                {rider.first_name} {rider.last_name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                @{rider.username}
                                            </p>
                                        </div>
                                        <span className="text-center">
                                            <Badge
                                                variant="outline"
                                                className="gap-1 font-mono bg-destructive/10 text-destructive border-destructive/30"
                                            >
                                                {isLoading ? (
                                                    <Loader2 className="w-3 h-3 animate-spin" />
                                                ) : (
                                                    <Siren className="w-3 h-3" />
                                                )}
                                                {rider.emergency_alerts_count}
                                            </Badge>
                                        </span>
                                        <span className="text-muted-foreground whitespace-nowrap">
                                            {formatDateTime(
                                                rider.emergency_alerts_max_triggered_at,
                                            )}
                                        </span>
                                        <span>
                                            {latest && (
                                                <Badge
                                                    variant="outline"
                                                    className={statusBadgeClass(latest.status)}
                                                >
                                                    {statusLabel(latest.status, latest.on_scene_at)}
                                                </Badge>
                                            )}
                                        </span>
                                    </div>
                                </AccordionTrigger>
                                <AccordionContent>
                                    <div className="space-y-2 pb-2">
                                        {(history ?? []).map((alert) => (
                                            <div
                                                key={alert.id}
                                                className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md border border-border/50 bg-muted/20 px-3 py-2 text-sm"
                                            >
                                                <span className="text-muted-foreground whitespace-nowrap">
                                                    {formatDateTime(alert.triggered_at)}
                                                </span>
                                                <a
                                                    href={route("map", {
                                                        alert: alert.id,
                                                    })}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    title={`${Number(alert.latitude).toFixed(5)}, ${Number(alert.longitude).toFixed(5)} — open on Live Map`}
                                                    className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    <MapPin className="w-3 h-3 shrink-0" />
                                                    <span
                                                        className={
                                                            addressByAlertId[alert.id]
                                                                ? undefined
                                                                : "font-mono"
                                                        }
                                                    >
                                                        {addressByAlertId[alert.id] ??
                                                            `${Number(alert.latitude).toFixed(5)}, ${Number(alert.longitude).toFixed(5)}`}
                                                    </span>
                                                </a>
                                                <Badge
                                                    variant="outline"
                                                    className={statusBadgeClass(alert.status)}
                                                >
                                                    {statusLabel(alert.status, alert.on_scene_at)}
                                                </Badge>
                                                {alert.resolved_at ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Resolved {formatDateTime(alert.resolved_at)}
                                                    </span>
                                                ) : (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="ml-auto h-7 gap-1.5 text-xs"
                                                        onClick={() =>
                                                            setResolving({
                                                                alert,
                                                                riderId: rider.id,
                                                                riderName: `${rider.first_name} ${rider.last_name}`,
                                                            })
                                                        }
                                                    >
                                                        <CheckCircle2 className="w-3 h-3" />
                                                        Mark Resolved
                                                    </Button>
                                                )}
                                                <ResponseSummary alert={alert} />
                                            </div>
                                        ))}
                                    </div>
                                </AccordionContent>
                            </AccordionItem>
                        );
                    })}
                </Accordion>
            )}

            {/* Pagination */}
            {riders.last_page > 1 && (
                <div className="p-4 border-t flex items-center justify-between text-sm text-muted-foreground">
                    <div>
                        Showing{" "}
                        <span className="font-medium text-foreground">
                            {(riders.current_page - 1) * riders.per_page + 1}
                        </span>{" "}
                        to{" "}
                        <span className="font-medium text-foreground">
                            {Math.min(
                                riders.current_page * riders.per_page,
                                riders.total,
                            )}
                        </span>{" "}
                        of{" "}
                        <span className="font-medium text-foreground">
                            {riders.total}
                        </span>{" "}
                        riders
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            disabled={riders.current_page === 1}
                            onClick={() =>
                                router.get(riders.links[0].url as string)
                            }
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>

                        <div className="flex items-center gap-1">
                            {riders.links.slice(1, -1).map((link, i) => {
                                if (link.label === "...") {
                                    return (
                                        <span key={i} className="px-2">
                                            ...
                                        </span>
                                    );
                                }

                                return (
                                    <Button
                                        key={i}
                                        variant={link.active ? "default" : "outline"}
                                        size="sm"
                                        className={`h-8 w-8 p-0 ${link.active ? "bg-primary text-primary-foreground" : ""}`}
                                        onClick={() =>
                                            link.url && router.get(link.url)
                                        }
                                    >
                                        {link.label}
                                    </Button>
                                );
                            })}
                        </div>

                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            disabled={riders.current_page === riders.last_page}
                            onClick={() =>
                                router.get(
                                    riders.links[riders.links.length - 1]
                                        .url as string,
                                )
                            }
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}

            <ResolveDialog
                open={resolving !== null}
                onOpenChange={(open) => {
                    if (!open) setResolving(null);
                }}
                riderName={resolving?.riderName ?? ""}
                onSubmit={handleResolve}
            />
        </Card>
    );
}

/** Responded by · Response time · Outcome for one alert in the history list. */
function ResponseSummary({ alert }: { alert: EmergencyAlert }) {
    if (!alert.dispatched_at && !alert.outcome_label) return null;

    const responder = [alert.responder_first_name, alert.responder_last_name]
        .filter(Boolean)
        .join(" ");
    const respondedBy = alert.agency_label
        ? responder
            ? `${alert.agency_label} – ${responder}`
            : alert.agency_label
        : null;
    const responseTime = minutesBetween(alert.triggered_at, alert.on_scene_at);

    return (
        <div className="basis-full grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-0.5 text-xs text-muted-foreground">
            <span>
                <span className="font-medium text-foreground">Responded by:</span>{" "}
                {respondedBy ?? "—"}
                {alert.dispatched_at && !alert.on_scene_at && ` (ETA ${etaLabel(alert.eta_minutes)})`}
            </span>
            <span>
                <span className="font-medium text-foreground">Response time:</span>{" "}
                {responseTime ?? "—"}
            </span>
            <span title={alert.resolution_note ?? undefined}>
                <span className="font-medium text-foreground">Outcome:</span>{" "}
                {alert.outcome_label ?? "—"}
                {alert.resolution_note && ` – ${alert.resolution_note}`}
            </span>
        </div>
    );
}
