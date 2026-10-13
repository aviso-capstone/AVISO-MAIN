import { useState, useMemo, useEffect } from 'react';
import { useMap, MapMarker, MarkerContent, MarkerPopup } from '@/components/ui/map';
import { getHazardColor } from '@/lib/hazards';
import { type HazardLog } from '@/types/models';
import useSupercluster from 'use-supercluster';

// Pins only merge into a number bubble when they truly overlap on screen, and
// from zoom 16 (street level) up every hazard is drawn as its own pin.
const CLUSTER_RADIUS_PX = 40;
const CLUSTER_MAX_ZOOM = 15;
// ~10 m — far enough apart to click each stacked hazard once zoomed in.
const DUPLICATE_SPREAD_DEG = 0.00009;

// Fixed rows for the "Visible on screen" strip: always all three, zeros included,
// so it never changes size while the admin pans or zooms.
const VISIBLE_TYPES: { type: string; label: string }[] = [
    { type: 'Pothole', label: 'Potholes' },
    { type: 'Road Excavation', label: 'Excavations' },
    { type: 'Road Barrier', label: 'Barriers' },
];

interface HazardPinsProps {
    hazards?: HazardLog[];
    theme?: 'day' | 'night' | 'dusk' | 'dawn';
    /** Hide the "Visible on screen" strip, e.g. while an SOS panel needs the space. */
    hideStats?: boolean;
}

