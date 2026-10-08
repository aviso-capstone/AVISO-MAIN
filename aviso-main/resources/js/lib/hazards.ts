// Helper to consistently assign colors to hazard types based on map theme
export function getHazardColor(type: string, theme: 'day' | 'night' | 'dusk' | 'dawn' = 'day'): string {
    const palettes = {
        day: {
            "Pothole":              "#ef4444",
            "Road Excavation":      "#f97316",
            "Road Barrier":         "#eab308",
            "default":              "#64748b"
        },
        night: {
            "Pothole":              "#ff0055",
            "Road Excavation":      "#ff9900",
            "Road Barrier":         "#fcd34d",
            "default":              "#94a3b8"
        },
        dusk: {
            "Pothole":              "#9f1239",
            "Road Excavation":      "#c2410c",
            "Road Barrier":         "#b45309",
            "default":              "#475569"
        },
        dawn: {
            "Pothole":              "#fca5a5",
            "Road Excavation":      "#fdba74",
            "Road Barrier":         "#fde047",
            "default":              "#cbd5e1"
        }
    };

    const palette = palettes[theme] || palettes.day;
    return palette[type as keyof typeof palette] || palette.default;
}

export const HAZARD_CHART_COLORS = {
    potholes:           '#ef4444',
    roadExcavation:     '#f97316',
    roadBarriers:       '#eab308',
} as const;

// Helper to consistently assign Tailwind classes to hazard types
export function getHazardTailwindColors(type: string): string {
    switch (type) {
        case "Pothole":
            return "text-red-600 bg-red-100 border-red-200 dark:bg-red-900/30 dark:border-red-900";
        case "Road Excavation":
            return "text-orange-600 bg-orange-100 border-orange-200 dark:bg-orange-900/30 dark:border-orange-900";
        case "Road Barrier":
            return "text-yellow-600 bg-yellow-100 border-yellow-200 dark:bg-yellow-900/30 dark:border-yellow-900";
        default:
            return "text-gray-600 bg-gray-100 border-gray-200";
    }
}
