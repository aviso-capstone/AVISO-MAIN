import { useMap } from "@/components/ui/map";
import { Button } from "@/components/ui/button";
import {
    RotateCcw,
    Mountain,
    RefreshCw,
    Layers,
    Sun,
    Moon,
    Sunset,
    Sunrise,
    Filter,
    MapPinned,
    TriangleAlert,
} from "lucide-react";
import { useEffect, useRef, useState } from "react";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { type HazardLog } from "@/types/models";
import { getHazardColor } from "@/lib/hazards";
import { bundledRiskSquares, RISK_MAP_VERSION } from "@/lib/risk-areas";

/** Purple: predicted risk (not a detected hazard); hazard pins use red, orange and yellow. */
const RISK_FILL = "#8B5CF6";

/** Pin colors for the legend (day palette, as the hazard pins). */
const DETECTED_COLORS = ["Pothole", "Road Excavation", "Road Barrier"].map((type) => getHazardColor(type));

// Shifted camera center slightly North so the riders load at the bottom of the screen
const ZAMBOANGA: [number, number] = [122.0739, 6.935];

interface MapControllerProps {
    hazards?: HazardLog[];
    activeFilters?: string[];
    setActiveFilters?: (filters: string[]) => void;
    availableTypes?: string[];
    lightPreset?: "day" | "night" | "dusk" | "dawn";
    setLightPreset?: (preset: "day" | "night" | "dusk" | "dawn") => void;
    showBarangays?: boolean;
    setShowBarangays?: (show: boolean) => void;
}

