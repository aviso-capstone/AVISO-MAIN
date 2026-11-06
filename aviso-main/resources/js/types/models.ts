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
    /** False = deactivated admin (can't log in). Missing before the migration runs. */
    is_active?: boolean;
    created_at: string;
    updated_at?: string;
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
    distance: number | null;
    /** Nearest other active hazard of the same type (Hazard to Hazard Distance); null when none. */
    nearest_hazard_code?: string | null;
    nearest_hazard_distance?: number | null;
    rider_code: string;
    status: 'active' | 'resolved' | string;
    detected_at: string;
    resolved_at?: string | null;
    /** fixed | not_hazard | other */
    resolved_reason?: string | null;
    /** Admin who resolved it (Hazard Logs only). */
    resolved_by_name?: string | null;
    /** The rider's confirmed "no longer there" photo (short-lived link, Hazard Logs only). */
    proof_photo_url?: string | null;
    /** Riders' "no longer there" reports waiting for review (Live Map only). */
    pending_reports_count?: number;
    /** The rider's GPS point when detected; latitude/longitude are the hazard's own position. */
    rider_latitude?: number | null;
    rider_longitude?: number | null;
    /** Direction of travel used to place the hazard (degrees from north). */
    travel_heading?: number | null;
    /** How the position was worked out: gps | route | none. */
    position_source?: 'gps' | 'route' | 'none' | null;
    /** How many times (rides) this hazard was seen on the road. */
    times_seen?: number;
    last_seen_at?: string | null;
    /** Live Map: ids of every save of this hazard (the pin is their average). */
    member_ids?: number[];
}

/** A rider's "hazard no longer there" report, with a photo as proof. */
export interface HazardReportPayload {
    id: number;
    hazard_log_id: number;
    status: 'pending' | 'confirmed' | 'rejected';
    photo_url: string | null;
    note: string | null;
    /** Rider's position when reporting (null without a GPS fix). */
    latitude: number | null;
    longitude: number | null;
    /** Rider-to-hazard distance in meters when reporting. */
    distance_m: number | null;
    created_at: string;
    rider_code: string;
    rider_name: string | null;
    hazard: {
        id: number;
        haz_code: string;
        type: string;
        area: string;
        latitude: number;
        longitude: number;
        confidence: number | null;
        rider_code: string;
        status: string;
        detected_at: string;
    } | null;
}

/** Broadcast when an admin confirms or rejects a report. */
export interface HazardReportReviewedPayload {
    report_id: number;
    status: 'confirmed' | 'rejected';
    closed_report_ids: number[];
    resolved_hazard_ids: number[];
}

export interface EmergencyAlert {
    id: number;
    user_id: number;
    rider_code: string;
    latitude: number;
    longitude: number;
    triggered_at: string;
    /** `acknowledged` = help dispatched. */
    status: 'pending' | 'acknowledged' | 'resolved' | string;
    resolved_at: string | null;
    dispatched_at?: string | null;
    responder_agency?: string | null;
    responder_agency_other?: string | null;
    responder_first_name?: string | null;
    responder_last_name?: string | null;
    eta_minutes?: number | null;
    on_scene_at?: string | null;
    outcome?: string | null;
    resolution_note?: string | null;
    agency_label?: string | null;
    outcome_label?: string | null;
    handled_by_name?: string | null;
}

/** Dispatch-tracking fields carried by every SOS alert payload. */
export interface SosDispatchFields {
    dispatched_at: string | null;
    responder_agency: string | null;
    responder_agency_other: string | null;
    /** Agency as shown to people (the typed name when "Other"). */
    agency_label: string | null;
    responder_first_name: string | null;
    responder_last_name: string | null;
    /** null = Unknown, 61 = more than 1 hour. */
    eta_minutes: number | null;
    on_scene_at: string | null;
    outcome: string | null;
    outcome_label: string | null;
    resolution_note: string | null;
    resolved_at: string | null;
    /** The admin who dispatched help. */
    handled_by_name: string | null;
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
export interface SosAlertPayload extends SosDispatchFields {
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
    /** How the SOS started; null on alerts older than this field. */
    trigger_type: SosTriggerType | null;
    /** Hazard the same rider detected within 2 min before and 100 m of the SOS. */
    hazard_before: SosHazardBefore | null;
}

export type SosTriggerType = 'manual' | 'crash_iot' | 'crash_phone';

export interface SosHazardBefore {
    haz_code: string;
    type: string;
    confidence: number;
    detected_at: string | null;
    meters_from_sos: number;
}

/** Payload of the `emergency.resolved` broadcast. */
export interface SosAlertResolvedPayload {
    id: number;
    rider_code: string;
    status: string;
    resolved_at: string | null;
}

// ─── Crash detection log (IoT unit classifications) ──────────────────────────

export type RiderEventType = 'normal' | 'hard_braking' | 'road_bump' | 'crash';

export interface RiderEventLog {
    id: number;
    event_uid: string | null;
    rider_code: string;
    trip_id: number | null;
    event_type: RiderEventType;
    latitude: string;
    longitude: string;
    area: string | null;
    acceleration_peak: string;
    vertical_g: string | null;
    horizontal_g: string | null;
    gyro_peak_dps: string | null;
    tilt_deg: string | null;
    status: string;
    detected_at: string;
}

export interface MinAvgMax {
    min: number | null;
    avg: number | null;
    max: number | null;
}

/** Per-category ranges used to set the unit's detection thresholds. */
export interface RiderEventTypeStats {
    type: RiderEventType;
    label: string;
    total: number;
    g: MinAvgMax;
    vertical_g: MinAvgMax;
    horizontal_g: MinAvgMax;
    gyro_dps: MinAvgMax;
    tilt_deg: MinAvgMax;
}
