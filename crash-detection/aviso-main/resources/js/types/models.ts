export interface User {
    id: number;
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    username: string;
    email: string;
    contact_number: string | null;
    address: string | null;
    street: string | null;
    barangay_id: string | null;
    city_id: string | null;
    province_id: string | null;
    region_id: string | null;
    role: 'admin' | 'rider' | string;
    created_at: string;
    updated_at?: string;
}

export interface SystemSetting {
    id: number;
    confidence_threshold: number;
    items_per_page: number;
    default_sort: string;
    emergency_hazard_types: string[];
}

export interface HazardLog {
    id: number;
    haz_code: string;
    type: string;
    area: string;
    barangay_code: string | null;
    latitude: number;
    longitude: number;
    confidence: number;
    distance: number;
    rider_code: string;
    status: 'active' | string;
    detected_at: string;
}

export interface EmergencyAlert {
    id: number;
    user_id: number;
    rider_code: string;
    latitude: number;
    longitude: number;
    triggered_at: string;
    status: 'pending' | 'acknowledged' | 'resolved' | string;
    resolved_at: string | null;
}

export interface SosRiderSummary {
    id: number;
    first_name: string;
    last_name: string;
    username: string;
    emergency_alerts_count: number;
    emergency_alerts_max_triggered_at: string;
    emergency_alerts: EmergencyAlert[]; // latest 1, preloaded by the index endpoint
}

// Often Inertia passes paginated data in this structure:
export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

// A Zamboanga City barangay (PSGC 10-digit code), as listed in filters.
export interface Barangay {
    code: string;
    name: string;
}

// Road hazard counts for one barangay, ranked on the dashboard.
// Keys match HAZARD_CHART_COLORS in lib/hazards.ts so colors need no mapping.
export interface BarangayHazardCount {
    area: string;
    potholes: number;
    roadBarriers: number;
    roadExcavation: number;
    total: number;
}

/**
 * Wire shape of an SOS alert — produced by `EmergencyAlertTriggered::broadcastWith()`
 * on the backend and used by three paths that must agree: the live Reverb
 * broadcast, the alerts seeded into shared props on page load, and the
 * `?alert=<id>` historical deep link.
 */
export interface SosAlertPayload {
    id: number;
    rider_code: string;
    latitude: number;
    longitude: number;
    triggered_at: string;
    status: string;
    rider_name: string;
    username: string;
    contact: string;
    address: string;
}

/** Payload of the `emergency.resolved` broadcast. */
export interface SosAlertResolvedPayload {
    id: number;
    rider_code: string;
    status: string;
    resolved_at: string | null;
}
