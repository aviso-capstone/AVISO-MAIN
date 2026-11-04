import { useEffect, useRef, useState } from 'react';
import { useMap } from '@/components/ui/map';
import mapboxgl from 'mapbox-gl';
import { type EmergencyAlert } from './riderData';
import { type HazardLog } from '@/types/models';
import { createEmergencyMarkerElement } from './createMarkerElement';
import { EmergencyAlertPanel } from './EmergencyAlertPanel';
import { type DispatchFormData, type ResolveFormData } from '../sos/dispatchOptions';

interface EmergencyRidersProps {
    theme?: 'day' | 'night' | 'dusk' | 'dawn';
    hazards: HazardLog[];
    emergencies: EmergencyAlert[];
    onDispatch: (id: string, data: DispatchFormData) => Promise<void>;
    onOnScene: (id: string) => Promise<void>;
    onResolve: (id: string, data: ResolveFormData) => Promise<void>;
    /** Told whether a rider's SOS panel is open, so other overlays can make room. */
    onPanelOpenChange?: (open: boolean) => void;
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

export function EmergencyRiders({ theme = 'day', hazards, emergencies, onDispatch, onOnScene, onResolve, onPanelOpenChange }: EmergencyRidersProps) {
    const { map, isLoaded } = useMap();
    const markersRef = useRef<Record<string, mapboxgl.Marker>>({});
    // Status each marker was drawn with, so a dispatch redraws it orange.
    const markerStatusRef = useRef<Record<string, string | undefined>>({});
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

        // Move existing markers if coords changed (rider moved after SOS), and
        // drop any whose status changed so it is redrawn in its new colour.
        for (const emergency of emergencies) {
            if (!existingIds.has(emergency.id)) continue;
            if (markerStatusRef.current[emergency.id] !== emergency.status) {
                markersRef.current[emergency.id].remove();
                delete markersRef.current[emergency.id];
                existingIds.delete(emergency.id);
                continue;
            }
            markersRef.current[emergency.id].setLngLat(emergency.coords);
        }

        // Add new markers
        for (const emergency of emergencies) {
            if (!existingIds.has(emergency.id)) {
                const el = createEmergencyMarkerElement(() => {
                    setActivePanel(prev => prev === emergency.id ? null : emergency.id);
                }, emergency.status === 'acknowledged');

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
                markerStatusRef.current[emergency.id] = emergency.status;
            }
        }

        // Remove resolved markers
        for (const id of existingIds) {
            if (!currentIds.has(id)) {
                markersRef.current[id].remove();
                delete markersRef.current[id];
                delete markerStatusRef.current[id];
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

    const panelOpen = activeEmergency !== null;
    useEffect(() => {
        onPanelOpenChange?.(panelOpen);
    }, [panelOpen, onPanelOpenChange]);

    const handleResolve = async (id: string, data: ResolveFormData) => {
        await onResolve(id, data);
        setActivePanel(null);
    };

    return (
        <>
            {activeEmergency && (
                <EmergencyAlertPanel
                    emergency={activeEmergency}
                    theme={theme}
                    onClose={() => setActivePanel(null)}
                    onDispatch={data => onDispatch(activeEmergency.id, data)}
                    onOnScene={() => onOnScene(activeEmergency.id)}
                    onResolve={data => handleResolve(activeEmergency.id, data)}
                />
            )}
        </>
    );
}
