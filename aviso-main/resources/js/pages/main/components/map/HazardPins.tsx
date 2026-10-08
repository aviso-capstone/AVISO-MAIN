import { useState, useMemo, useEffect } from 'react';
import { useMap, MapMarker, MarkerContent, MarkerPopup } from '@/components/ui/map';
import { getHazardColor } from '@/lib/hazards';
import { type HazardLog } from '@/types/models';
import useSupercluster from 'use-supercluster';

interface HazardPinsProps {
    hazards?: HazardLog[];
    theme?: 'day' | 'night' | 'dusk' | 'dawn';
}

export function HazardPins({ hazards = [], theme = 'day' }: HazardPinsProps) {
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

        updateBounds();
        map.on('move', updateBounds);
        map.on('zoom', updateBounds);
        
        return () => {
            map.off('move', updateBounds);
            map.off('zoom', updateBounds);
        };
    }, [map]);

    const points = useMemo(() => {
        return hazards.map((h) => ({
            type: "Feature" as const,
            properties: {
                cluster: false,
                hazardId: h.id,
                hazardType: h.type,
                timestamp: h.detected_at,
                confidence: h.confidence,
                distance: h.distance,
                riderId: h.rider_code,
                area: h.area
            },
            geometry: {
                type: "Point" as const,
                coordinates: [Number(h.longitude), Number(h.latitude)]
            }
        }));
    }, [hazards]);

    const { clusters, supercluster } = useSupercluster({
        points,
        bounds,
        zoom,
        options: { radius: 75, maxZoom: 20 }
    });

    const viewportStats = useMemo(() => {
        if (!bounds) return [];
        const [w, s, e, n] = bounds;
        
        const counts: Record<string, number> = {};
        let total = 0;

        points.forEach(p => {
            const [lng, lat] = p.geometry.coordinates;
            if (lng >= w && lng <= e && lat >= s && lat <= n) {
                const type = p.properties.hazardType;
                counts[type] = (counts[type] || 0) + 1;
                total++;
            }
        });

        if (total === 0) return [];
        return Object.entries(counts).sort((a, b) => b[1] - a[1]);
    }, [points, bounds]);

    return (
        <>
            {clusters.map(cluster => {
                const [longitude, latitude] = cluster.geometry.coordinates;
                const properties = cluster.properties as any;
                const { cluster: isCluster, point_count: pointCount, hazardId, hazardType, timestamp, confidence, distance, riderId, area } = properties;

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
                                        20
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
                                    <p className="flex justify-between"><strong className="text-foreground">Coordinates:</strong> <span className="font-mono text-[10px]">{latitude.toFixed(6)}, {longitude.toFixed(6)}</span></p>
                                    <p className="flex justify-between"><strong className="text-foreground">Confidence:</strong> <span className="text-green-600 font-semibold">{confidence}%</span></p>
                                    <p className="flex justify-between"><strong className="text-foreground">Distance:</strong> {distance} m</p>
                                    <p className="flex justify-between"><strong className="text-foreground">Rider ID:</strong> <span className="font-mono">{riderId}</span></p>
                                </div>
                            </div>
                        </MarkerPopup>
                    </MapMarker>
                );
            })}

            {/* Viewport Statistics Panel */}
            {viewportStats.length > 0 && (
                <div className="absolute bottom-8 right-14 z-10 bg-background/95 backdrop-blur shadow-lg border rounded-xl p-4 w-60">
                    <h3 className="font-heading font-bold text-xs mb-3 text-muted-foreground uppercase tracking-wider">Visible on screen</h3>
                    <div className="space-y-2.5">
                        {viewportStats.map(([type, count]) => (
                            <div key={type} className="flex justify-between items-center">
                                <div className="flex items-center gap-2 text-sm font-medium">
                                    <div className="w-2.5 h-2.5 rounded-full shadow-sm" style={{ backgroundColor: getHazardColor(type as any, theme) }} />
                                    {type}
                                </div>
                                <span className="font-mono text-sm font-bold bg-muted/50 border px-2 py-0.5 rounded-md">{count}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}
