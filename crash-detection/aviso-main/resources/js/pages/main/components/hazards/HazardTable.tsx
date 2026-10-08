import { useState } from "react";
import { router } from "@inertiajs/react";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { Progress } from "@/components/ui/progress";
import {
    Search,
    AlertCircle,
    Cone,
    Construction,
    ChevronLeft,
    ChevronRight,
} from "lucide-react";
import { type Barangay, type HazardLog, type PaginatedData } from "@/types/models";
import { getHazardTailwindColors } from "@/lib/hazards";

interface HazardTableProps {
    hazards: PaginatedData<HazardLog>;
    filters: {
        search?: string;
        type?: string;
        barangay?: string;
        status?: string;
    };
    types: string[];
    barangays: Barangay[];
}

export function HazardTable({
    hazards,
    filters,
    types,
    barangays,
}: HazardTableProps) {
    const [searchQuery, setSearchQuery] = useState(filters.search || "");

    const handleFilterChange = (
        key: "type" | "barangay" | "status",
        value: string,
    ) => {
        router.get(
            route("hazards.index"),
            {
                ...filters,
                search: searchQuery,
                [key]: value,
            },
            {
                preserveState: true,
                preserveScroll: true,
            },
        );
    };

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            route("hazards.index"),
            {
                ...filters,
                search: searchQuery,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const getIcon = (type: string) => {
        switch (type) {
            case "Pothole":
                return <AlertCircle className="w-4 h-4" />;
            case "Road Excavation":
                return <Construction className="w-4 h-4" />;
            case "Road Barrier":
                return <Cone className="w-4 h-4" />;
            default:
                return <AlertCircle className="w-4 h-4" />;
        }
    };

    return (
        <Card className="border-border/50 shadow-sm">
            {/* Filters */}
            <div className="p-4 border-b flex flex-col sm:flex-row gap-4 items-center bg-muted/20">
                <form
                    onSubmit={submitSearch}
                    className="relative w-full sm:w-72"
                >
                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        placeholder="Search code or rider..."
                        className="pl-9 bg-background"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                    />
                </form>

                <div className="flex w-full sm:w-auto gap-3 flex-1 sm:justify-end">
                    <Select
                        value={filters.type || "all"}
                        onValueChange={(v) => handleFilterChange("type", v)}
                    >
                        <SelectTrigger className="w-[140px] bg-background">
                            <SelectValue placeholder="All Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            {types.map((t) => (
                                <SelectItem key={t} value={t}>
                                    {t}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.barangay || "all"}
                        onValueChange={(v) => handleFilterChange("barangay", v)}
                    >
                        <SelectTrigger className="w-[200px] bg-background">
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


                </div>
            </div>

            {/* Table */}
            <div className="relative w-full overflow-auto">
                <Table>
                    <TableHeader>
                        <TableRow className="bg-muted/30">
                            <TableHead className="w-[100px] font-semibold">
                                Code
                            </TableHead>
                            <TableHead className="font-semibold">
                                Classification
                            </TableHead>
                            <TableHead className="font-semibold">
                                Area
                            </TableHead>
                            <TableHead className="font-semibold">
                                Confidence
                            </TableHead>
                            <TableHead className="font-semibold">
                                Distance
                            </TableHead>
                            <TableHead className="font-semibold">
                                Coordinates
                            </TableHead>
                            <TableHead className="font-semibold">
                                Rider
                            </TableHead>
                            <TableHead className="font-semibold">
                                Detected At
                            </TableHead>

                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {hazards.data.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={8}
                                    className="h-32 text-center text-muted-foreground"
                                >
                                    No hazards found matching your filters.
                                </TableCell>
                            </TableRow>
                        ) : (
                            hazards.data.map((hazard) => (
                                <TableRow
                                    key={hazard.id}
                                    className="hover:bg-muted/20"
                                >
                                    <TableCell className="font-mono text-xs">
                                        {hazard.haz_code}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant="outline"
                                            className={`gap-1.5 py-1 pr-3 pl-2 font-normal ${getHazardTailwindColors(hazard.type)}`}
                                        >
                                            {getIcon(hazard.type)}
                                            {hazard.type}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {hazard.area}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Progress
                                                value={hazard.confidence}
                                                className="w-16 h-2"
                                            />
                                            <span className="text-xs font-mono text-muted-foreground">
                                                {hazard.confidence}%
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm font-mono text-muted-foreground">
                                        {hazard.distance != null ? `${hazard.distance}m` : '-'}
                                    </TableCell>
                                    <TableCell className="text-xs font-mono text-muted-foreground whitespace-nowrap">
                                        {Number(hazard.latitude).toFixed(5)}, {Number(hazard.longitude).toFixed(5)}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {hazard.rider_code}
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                        {new Date(
                                            hazard.detected_at,
                                        ).toLocaleString([], {
                                            month: "short",
                                            day: "numeric",
                                            hour: "2-digit",
                                            minute: "2-digit",
                                        })}
                                    </TableCell>

                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            {/* Pagination */}
            {hazards.last_page > 1 && (
                <div className="p-4 border-t flex items-center justify-between text-sm text-muted-foreground">
                    <div>
                        Showing{" "}
                        <span className="font-medium text-foreground">
                            {(hazards.current_page - 1) * hazards.per_page + 1}
                        </span>{" "}
                        to{" "}
                        <span className="font-medium text-foreground">
                            {Math.min(
                                hazards.current_page * hazards.per_page,
                                hazards.total,
                            )}
                        </span>{" "}
                        of{" "}
                        <span className="font-medium text-foreground">
                            {hazards.total}
                        </span>{" "}
                        entries
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            disabled={hazards.current_page === 1}
                            onClick={() =>
                                router.get(hazards.links[0].url as string)
                            }
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>

                        <div className="flex items-center gap-1">
                            {hazards.links.slice(1, -1).map((link, i) => {
                                // Handle "..." ellipsis
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
                                        variant={
                                            link.active ? "default" : "outline"
                                        }
                                        size="sm"
                                        className={`h-8 w-8 p-0 ${link.active ? "bg-primary text-primary-foreground" : ""}`}
                                        onClick={() =>
                                            link.url && router.get(link.url)
                                        }
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                );
                            })}
                        </div>

                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            disabled={
                                hazards.current_page === hazards.last_page
                            }
                            onClick={() =>
                                router.get(
                                    hazards.links[hazards.links.length - 1]
                                        .url as string,
                                )
                            }
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </Card>
    );
}
