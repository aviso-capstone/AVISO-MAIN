import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { HAZARD_CHART_COLORS } from '@/lib/hazards';
import { type BarangayHazardCount } from '@/types/models';

// Hoisted so the row maps over it instead of repeating three near-identical blocks.
const SEGMENTS = [
    { key: 'potholes',       label: 'Potholes',        color: HAZARD_CHART_COLORS.potholes       },
    { key: 'roadBarriers',   label: 'Road Barriers',   color: HAZARD_CHART_COLORS.roadBarriers   },
    { key: 'roadExcavation', label: 'Road Excavation', color: HAZARD_CHART_COLORS.roadExcavation },
] as const;

interface BarangayHazardRowProps {
    rank: number;
    barangay: BarangayHazardCount;
    maxTotal: number;
}

function BarangayHazardRow({ rank, barangay, maxTotal }: BarangayHazardRowProps) {
    // Track length is relative to the leading barangay, so rank 1 always fills it.
    const trackWidth = maxTotal > 0 ? (barangay.total / maxTotal) * 100 : 0;

    return (
        <li className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="bg-muted text-muted-foreground flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                    {rank}
                </span>
                <span className="font-heading text-base font-semibold">{barangay.area}</span>
                <span className="text-muted-foreground ml-auto text-sm whitespace-nowrap">
                    {barangay.total} total
                </span>
            </div>

            {/* Decorative: every number it encodes is already written out below. */}
            <div className="bg-muted h-2.5 w-full overflow-hidden rounded-full" aria-hidden="true">
                <div className="flex h-full" style={{ width: `${trackWidth}%` }}>
                    {SEGMENTS.map((segment) => (
                        <div
                            key={segment.key}
                            style={{
                                width: `${barangay.total > 0 ? (barangay[segment.key] / barangay.total) * 100 : 0}%`,
                                backgroundColor: segment.color,
                            }}
                        />
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap gap-x-4 gap-y-1">
                {SEGMENTS.map((segment) => (
                    <span
                        key={segment.key}
                        className="text-muted-foreground flex items-center gap-1.5 text-xs"
                    >
                        <span
                            className="h-2 w-2 shrink-0 rounded-full"
                            style={{ backgroundColor: segment.color }}
                        />
                        {segment.label}
                        <span className="text-foreground font-semibold">
                            {barangay[segment.key]}
                        </span>
                    </span>
                ))}
            </div>
        </li>
    );
}

interface TopBarangayHazardsProps {
    data: BarangayHazardCount[];
}

export function TopBarangayHazards({ data }: TopBarangayHazardsProps) {
    // Derived during render — no state, no effect.
    const maxTotal = data.reduce((max, item) => (item.total > max ? item.total : max), 0);

    return (
        <Card className="border-border/50 mt-6 shadow-md">
            <CardHeader>
                <CardTitle>Hazard-Prone Barangays</CardTitle>
                <CardDescription>
                    Barangays with the most road hazards recorded, counting potholes, road
                    barriers and road excavations.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {data.length === 0 ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">
                        No road hazards have been recorded yet.
                    </p>
                ) : (
                    <ol className="flex flex-col gap-5">
                        {data.map((barangay, index) => (
                            <BarangayHazardRow
                                key={barangay.area}
                                rank={index + 1}
                                barangay={barangay}
                                maxTotal={maxTotal}
                            />
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}
