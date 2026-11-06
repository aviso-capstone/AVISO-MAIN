import { useState } from "react";
import { LIMITS } from '@/lib/sanitize';
import { router } from "@inertiajs/react";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { ChevronLeft, ChevronRight, Search } from "lucide-react";
import { type Barangay, type PaginatedData, type RiderEventLog } from "@/types/models";
import { EVENT_TYPE_STYLES } from "./eventTypes";

export interface CrashLogFilters {
    type?: string;
    search?: string;
    barangay?: string;
    from?: string;
    to?: string;
}

interface CrashLogTableProps {
    events: PaginatedData<RiderEventLog>;
    filters: CrashLogFilters;
    types: { value: string; label: string }[];
    barangays: Barangay[];
}

const num = (v: string | null, digits: number, unit: string) =>
    v === null ? "-" : `${Number(v).toFixed(digits)}${unit}`;

export function CrashLogTable({ events, filters, types, barangays }: CrashLogTableProps) {
    const [search, setSearch] = useState(filters.search || "");

    const apply = (changes: Partial<CrashLogFilters>) => {
        router.get(
            route("crash-logs.index"),
            { ...filters, search, ...changes },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <Card className="border-border/50 shadow-sm">
            {/* Filters */}
            <div className="p-4 border-b flex flex-col lg:flex-row gap-3 lg:items-center bg-muted/20">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply({});
                    }}
                    className="relative w-full lg:w-64"
                >
                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder="Search rider..."
                        maxLength={LIMITS.search}
                        className="pl-9 bg-background"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </form>

                <div className="flex flex-wrap gap-3 flex-1 lg:justify-end">
                    <Select value={filters.type || "all"} onValueChange={(v) => apply({ type: v })}>
                        <SelectTrigger className="w-[160px] bg-background">
                            <SelectValue placeholder="All categories" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All categories</SelectItem>
                            {types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.barangay || "all"} onValueChange={(v) => apply({ barangay: v })}>
                        <SelectTrigger className="w-[190px] bg-background">
                            <SelectValue placeholder="All Barangays" />
                        </SelectTrigger>
                        <SelectContent className="max-h-72">
                            <SelectItem value="all">All Barangays</SelectItem>
                            {barangays.map((b) => (
                                <SelectItem key={b.code} value={b.code}>
                                    {b.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Input
                        type="date"
                        aria-label="From date"
                        className="w-[150px] bg-background"
                        value={filters.from || ""}
                        onChange={(e) => apply({ from: e.target.value || undefined })}
                    />
                    <Input
                        type="date"
                        aria-label="To date"
                        className="w-[150px] bg-background"
                        value={filters.to || ""}
                        onChange={(e) => apply({ to: e.target.value || undefined })}
                    />
                </div>
            </div>

            {/* Table */}
            <div className="relative w-full overflow-auto">
                <Table>
                    <TableHeader>
                        <TableRow className="bg-muted/30">
                            <TableHead className="font-semibold">Category</TableHead>
                            <TableHead className="font-semibold">Peak G</TableHead>
                            <TableHead className="font-semibold">Vertical</TableHead>
                            <TableHead className="font-semibold">Horizontal</TableHead>
                            <TableHead className="font-semibold">Gyro</TableHead>
                            <TableHead className="font-semibold">Tilt</TableHead>
                            <TableHead className="font-semibold">Area</TableHead>
                            <TableHead className="font-semibold">Rider</TableHead>
                            <TableHead className="font-semibold">Detected At</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.data.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={9} className="h-32 text-center text-muted-foreground">
                                    No crash-detection records match your filters.
                                </TableCell>
                            </TableRow>
                        ) : (
                            events.data.map((e) => {
                                const style = EVENT_TYPE_STYLES[e.event_type];
                                const Icon = style.icon;
                                return (
                                    <TableRow key={e.id} className="hover:bg-muted/20">
                                        <TableCell>
                                            <Badge variant="outline" className={`gap-1.5 py-1 pr-3 pl-2 font-normal ${style.badge}`}>
                                                <Icon className="h-4 w-4" />
                                                {style.label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="font-mono text-sm">{num(e.acceleration_peak, 2, " g")}</TableCell>
                                        <TableCell className="font-mono text-sm text-muted-foreground">{num(e.vertical_g, 2, " g")}</TableCell>
                                        <TableCell className="font-mono text-sm text-muted-foreground">{num(e.horizontal_g, 2, " g")}</TableCell>
                                        <TableCell className="font-mono text-sm text-muted-foreground">{num(e.gyro_peak_dps, 0, "°/s")}</TableCell>
                                        <TableCell className="font-mono text-sm text-muted-foreground">{num(e.tilt_deg, 0, "°")}</TableCell>
                                        <TableCell className="text-sm">{e.area ?? "-"}</TableCell>
                                        <TableCell className="text-sm">{e.rider_code}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                            {new Date(e.detected_at).toLocaleString([], {
                                                month: "short",
                                                day: "numeric",
                                                hour: "2-digit",
                                                minute: "2-digit",
                                                second: "2-digit",
                                            })}
                                        </TableCell>
                                    </TableRow>
                                );
                            })
                        )}
                    </TableBody>
                </Table>
            </div>

            {/* Pagination */}
            {events.last_page > 1 && (
                <div className="p-4 border-t flex items-center justify-between text-sm text-muted-foreground">
                    <div>
                        Page <span className="font-medium text-foreground">{events.current_page}</span> of{" "}
                        <span className="font-medium text-foreground">{events.last_page}</span> ·{" "}
                        <span className="font-medium text-foreground">{events.total}</span> records
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            aria-label="Previous page"
                            disabled={events.current_page === 1}
                            onClick={() => events.links[0].url && router.get(events.links[0].url)}
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            aria-label="Next page"
                            disabled={events.current_page === events.last_page}
                            onClick={() => {
                                const next = events.links[events.links.length - 1].url;
                                if (next) router.get(next);
                            }}
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}
