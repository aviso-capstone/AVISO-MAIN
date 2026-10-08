import { useEffect, useMemo, useRef, useState } from "react";
import mapboxgl from "mapbox-gl";
import type { FeatureCollection, MultiPolygon, Polygon } from "geojson";
import { useMap } from "@/components/ui/map";
import { type HazardLog } from "@/types/models";

// Simplified PSA/NAMRIA barangay outlines (PSGC 2023) — the same codes the
// server's BarangayLocatorService resolves hazards and SOS alerts to.
const BOUNDARIES_URL = "/geo/zamboanga-city-barangays.json";

const SOURCE_ID = "barangay-boundaries";
const FILL_LAYER = "barangay-boundaries-fill";
const LINE_LAYER = "barangay-boundaries-line";
const LABEL_LAYER = "barangay-boundaries-label";
const LAYER_IDS = [FILL_LAYER, LINE_LAYER, LABEL_LAYER];

type BarangayProperties = { psgc_code: string; name: string };
type BarangayCollection = FeatureCollection<Polygon | MultiPolygon, BarangayProperties>;

interface BarangayBoundariesProps {
    visible: boolean;
    hazards: HazardLog[];
    theme: "day" | "night" | "dusk" | "dawn";
}

export function BarangayBoundaries({ visible, hazards, theme }: BarangayBoundariesProps) {
    const { map, isLoaded } = useMap();
    const [boundaries, setBoundaries] = useState<BarangayCollection | null>(null);
    const popupRef = useRef<mapboxgl.Popup | null>(null);

    const isDark = theme === "night" || theme === "dusk";

    // Active hazards per barangay, for the click popup.
    const hazardCounts = useMemo(() => {
        const counts = new Map<string, number>();
        for (const h of hazards) {
            if (h.barangay_code) counts.set(h.barangay_code, (counts.get(h.barangay_code) ?? 0) + 1);
        }
        return counts;
    }, [hazards]);
    const hazardCountsRef = useRef(hazardCounts);
    hazardCountsRef.current = hazardCounts;

    useEffect(() => {
        let cancelled = false;
        fetch(BOUNDARIES_URL)
            .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
            .then((data: BarangayCollection) => {
                if (!cancelled) setBoundaries(data);
            })
            .catch((err) => console.warn("[BarangayBoundaries] could not load boundaries", err));
        return () => {
            cancelled = true;
        };
    }, []);

    // Add source + layers. Re-runs after a style change (isLoaded flips), the
    // same way the other map layers re-attach themselves.
    useEffect(() => {
        if (!map || !isLoaded || !boundaries) return;

        if (!map.getSource(SOURCE_ID)) {
            map.addSource(SOURCE_ID, { type: "geojson", data: boundaries, promoteId: "psgc_code" });
        }

        if (!map.getLayer(FILL_LAYER)) {
            map.addLayer({
                id: FILL_LAYER,
                type: "fill",
                source: SOURCE_ID,
                slot: "middle",
                paint: {
                    "fill-color": "#64748b",
                    "fill-opacity": ["case", ["boolean", ["feature-state", "hover"], false], 0.3, 0.12],
                },
            });
        }

        if (!map.getLayer(LINE_LAYER)) {
            map.addLayer({
                id: LINE_LAYER,
                type: "line",
                source: SOURCE_ID,
                slot: "middle",
                layout: { "line-join": "round" },
                paint: {
                    "line-color": "#334155",
                    "line-width": ["interpolate", ["linear"], ["zoom"], 10, 0.8, 15, 2],
                    "line-opacity": 0.85,
                },
            });
        }

        if (!map.getLayer(LABEL_LAYER)) {
            map.addLayer({
                id: LABEL_LAYER,
                type: "symbol",
                source: SOURCE_ID,
                minzoom: 12.5,
                layout: {
                    "text-field": ["get", "name"],
                    "text-size": ["interpolate", ["linear"], ["zoom"], 12.5, 11, 16, 14],
                    "text-font": ["DIN Pro Medium", "Arial Unicode MS Regular"],
                    "symbol-placement": "point",
                    "text-max-width": 8,
                },
                paint: {
                    "text-color": "#1e293b",
                    "text-halo-color": "#ffffff",
                    "text-halo-width": 1.5,
                },
            });
        }

        let hoveredCode: string | null = null;
        const clearHover = () => {
            if (hoveredCode !== null && map.getSource(SOURCE_ID)) {
                map.setFeatureState({ source: SOURCE_ID, id: hoveredCode }, { hover: false });
            }
            hoveredCode = null;
        };

        const onMove = (e: mapboxgl.MapMouseEvent) => {
            const code = e.features?.[0]?.properties?.psgc_code as string | undefined;
            if (!code || code === hoveredCode) return;
            clearHover();
            hoveredCode = code;
            map.setFeatureState({ source: SOURCE_ID, id: code }, { hover: true });
        };

        const onLeave = () => clearHover();

        const onClick = (e: mapboxgl.MapMouseEvent) => {
            const props = e.features?.[0]?.properties as BarangayProperties | undefined;
            if (!props) return;
            const count = hazardCountsRef.current.get(props.psgc_code) ?? 0;

            const body = document.createElement("div");
            body.className = "text-sm";
            const title = document.createElement("div");
            title.className = "font-semibold";
            title.textContent = props.name;
            const detail = document.createElement("div");
            detail.className = "text-xs text-muted-foreground";
            detail.textContent = `${count} active hazard${count === 1 ? "" : "s"}`;
            body.append(title, detail);

            popupRef.current?.remove();
            popupRef.current = new mapboxgl.Popup({ closeButton: false, offset: 8 })
                .setLngLat(e.lngLat)
                .setDOMContent(body)
                .addTo(map);
        };

        map.on("mousemove", FILL_LAYER, onMove);
        map.on("mouseleave", FILL_LAYER, onLeave);
        map.on("click", FILL_LAYER, onClick);

        return () => {
            map.off("mousemove", FILL_LAYER, onMove);
            map.off("mouseleave", FILL_LAYER, onLeave);
            map.off("click", FILL_LAYER, onClick);
            popupRef.current?.remove();
            try {
                for (const id of LAYER_IDS) if (map.getLayer(id)) map.removeLayer(id);
                if (map.getSource(SOURCE_ID)) map.removeSource(SOURCE_ID);
            } catch {
                // style already torn down
            }
        };
    }, [map, isLoaded, boundaries]);

    // Show / hide without rebuilding the layers.
    useEffect(() => {
        if (!map || !isLoaded || !boundaries) return;
        for (const id of LAYER_IDS) {
            if (map.getLayer(id)) map.setLayoutProperty(id, "visibility", visible ? "visible" : "none");
        }
        if (!visible) popupRef.current?.remove();
    }, [map, isLoaded, boundaries, visible]);

    // Lighter strokes and labels on the darker light presets.
    useEffect(() => {
        if (!map || !isLoaded || !boundaries) return;
        if (map.getLayer(LINE_LAYER)) map.setPaintProperty(LINE_LAYER, "line-color", isDark ? "#cbd5e1" : "#334155");
        if (map.getLayer(FILL_LAYER)) map.setPaintProperty(FILL_LAYER, "fill-color", isDark ? "#94a3b8" : "#64748b");
        if (map.getLayer(LABEL_LAYER)) {
            map.setPaintProperty(LABEL_LAYER, "text-color", isDark ? "#f1f5f9" : "#1e293b");
            map.setPaintProperty(LABEL_LAYER, "text-halo-color", isDark ? "#0f172a" : "#ffffff");
        }
    }, [map, isLoaded, boundaries, isDark]);

    return null;
}
