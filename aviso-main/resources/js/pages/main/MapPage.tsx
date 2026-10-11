import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Card, CardContent } from '@/components/ui/card';
import AdminLayout from '@/layouts/AdminLayout';
import { Map, MapControls, MapMarker, MarkerContent, useMap } from '@/components/ui/map';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AlertCircle, Cone, Construction, ShieldAlert } from 'lucide-react';

// Subcomponents
import { SearchBox } from './components/map/SearchBox';
import { HazardPins } from './components/map/HazardPins';
import { BarangayBoundaries } from './components/map/BarangayBoundaries';
import { MapController } from './components/map/MapController';
import { EmergencyRiders } from './components/map/EmergencyRiders';
import { EmergencyAlertPanel } from './components/map/EmergencyAlertPanel';
import { EmergencyHotlinesPanel } from './components/map/EmergencyHotlinesPanel';
import { getHazardTailwindColors } from '@/lib/hazards';
import { toast } from '@/lib/toast';
import { useSosAlerts } from '@/components/sos/SosAlertProvider';
import { type HazardLog, type SosAlertPayload } from '@/types/models';
import { type EmergencyAlert, type LngLat } from './components/map/riderData';
import { findNearestHazard } from './components/map/riderUtils';

const STYLE_STANDARD = 'mapbox://styles/mapbox/standard';

const HAZARD_STATS: {
    types: string[];
    label: string;
    icon: React.ReactNode;
    description: string;
}[] = [
    { types: ['Pothole'],                                                                  label: 'Potholes',       icon: <AlertCircle className="w-5 h-5" />,  description: 'Surface defects'    },
    { types: ['Road Excavation'],                                                          label: 'Excavations',    icon: <Construction className="w-5 h-5" />, description: 'Active digging'     },
    { types: ['Road Barrier'],                                                             label: 'Road Barriers',  icon: <Cone className="w-5 h-5" />,         description: 'Blocked lanes'      },
];

interface MapPageProps {
    hazards: HazardLog[];
    focusAlert?: SosAlertPayload | null;
}

function toEmergencyAlert(data: SosAlertPayload, hazards: HazardLog[]): EmergencyAlert {
    const coords: LngLat = [Number(data.longitude), Number(data.latitude)];
    const riderName = data.rider_name ?? data.rider_code;

    const COLOR_BASES = ['blue', 'green', 'orange'] as const;
    const colorBase = COLOR_BASES[
        data.rider_code.split('').reduce((a: number, c: string) => a + c.charCodeAt(0), 0) % 3
    ];

    return {
        id: String(data.id),
        riderId: data.rider_code,
        riderName,
        colorBase,
        coords,
        userInfo: {
            fullName: riderName,
            username: data.username ?? data.rider_code,
            contact: data.contact ?? '—',
            address: data.address ?? '—',
        },
        triggeredAt: data.triggered_at,
        nearestHazard: findNearestHazard(coords, hazards),
        status: data.status,
    };
}

/** Flies the camera to a historical SOS alert's location once, on load.
 * Deliberately separate from EmergencyRiders' live-arrival flyTo — a
 * historical/resolved record should never be treated as a fresh live
 * emergency (it must not count toward the "active" banner or re-trigger
 * the alarm loop). */
function HistoricalAlertFlyTo({ coords }: { coords: LngLat }) {
    const { map, isLoaded } = useMap();
    const firedRef = useRef(false);

    useEffect(() => {
        if (!map || !isLoaded || firedRef.current) return;
        firedRef.current = true;
        map.flyTo({ center: coords, zoom: 15, duration: 1500, essential: true });
    }, [map, isLoaded, coords]);

    return null;
}