export function HazardPins({ hazards = [], theme = 'day', hideStats = false }: HazardPinsProps) {
    const { map } = useMap();
    const [bounds, setBounds] = useState<any>(null);
    const [zoom, setZoom] = useState(14);

    useEffect(() => {
        if (!map) return;
        
        const updateBounds = () => {
            const b = map.getBounds();
            if (b) {
                setBounds([b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]);
                setZoom(map.getZoom());
            }
        };

        // Only once the map stops moving: updating on every frame re-clustered and
        // re-rendered every pin ~60 times a second while zooming, which made the map
        // stutter. Pins still follow the map smoothly; grouping refreshes at the end.
        updateBounds();
        map.on('moveend', updateBounds);

        return () => {
            map.off('moveend', updateBounds);
        };
    }, [map]);

    const points = useMemo(() => {
        // Hazards reported at the exact same spot would sit on top of each other
        // and only one could ever be clicked. Spread each such stack around a
        // small ring (display only; the stored coordinates are untouched).
        const stacks = new Map<string, number>();
        const stackIndex = hazards.map((h) => {
            const key = `${Number(h.latitude).toFixed(6)},${Number(h.longitude).toFixed(6)}`;
            const index = stacks.get(key) ?? 0;
            stacks.set(key, index + 1);
            return { key, index };
        });

        return hazards.map((h, i) => {
            const { key, index } = stackIndex[i];
            const stackSize = stacks.get(key) ?? 1;
            const angle = (2 * Math.PI * index) / stackSize;
            const offset = stackSize > 1 ? DUPLICATE_SPREAD_DEG : 0;
            const lat = Number(h.latitude);
            const lng = Number(h.longitude);

            return {
                type: "Feature" as const,
                properties: {
                    cluster: false,
                    hazardId: h.id,
                    hazardType: h.type,
                    timestamp: h.detected_at,
                    confidence: h.confidence,
                    distance: h.distance,
                    riderId: h.rider_code,
                    area: h.area,
                    // Real position for the popup — the pin itself may be spread out.
                    lat,
                    lng,
                },
                geometry: {
                    type: "Point" as const,
                    coordinates: [lng + offset * Math.cos(angle), lat + offset * Math.sin(angle)],
                },
            };
        });
    }, [hazards]);

    const { clusters, supercluster } = useSupercluster({
        points,
        bounds,
        zoom,
        options: { radius: CLUSTER_RADIUS_PX, maxZoom: CLUSTER_MAX_ZOOM }
    });

    const viewportCounts = useMemo(() => {
        const counts: Record<string, number> = {};
        if (!bounds) return counts;
        const [w, s, e, n] = bounds;

        points.forEach(p => {
            const [lng, lat] = p.geometry.coordinates;
            if (lng >= w && lng <= e && lat >= s && lat <= n) {
                const type = p.properties.hazardType;
                counts[type] = (counts[type] || 0) + 1;
            }
        });

        return counts;
    }, [points, bounds]);

    return (
        <>
            {clusters.map(cluster => {
                const [longitude, latitude] = cluster.geometry.coordinates;
                const properties = cluster.properties as any;
                const { cluster: isCluster, point_count: pointCount, hazardId, hazardType, timestamp, confidence, distance, riderId, area, lat, lng } = properties;

                if (isCluster) {
                    return (
                        <MapMarker 
                            key={`cluster-${cluster.id}`} 
                            longitude={longitude} 
                            latitude={latitude}
                            onClick={() => {
                                if (supercluster && cluster.id) {
                                    const expansionZoom = Math.min(
                                        supercluster.getClusterExpansionZoom(cluster.id as number),
                                        CLUSTER_MAX_ZOOM + 1
                                    );
                                    map?.flyTo({ center: [longitude, latitude], zoom: expansionZoom });
                                }
                            }}
                        >
                            <div className="w-10 h-10 bg-primary/90 text-primary-foreground border-4 border-background rounded-full flex items-center justify-center font-bold text-sm shadow-xl shadow-primary/30 cursor-pointer hover:scale-110 transition-transform duration-200">
                                {pointCount}
                            </div>
                        </MapMarker>
                    );
                }

                // Render individual hazard marker
                return (
                    <MapMarker key={hazardId} longitude={longitude} latitude={latitude}>
                        <MarkerContent>
                            <div 
                                style={{ backgroundColor: getHazardColor(hazardType as any, theme) }} 
                                className="w-4 h-4 rounded-full border-[3px] border-white shadow-md hover:scale-125 transition-transform"
                                title={hazardType as string}
                            />
                        </MarkerContent>
                        <MarkerPopup closeButton className="w-64 z-50 rounded-xl overflow-hidden p-0">
                            <div className="p-4 text-sm bg-background">
                                <div className="flex items-center gap-2 mb-3 pb-2 border-b">
                                    <div className="w-3 h-3 rounded-full shadow-sm" style={{ backgroundColor: getHazardColor(hazardType as any, theme) }} />
                                    <span className="font-bold text-base">{hazardType}</span>
                                </div>
                                <div className="space-y-2 text-muted-foreground">
                                    <p className="flex justify-between"><strong className="text-foreground">Detected:</strong> {new Date(timestamp as string).toLocaleTimeString()}</p>
                                    <p className="flex justify-between"><strong className="text-foreground">Address/Area:</strong> {area}</p>
                                    <p className="flex justify-between"><strong className="text-foreground">Coordinates:</strong> <span className="font-mono text-[10px]">{Number(lat).toFixed(6)}, {Number(lng).toFixed(6)}</span></p>
                                    <p className="flex justify-between"><strong className="text-foreground">Confidence:</strong> <span className="text-green-600 font-semibold">{confidence}%</span></p>
                                    <p className="flex justify-between"><strong className="text-foreground">Distance:</strong> {distance} m</p>
                                    <p className="flex justify-between"><strong className="text-foreground">Rider ID:</strong> <span className="font-mono">{riderId}</span></p>
                                </div>
                            </div>
                        </MarkerPopup>
                    </MapMarker>
                );
            })}

            {/* Visible on screen: one steady line at the bottom centre */}
            {!hideStats && bounds && (
                <div className="pointer-events-none absolute bottom-8 left-1/2 z-10 flex max-w-[calc(100%-1.5rem)] -translate-x-1/2 items-center gap-3 whitespace-nowrap rounded-full border bg-background/95 px-4 py-1.5 shadow-lg backdrop-blur">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Visible on screen</span>
                    {VISIBLE_TYPES.map(({ type, label }) => (
                        <span key={type} className="flex items-center gap-1.5 text-xs font-medium">
                            <span className="h-2.5 w-2.5 rounded-full shadow-sm" style={{ backgroundColor: getHazardColor(type as any, theme) }} />
                            <span className="font-mono font-bold tabular-nums">{viewportCounts[type] ?? 0}</span>
                            {label}
                        </span>
                    ))}
                </div>
            )}
        </>
    );
}
