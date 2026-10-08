import { useEffect, useRef, useState } from 'react';
import { useMap } from '@/components/ui/map';
import mapboxgl from 'mapbox-gl';
import { type EmergencyAlert } from './riderData';
import { type HazardLog } from '@/types/models';
import { createEmergencyMarkerElement } from './createMarkerElement';
import { EmergencyAlertPanel } from './EmergencyAlertPanel';

interface EmergencyRidersProps {
    theme?: 'day' | 'night' | 'dusk' | 'dawn';
    hazards: HazardLog[];
    emergencies: EmergencyAlert[];
    onResolve: (id: string) => void;
}

// Inject SOS keyframes once into the document head
function ensureSosKeyframes() {
    if (document.getElementById('sos-pulse-style')) return;
    const style = document.createElement('style');
    style.id = 'sos-pulse-style';
    style.textContent = `
        @keyframes sos-ping-fast {
            0% { transform: scale(1); opacity: 0.3; }
            70%, 100% { transform: scale(2); opacity: 0; }
        }
        @keyframes sos-ping-slow {
            0% { transform: scale(1); opacity: 0.4; }
            70%, 100% { transform: scale(2.5); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
}

export function EmergencyRiders({ theme = 'day', hazards, emergencies, onResolve }: EmergencyRidersProps) {
    const { map, isLoaded } = useMap();
    const markersRef = useRef<Record<string, mapboxgl.Marker>>({});
    const prevIdsRef = useRef<Set<string>>(new Set());
    const [activePanel, setActivePanel] = useState<string | null>(null);

    // Keep activePanel in sync: if an emergency is resolved while panel is open, close it
    useEffect(() => {
        if (activePanel && !emergencies.find(e => e.id === activePanel)) {
            setActivePanel(null);
        }
    }, [emergencies, activePanel]);

    useEffect(() => {
        if (!isLoaded || !map) return;
        ensureSosKeyframes();

        const currentIds = new Set(emergencies.map(e => e.id));
        const existingIds = new Set(Object.keys(markersRef.current));

        // Move existing markers if coords changed (rider moved after SOS)
        for (const emergency of emergencies) {
            if (existingIds.has(emergency.id)) {
                markersRef.current[emergency.id].setLngLat(emergency.coords);
            }
        }

        // Add new markers
        for (const emergency of emergencies) {
            if (!existingIds.has(emergency.id)) {
                const el = createEmergencyMarkerElement(() => {
                    setActivePanel(prev => prev === emergency.id ? null : emergency.id);
                });

                const marker = new mapboxgl.Marker({
                    element: el,
                    anchor: 'center',
                    offset: [0, -44],
                    pitchAlignment: 'viewport',
                    rotationAlignment: 'viewport',
                })
                    .setLngLat(emergency.coords)
                    .addTo(map);

                markersRef.current[emergency.id] = marker;
            }
        }

        // Remove resolved markers
        for (const id of existingIds) {
            if (!currentIds.has(id)) {
                markersRef.current[id].remove();
                delete markersRef.current[id];
            }
        }

        // Fly to newly added emergency and auto-open its panel
        const newEmergency = emergencies.find(e => !prevIdsRef.current.has(e.id));
        if (newEmergency) {
            map.flyTo({ center: newEmergency.coords, zoom: 15, duration: 1500, essential: true });
            setActivePanel(newEmergency.id);
        }
        prevIdsRef.current = new Set(emergencies.map(e => e.id));
    }, [isLoaded, map, emergencies]);

    // Cleanup all markers on unmount
    useEffect(() => {
        return () => {
            Object.values(markersRef.current).forEach(m => m.remove());
            markersRef.current = {};
        };
    }, []);

    const activeEmergency = emergencies.find(e => e.id === activePanel) ?? null;

    const handleResolve = (id: string) => {
        setActivePanel(null);
        onResolve(id);
    };

    return (
        <>
            {activeEmergency && (
                <EmergencyAlertPanel
                    emergency={activeEmergency}
                    theme={theme}
                    onClose={() => setActivePanel(null)}
                    onResolve={() => handleResolve(activeEmergency.id)}
                />
            )}
        </>
    );
}