export function MapController({
    hazards = [],
    activeFilters = [],
    setActiveFilters,
    availableTypes = [],
    lightPreset = "day",
    setLightPreset,
    showBarangays = false,
    setShowBarangays,
}: MapControllerProps) {
    const { map, isLoaded } = useMap();
    const [pitch, setPitch] = useState(0);
    const [bearing, setBearing] = useState(0);
    const [is3D, setIs3D] = useState(false);
    const [showHeatmap, setShowHeatmap] = useState(false);
    const [showRiskAreas, setShowRiskAreas] = useState(false);

    // 360° Auto-Rotate state
    const [isRotating, setIsRotating] = useState(false);
    const rafRef = useRef<number | null>(null);

    // Custom dropdown state for Filters
    const [showFilters, setShowFilters] = useState(false);

    const initRef = useRef(false);

    // Initial setup
    useEffect(() => {
        if (!map || !isLoaded || initRef.current) return;
        initRef.current = true;
        map.jumpTo({ center: ZAMBOANGA, zoom: 14 });

        // Add Heatmap Source & Layer
        if (!map.getSource("hazards-source")) {
            map.addSource("hazards-source", {
                type: "geojson",
                data: { type: "FeatureCollection", features: [] },
            });

            map.addLayer({
                id: "hazards-heat",
                type: "heatmap",
                source: "hazards-source",
                maxzoom: 20,
                layout: { visibility: "none" }, // hidden by default
                paint: {
                    "heatmap-weight": [
                        "interpolate",
                        ["linear"],
                        ["get", "confidence"],
                        0,
                        0,
                        100,
                        1,
                    ],
                    "heatmap-intensity": [
                        "interpolate",
                        ["linear"],
                        ["zoom"],
                        11,
                        1,
                        15,
                        3,
                    ],
                    // This color gradient will be overwritten dynamically
                    "heatmap-color": [
                        "interpolate",
                        ["linear"],
                        ["heatmap-density"],
                        0,
                        "rgba(33,102,172,0)",
                        0.2,
                        "rgb(103,169,207)",
                        0.4,
                        "rgb(209,229,240)",
                        0.6,
                        "rgb(253,219,199)",
                        0.8,
                        "rgb(239,138,98)",
                        1,
                        "rgb(178,24,43)",
                    ],
                    "heatmap-radius": [
                        "interpolate",
                        ["linear"],
                        ["zoom"],
                        11,
                        15,
                        15,
                        30,
                    ],
                    "heatmap-opacity": 0.7,
                },
            });
        }

        // Risk areas: the risk map's high-risk squares (the ones that trigger
        // "Possible hazard ahead" on the rider app). Hidden by default.
        if (!map.getSource("risk-areas-source")) {
            map.addSource("risk-areas-source", {
                type: "geojson",
                data: bundledRiskSquares(),
            });
            map.addLayer({
                id: "risk-areas-fill",
                type: "fill",
                source: "risk-areas-source",
                layout: { visibility: "none" },
                paint: {
                    "fill-color": RISK_FILL,
                    "fill-opacity": 0.35,
                    "fill-outline-color": RISK_FILL,
                },
            });
        }
    }, [map, isLoaded]);

    // Update Heatmap Data Source
    useEffect(() => {
        if (!map || !isLoaded) return;
        const source = map.getSource("hazards-source");
        if (source) {
            const heatmapHazards = hazards.filter((h) =>
                ["Pothole", "Road Excavation", "Road Barrier"].includes(h.type),
            );

            (source as any).setData({
                type: "FeatureCollection",
                features: heatmapHazards.map((h) => ({
                    type: "Feature",
                    geometry: {
                        type: "Point",
                        coordinates: [h.longitude, h.latitude],
                    },
                    properties: { confidence: h.confidence },
                })),
            });
        }
    }, [map, isLoaded, hazards]);

    // Apply Light Preset and Dynamic Heatmap Colors
    useEffect(() => {
        if (!map || !isLoaded) return;
        try {
            map.setConfigProperty("basemap", "lightPreset", lightPreset);
        } catch (e) {
            // Ignored if map style is not mapbox standard
        }

        // Apply dynamic heatmap colors if the layer exists
        if (map.getLayer("hazards-heat")) {
            let heatmapColor: any[];
            if (lightPreset === "night") {
                heatmapColor = [
                    "interpolate",
                    ["linear"],
                    ["heatmap-density"],
                    0,
                    "rgba(0,0,0,0)",
                    0.2,
                    "#4c1d95", // deep purple
                    0.4,
                    "#7c3aed", // purple
                    0.6,
                    "#ec4899", // pink
                    0.8,
                    "#06b6d4", // cyan
                    1,
                    "#22d3ee", // light cyan
                ];
            } else if (lightPreset === "dusk") {
                heatmapColor = [
                    "interpolate",
                    ["linear"],
                    ["heatmap-density"],
                    0,
                    "rgba(0,0,0,0)",
                    0.2,
                    "#581c87", // deep violet
                    0.4,
                    "#9f1239", // crimson
                    0.6,
                    "#ea580c", // orange
                    0.8,
                    "#f59e0b", // amber
                    1,
                    "#fde047", // yellow
                ];
            } else if (lightPreset === "dawn") {
                heatmapColor = [
                    "interpolate",
                    ["linear"],
                    ["heatmap-density"],
                    0,
                    "rgba(0,0,0,0)",
                    0.2,
                    "#38bdf8", // light blue
                    0.4,
                    "#818cf8", // indigo
                    0.6,
                    "#c084fc", // purple
                    0.8,
                    "#f472b6", // pink
                    1,
                    "#fde047", // soft yellow
                ];
            } else {
                // Default Day
                heatmapColor = [
                    "interpolate",
                    ["linear"],
                    ["heatmap-density"],
                    0,
                    "rgba(33,102,172,0)",
                    0.2,
                    "rgb(103,169,207)",
                    0.4,
                    "rgb(209,229,240)",
                    0.6,
                    "rgb(253,219,199)",
                    0.8,
                    "rgb(239,138,98)",
                    1,
                    "rgb(178,24,43)",
                ];
            }

            map.setPaintProperty(
                "hazards-heat",
                "heatmap-color",
                heatmapColor as any,
            );
        }
    }, [lightPreset, map, isLoaded]);

    // Apply Heatmap Visibility
    useEffect(() => {
        if (!map || !isLoaded || !map.getLayer("hazards-heat")) return;
        map.setLayoutProperty(
            "hazards-heat",
            "visibility",
            showHeatmap ? "visible" : "none",
        );
    }, [showHeatmap, map, isLoaded]);

    // Apply Risk areas visibility
    useEffect(() => {
        if (!map || !isLoaded || !map.getLayer("risk-areas-fill")) return;
        map.setLayoutProperty(
            "risk-areas-fill",
            "visibility",
            showRiskAreas ? "visible" : "none",
        );
    }, [showRiskAreas, map, isLoaded]);

    // Track camera values for overlay
    useEffect(() => {
        if (!map || !isLoaded) return;
        const onMove = () => {
            setPitch(Math.round(map.getPitch()));
            setBearing(Math.round(map.getBearing()));
        };
        map.on("move", onMove);
        return () => {
            map.off("move", onMove);
        };
    }, [map, isLoaded]);

    // ── 360° rotation helpers ──
    const stopRotation = () => {
        if (rafRef.current !== null) {
            cancelAnimationFrame(rafRef.current);
            rafRef.current = null;
        }
        setIsRotating(false);
    };

    const startRotation = () => {
        if (!map) return;
        setIsRotating(true);
        const spin = () => {
            map.setBearing((map.getBearing() + 0.3) % 360);
            rafRef.current = requestAnimationFrame(spin);
        };
        rafRef.current = requestAnimationFrame(spin);
        map.once("mousedown", stopRotation);
        map.once("touchstart", stopRotation);
    };

    const toggleRotate = () => (isRotating ? stopRotation() : startRotation());
    useEffect(() => () => stopRotation(), []);

    // ── Buttons ──
    const handle3DView = () => {
        if (!map) return;

        if (is3D) {
            stopRotation();
            setIs3D(false);
            try {
                map.setConfigProperty("basemap", "show3dObjects", false);
            } catch (e) {}
            map.easeTo({ pitch: 0, bearing: 0, duration: 800 });
        } else {
            setIs3D(true);
            try {
                map.setConfigProperty("basemap", "show3dObjects", true);
            } catch (e) {}
            map.easeTo({ pitch: 60, bearing: -20, duration: 1200 });
        }
    };

    const handleReset = () => {
        if (!map) return;
        stopRotation();
        setIs3D(false);
        try {
            map.setConfigProperty("basemap", "show3dObjects", false);
        } catch (e) {}
        map.easeTo({ pitch: 0, bearing: 0, duration: 800 });
    };

    if (!isLoaded) return null;

    return (
        <div className="absolute top-3 left-3 z-10 flex flex-col gap-2">
            <div className="flex gap-2 flex-wrap items-center bg-background/90 p-1.5 rounded-lg border backdrop-blur shadow-sm">
                {/* 3D toggle */}
                <Button
                    size="sm"
                    variant={is3D ? "default" : "ghost"}
                    onClick={handle3DView}
                >
                    <Mountain className="mr-1.5 h-4 w-4" />
                    3D View
                </Button>

                {/* Reset */}
                <Button size="sm" variant="ghost" onClick={handleReset}>
                    <RotateCcw className="mr-1.5 h-4 w-4" />
                    Reset
                </Button>

                <div className="w-px h-5 bg-border mx-1" />

                {/* Heatmap Toggle */}
                <Button
                    size="sm"
                    variant={showHeatmap ? "default" : "ghost"}
                    onClick={() => setShowHeatmap(!showHeatmap)}
                    className={
                        showHeatmap
                            ? "bg-orange-600 hover:bg-orange-700 text-white"
                            : ""
                    }
                >
                    <Layers className="mr-1.5 h-4 w-4" />
                    Heatmap
                </Button>

                {/* Risk areas toggle (team risk map) */}
                <Button
                    size="sm"
                    variant={showRiskAreas ? "default" : "ghost"}
                    onClick={() => setShowRiskAreas(!showRiskAreas)}
                    aria-pressed={showRiskAreas}
                    title="Places where hazards are likely, from the team risk map"
                    className={
                        showRiskAreas
                            ? "bg-violet-500 hover:bg-violet-600 text-white"
                            : ""
                    }
                >
                    <TriangleAlert className="mr-1.5 h-4 w-4" />
                    Risk areas
                </Button>

                {/* Barangay boundaries toggle */}
                {setShowBarangays && (
                    <Button
                        size="sm"
                        variant={showBarangays ? "default" : "ghost"}
                        onClick={() => setShowBarangays(!showBarangays)}
                        aria-pressed={showBarangays}
                    >
                        <MapPinned className="mr-1.5 h-4 w-4" />
                        Barangays
                    </Button>
                )}

                <div className="w-px h-5 bg-border mx-1" />

                {/* Filter Hazards Dropdown (Custom to avoid Mapbox portal conflicts) */}
                {setActiveFilters && availableTypes.length > 0 && (
                    <div className="relative">
                        <Button
                            size="sm"
                            variant={
                                activeFilters.length < availableTypes.length
                                    ? "default"
                                    : "ghost"
                            }
                            onClick={() => setShowFilters(!showFilters)}
                        >
                            <Filter className="mr-1.5 h-4 w-4" />
                            Filters
                        </Button>

                        {showFilters && (
                            <div className="absolute top-full mt-2 left-0 w-48 bg-background border rounded-md shadow-lg py-1.5 z-50 animate-in fade-in zoom-in-95">
                                {availableTypes.map((type) => (
                                    <label
                                        key={type}
                                        className="flex items-center px-3 py-2 text-sm hover:bg-muted cursor-pointer transition-colors"
                                    >
                                        <input
                                            type="checkbox"
                                            className="mr-2 h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                            checked={activeFilters.includes(type)}
                                            onChange={(e) => {
                                                if (e.target.checked) {
                                                    setActiveFilters([
                                                        ...activeFilters,
                                                        type,
                                                    ]);
                                                } else {
                                                    setActiveFilters(
                                                        activeFilters.filter(
                                                            (f) => f !== type,
                                                        ),
                                                    );
                                                }
                                            }}
                                        />
                                        {type}
                                    </label>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                <div className="w-px h-5 bg-border mx-1" />

                {/* Light Preset Toggle (Shadcn Select with Lucide Icons) */}
                {setLightPreset && (
                    <Select
                        value={lightPreset}
                        onValueChange={(val: any) => setLightPreset(val)}
                    >
                        <SelectTrigger className="w-[110px] h-8 bg-transparent border-none shadow-none focus:ring-0 px-2 py-0">
                            <SelectValue placeholder="Theme" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="day">
                                <div className="flex items-center">
                                    <Sun className="w-4 h-4 mr-2" />
                                    Day
                                </div>
                            </SelectItem>
                            <SelectItem value="night">
                                <div className="flex items-center">
                                    <Moon className="w-4 h-4 mr-2" />
                                    Night
                                </div>
                            </SelectItem>
                            <SelectItem value="dusk">
                                <div className="flex items-center">
                                    <Sunset className="w-4 h-4 mr-2" />
                                    Dusk
                                </div>
                            </SelectItem>
                            <SelectItem value="dawn">
                                <div className="flex items-center">
                                    <Sunrise className="w-4 h-4 mr-2" />
                                    Dawn
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                )}

                {/* 360° rotate — only shown in 3D mode */}
                {is3D && (
                    <>
                        <div className="w-px h-5 bg-border mx-1" />
                        <Button
                            size="sm"
                            variant={isRotating ? "default" : "ghost"}
                            onClick={toggleRotate}
                        >
                            <RefreshCw
                                className={`mr-1.5 h-4 w-4 ${isRotating ? "animate-spin" : ""}`}
                            />
                            {isRotating ? "Stop" : "360°"}
                        </Button>
                    </>
                )}
            </div>

            <div className="bg-background/90 rounded-md border px-3 py-2 font-mono text-xs backdrop-blur shadow-sm w-fit">
                <div>Pitch: {pitch}°</div>
                <div>Bearing: {bearing}°</div>
                {is3D && (
                    <div className="text-primary mt-1 font-semibold">
                        ● 3D Mode
                    </div>
                )}
                {isRotating && (
                    <div className="text-yellow-500 font-semibold">
                        ↻ Rotating 360°
                    </div>
                )}
                {showHeatmap && (
                    <div className="text-orange-500 font-semibold">
                        🔥 Heatmap Active
                    </div>
                )}
                {showRiskAreas && (
                    <div className="mt-1.5 flex flex-col gap-1 border-t pt-1.5 font-sans">
                        <div className="flex items-center gap-2">
                            <span className="h-3 w-5 rounded-sm bg-violet-500/70" />
                            <span className="font-semibold text-violet-600 dark:text-violet-400">
                                Possible hazard area (past hazard records)
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="flex w-5 justify-between">
                                {DETECTED_COLORS.map((color) => (
                                    <span key={color} className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: color }} />
                                ))}
                            </span>
                            <span>Detected hazard (pothole · excavation · barrier)</span>
                        </div>
                        <div className="flex items-center gap-2 text-muted-foreground">
                            <span className="h-3 w-5 rounded-sm border border-muted-foreground" />
                            <span>No color: lower risk or not yet surveyed</span>
                        </div>
                        {RISK_MAP_VERSION && (
                            <div className="text-muted-foreground">Risk map {RISK_MAP_VERSION}</div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
