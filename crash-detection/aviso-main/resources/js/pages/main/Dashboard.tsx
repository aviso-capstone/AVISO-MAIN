import { type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout';
import { HAZARD_CHART_COLORS } from '@/lib/hazards';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    ChartLegend,
    ChartLegendContent,
} from '@/components/ui/chart';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, XAxis, YAxis } from 'recharts';
import { ChartConfig } from '@/components/ui/chart';
import { TopBarangayHazards } from './components/dashboard/TopBarangayHazards';
import { type BarangayHazardCount } from '@/types/models';

const hazardsChartConfig = {
    potholes:           { label: 'Potholes',               color: HAZARD_CHART_COLORS.potholes           },
    roadExcavation:     { label: 'Road Excavation',        color: HAZARD_CHART_COLORS.roadExcavation     },
    roadBarriers:       { label: 'Road Barriers',          color: HAZARD_CHART_COLORS.roadBarriers       },
} satisfies ChartConfig;

const hazardTypesChartConfig = hazardsChartConfig;

const HAZARD_TYPE_COLORS = Object.values(HAZARD_CHART_COLORS);

const detectionAccuracyChartConfig = {
    accuracy: { label: 'Accuracy %', color: 'var(--primary)' },
} satisfies ChartConfig;

interface DashboardStat {
    label: string;
    value: string;
    sub: string;
    subVariant: 'destructive' | 'muted';
}

interface HazardTypeDatum {
    name: string;
    value: number;
}

interface AccuracyDatum {
    name: string;
    accuracy: number;
}

interface HazardsOverTimeDatum {
    day: string;
    potholes: number;
    roadExcavation: number;
    roadBarriers: number;
}

interface DashboardProps {
    dashboardStats: DashboardStat[];
    hazardTypesData: HazardTypeDatum[];
    detectionAccuracyData: AccuracyDatum[];
    hazardsOverTimeData: HazardsOverTimeDatum[];
    topBarangayHazards: BarangayHazardCount[];
}

export default function Dashboard({
    dashboardStats,
    hazardTypesData,
    detectionAccuracyData,
    hazardsOverTimeData,
    topBarangayHazards,
}: DashboardProps) {
    return (
        <>
            <Head title="Metrics" />

            <div className="mb-6">
                <h1 className="text-3xl font-heading font-bold tracking-tight">Metrics</h1>
                <p className="text-muted-foreground mt-1">
                    Analyze system performance and historical hazard trends.
                </p>
            </div>

            <>
                {/* Stat Cards */}
                <div className="grid gap-6 md:grid-cols-3 mb-6">
                    {dashboardStats.map((stat) => (
                        <Card key={stat.label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    {stat.label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className={`text-4xl font-heading font-bold ${stat.label === 'System Status' ? 'text-green-600 dark:text-green-500' : ''}`}>
                                    {stat.value}
                                </div>
                                <p className={`text-xs mt-1 ${stat.subVariant === 'destructive' ? 'text-destructive' : 'text-muted-foreground'}`}>
                                    {stat.sub}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Charts Row */}
                <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    {/* Area Chart — Hazards Over Time */}
                    <Card className="col-span-1 lg:col-span-2 border-border/50 shadow-md">
                        <CardHeader>
                            <CardTitle>Hazards Detected Over Time</CardTitle>
                            <CardDescription>7-day trend across the three road hazard types.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ChartContainer config={hazardsChartConfig} className="h-[300px] w-full">
                                <AreaChart data={hazardsOverTimeData} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
                                    <defs>
                                        <linearGradient id="colorPotholes" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%"  stopColor="var(--color-potholes)"          stopOpacity={0.3} />
                                            <stop offset="95%" stopColor="var(--color-potholes)"          stopOpacity={0} />
                                        </linearGradient>
                                        <linearGradient id="colorRoadExcavation" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%"  stopColor="var(--color-roadExcavation)"    stopOpacity={0.3} />
                                            <stop offset="95%" stopColor="var(--color-roadExcavation)"    stopOpacity={0} />
                                        </linearGradient>
                                        <linearGradient id="colorRoadBarriers" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%"  stopColor="var(--color-roadBarriers)"      stopOpacity={0.3} />
                                            <stop offset="95%" stopColor="var(--color-roadBarriers)"      stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid vertical={false} strokeDasharray="3 3" />
                                    <XAxis dataKey="day" tickLine={false} axisLine={false} tickMargin={8} />
                                    <YAxis tickLine={false} axisLine={false} />
                                    <ChartTooltip content={<ChartTooltipContent />} />
                                    <ChartLegend content={<ChartLegendContent />} />
                                    <Area type="monotone" dataKey="potholes"           stroke="var(--color-potholes)"           fill="url(#colorPotholes)"          strokeWidth={2} dot={false} />
                                    <Area type="monotone" dataKey="roadExcavation"     stroke="var(--color-roadExcavation)"     fill="url(#colorRoadExcavation)"    strokeWidth={2} dot={false} />
                                    <Area type="monotone" dataKey="roadBarriers"       stroke="var(--color-roadBarriers)"       fill="url(#colorRoadBarriers)"      strokeWidth={2} dot={false} />
                                </AreaChart>
                            </ChartContainer>
                        </CardContent>
                    </Card>

                    {/* Right Column */}
                    <div className="flex flex-col gap-6 col-span-1">
                        {/* Donut — Hazard Types */}
                        <Card className="flex-1 border-border/50 shadow-md">
                            <CardHeader>
                                <CardTitle>Hazard Types</CardTitle>
                                <CardDescription>Distribution by category.</CardDescription>
                            </CardHeader>
                            <CardContent className="flex items-center justify-center">
                                <ChartContainer config={hazardTypesChartConfig} className="h-[180px] w-full">
                                    <PieChart>
                                        <ChartTooltip content={<ChartTooltipContent hideLabel />} />
                                        <Pie
                                            data={hazardTypesData}
                                            dataKey="value"
                                            nameKey="name"
                                            cx="50%" cy="50%"
                                            innerRadius={50}
                                            outerRadius={78}
                                            paddingAngle={3}
                                        >
                                            {hazardTypesData.map((_, i) => (
                                                <Cell key={i} fill={HAZARD_TYPE_COLORS[i % HAZARD_TYPE_COLORS.length]} />
                                            ))}
                                        </Pie>
                                    </PieChart>
                                </ChartContainer>
                            </CardContent>
                        </Card>

                        {/* Bar — Detection Accuracy */}
                        <Card className="flex-1 border-border/50 shadow-md">
                            <CardHeader>
                                <CardTitle>Detection Accuracy</CardTitle>
                                <CardDescription>Model confidence per hazard class (%).</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ChartContainer config={detectionAccuracyChartConfig} className="h-[130px] w-full">
                                    <BarChart data={detectionAccuracyData} margin={{ top: 4, right: 4, bottom: 0, left: -28 }}>
                                        <CartesianGrid vertical={false} strokeDasharray="3 3" />
                                        <XAxis dataKey="name" tickLine={false} axisLine={false} tickMargin={6} tick={{ fontSize: 10 }} />
                                        <YAxis tickLine={false} axisLine={false} domain={[80, 100]} />
                                        <ChartTooltip content={<ChartTooltipContent />} />
                                        <Bar dataKey="accuracy" fill="var(--color-accuracy)" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ChartContainer>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* Ranked Barangays */}
                <TopBarangayHazards data={topBarangayHazards} />
            </>
        </>
    );
}

// Persistent layout: Inertia keeps AdminLayout mounted across navigation only
// when it is assigned here, which is what lets the global SOS subscription and
// alarm survive a page change.
Dashboard.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