function RealTimeClock() {
    const [time, setTime] = useState(new Date());

    useEffect(() => {
        const timer = setInterval(() => setTime(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    return (
        <div className="font-heading flex items-baseline gap-2 border border-border/50 bg-background/50 backdrop-blur-sm px-3 py-1.5 rounded-xl shadow-sm">
            <span className="text-lg font-bold tracking-tight leading-none text-primary tabular-nums">{time.toLocaleTimeString()}</span>
            <span className="hidden sm:inline text-xs text-muted-foreground font-medium">
                {time.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
            </span>
        </div>
    );
}



export default function MapPage({ hazards, focusAlert }: MapPageProps) {
    // Map theme preset — respects localStorage preference set in Settings
    const [lightPreset, setLightPreset] = useState<'day' | 'night' | 'dusk' | 'dawn'>(
        () => (localStorage.getItem('aviso_map_theme') as 'day' | 'night' | 'dusk' | 'dawn') ?? 'day'
    );

    // Hazard filter state
    const [activeFilters, setActiveFilters] = useState<string[]>([]);
    useEffect(() => {
        const types = Array.from(new Set(hazards.map(h => h.type)));
        setActiveFilters(types);
    }, [hazards]);

    // Barangay outline overlay — on by default so admins can read areas at a glance
    const [showBarangays, setShowBarangays] = useState(true);

    const filteredHazards = useMemo(() => hazards.filter(h => activeFilters.includes(h.type)), [hazards, activeFilters]);
    const availableTypes = useMemo(() => Array.from(new Set(hazards.map(h => h.type))), [hazards]);

    // Live emergencies come from the global SOS provider, which owns the single
    // riders.live subscription and the alarm audio for the whole admin session.
    // This page only enriches each alert with its nearest hazard for display.
    const { alerts, resolveAlert } = useSosAlerts();
    const activeEmergencies = useMemo<EmergencyAlert[]>(
        () => alerts.map(a => toEmergencyAlert(a, hazards)),
        [alerts, hazards],
    );

    // A historical alert opened via an SOS Alerts "open on map" link
    // (?alert=id) — shown as a read-only record, entirely separate from the
    // live tracking state above.
    const [historicalAlert, setHistoricalAlert] = useState<EmergencyAlert | null>(
        () => (focusAlert ? toEmergencyAlert(focusAlert, hazards) : null),
    );
    const [historicalDismissed, setHistoricalDismissed] = useState(false);

    // The Emergency Hotlines popup is a separate overlay from the rider SOS
    // panel. It rides on the same "an emergency is happening" signal and
    // re-surfaces whenever a new SOS arrives, even if previously dismissed.
    const [hotlinesDismissed, setHotlinesDismissed] = useState(false);
    const prevActiveCountRef = useRef(0);
    useEffect(() => {
        if (activeEmergencies.length > prevActiveCountRef.current) {
            setHotlinesDismissed(false);
        }
        prevActiveCountRef.current = activeEmergencies.length;
    }, [activeEmergencies.length]);

    // A focused alert opened via ?alert=id that is ALSO a currently-unresolved
    // (seeded) emergency should render only as the live active emergency, not
    // additionally as a gray historical marker — otherwise the same alert shows
    // up twice on the map.
    const showHistorical =
        !!historicalAlert &&
        !historicalDismissed &&
        !activeEmergencies.some(e => e.id === historicalAlert.id);

    // Only a LIVE emergency may raise the hotlines popup. A resolved record
    // opened from history is a read-only record, and treating it as an unfolding
    // emergency is what made a resolved alert look like it had come back.
    const showHotlines = activeEmergencies.length > 0 && !hotlinesDismissed;

    const handleResolveHistorical = async () => {
        if (!historicalAlert) return;
        try {
            await axios.put(route('sos-alerts.resolve', historicalAlert.id));
            setHistoricalAlert(prev => (prev ? { ...prev, status: 'resolved' } : prev));

            // Strip ?alert=<id> from the address bar. The backend serves that
            // alert by id regardless of status, so leaving the param in place
            // meant a refresh re-opened the record we just resolved.
            window.history.replaceState({}, '', window.location.pathname);

            toast.success({ title: 'Alert marked as resolved' });
        } catch {
            toast.error({
                title: 'Failed to resolve alert',
                description: 'Please try again.',
            });
        }
    };



    return (
        <>
            <Head title="Live Map" />

            {/* Full-height column: header rows keep their size, the map takes the rest. */}
            <div className="flex h-full flex-col gap-3">
            <div className="flex shrink-0 flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 items-baseline gap-3">
                    <h1 className="text-2xl font-heading font-bold tracking-tight">Live Map Tracker</h1>
                    <p className="hidden truncate text-sm text-muted-foreground lg:block">
                        Monitor real-time road hazards and system edge devices.
                    </p>
                </div>
                <RealTimeClock />
            </div>

            {/* ── SOS status + hazard counts, one compact row ───────── */}
            <div className="grid shrink-0 grid-cols-2 gap-3 lg:grid-cols-4">
                <Card className={`overflow-hidden border py-0 shadow-sm transition-colors duration-500 ${
                    activeEmergencies.length > 0
                        ? 'border-red-500/60 bg-red-50/60 dark:bg-red-950/25'
                        : 'border-border/50'
                }`}>
                    <CardContent className="flex items-center gap-3 p-3">
                        <div className={`relative shrink-0 rounded-lg p-2 ${
                            activeEmergencies.length > 0
                                ? 'bg-red-100 dark:bg-red-900/40'
                                : 'border bg-background shadow-sm'
                        }`}>
                            {activeEmergencies.length > 0 && (
                                <span className="absolute inset-0 animate-ping rounded-lg bg-red-400 opacity-25" />
                            )}
                            <ShieldAlert className={`h-5 w-5 ${
                                activeEmergencies.length > 0 ? 'text-red-600' : 'text-muted-foreground'
                            }`} />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-1.5">
                                <p className="text-sm font-semibold leading-none">SOS Alerts</p>
                                <span className={`h-1.5 w-1.5 rounded-full ${
                                    activeEmergencies.length > 0 ? 'animate-pulse bg-red-500' : 'bg-green-500'
                                }`} />
                                <span className="text-[10px] font-medium text-muted-foreground">LIVE</span>
                            </div>
                            <p className={`mt-1 truncate text-xs ${
                                activeEmergencies.length > 0
                                    ? 'font-medium text-red-600 dark:text-red-400'
                                    : 'text-muted-foreground'
                            }`}>
                                {activeEmergencies.length === 0
                                    ? 'All riders safe'
                                    : activeEmergencies.map(e => e.riderName).join(', ') + ' — needs help'
                                }
                            </p>
                        </div>
                        <div className={`shrink-0 font-heading text-2xl font-bold ${
                            activeEmergencies.length > 0 ? 'text-red-600' : 'text-muted-foreground'
                        }`}>
                            {activeEmergencies.length}
                        </div>
                    </CardContent>
                </Card>

                {HAZARD_STATS.map((stat) => (
                    <Card key={stat.label} className="overflow-hidden border-border/50 py-0 shadow-sm" title={stat.description}>
                        <CardContent className="flex items-center gap-3 p-3">
                            <div className="shrink-0 rounded-lg border bg-background p-2 shadow-sm">
                                <div className={getHazardTailwindColors(stat.types[0]).split(' ')[0]}>
                                    {stat.icon}
                                </div>
                            </div>
                            <p className="min-w-0 flex-1 truncate text-sm font-medium">{stat.label}</p>
                            <div className="shrink-0 font-heading text-2xl font-bold">
                                {hazards.filter(h => stat.types.includes(h.type)).length}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {/* ── Map card ──────────────────────────────────────────── */}
            <Card className="min-h-[360px] flex-1 flex flex-col overflow-hidden border-border/50 py-0 shadow-md">
                <div className="w-full h-full relative">
                    <Map
                        styles={{ light: STYLE_STANDARD, dark: STYLE_STANDARD }}
                        className="w-full h-full relative"
                    >
                        <MapController
                            hazards={filteredHazards}
                            activeFilters={activeFilters}
                            setActiveFilters={setActiveFilters}
                            availableTypes={availableTypes}
                            lightPreset={lightPreset}
                            setLightPreset={setLightPreset}
                            showBarangays={showBarangays}
                            setShowBarangays={setShowBarangays}
                        />
                        <BarangayBoundaries visible={showBarangays} hazards={hazards} theme={lightPreset} />
                        <HazardPins hazards={filteredHazards} theme={lightPreset} />
                        <EmergencyRiders
                            theme={lightPreset}
                            hazards={filteredHazards}
                            emergencies={activeEmergencies}
                            onResolve={resolveAlert}
                        />
                        {showHistorical && historicalAlert && (
                            <>
                                <HistoricalAlertFlyTo coords={historicalAlert.coords} />
                                <MapMarker
                                    longitude={historicalAlert.coords[0]}
                                    latitude={historicalAlert.coords[1]}
                                >
                                    <MarkerContent>
                                        <div className="w-4 h-4 rounded-full border-[3px] border-white shadow-md bg-muted-foreground" />
                                    </MarkerContent>
                                </MapMarker>
                                <EmergencyAlertPanel
                                    emergency={historicalAlert}
                                    theme={lightPreset}
                                    isHistorical
                                    onClose={() => setHistoricalDismissed(true)}
                                    onResolve={handleResolveHistorical}
                                />
                            </>
                        )}
                        {showHotlines && (
                            <EmergencyHotlinesPanel onClose={() => setHotlinesDismissed(true)} />
                        )}
                        <SearchBox />
                        <MapControls position="top-right" showZoom showCompass />
                    </Map>
                </div>
            </Card>
            </div>
        </>
    );
}

// Persistent layout: Inertia keeps AdminLayout mounted across navigation only
// when it is assigned here, which is what lets the global SOS subscription and
// alarm survive a page change.
MapPage.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
