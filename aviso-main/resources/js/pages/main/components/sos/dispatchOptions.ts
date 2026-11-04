/**
 * Dispatch-tracking choices. Mirrors the AGENCIES / ETA_OPTIONS / OUTCOMES
 * constants on app/Models/EmergencyAlert.php, which the server validates
 * against — keep both lists in sync.
 */

export interface SelectOption {
    value: string;
    label: string;
}

export const AGENCY_OTHER = 'other';

export const AGENCY_OPTIONS: SelectOption[] = [
    { value: 'zcdrrmo', label: 'ZCDRRMO' },
    { value: 'eoc', label: 'Emergency Operations Center' },
    { value: 'rescue', label: 'Technical Rescue / Fire Auxiliary' },
    { value: 'ems', label: 'Emergency Medical Services' },
    { value: 'fire', label: 'Zamboanga City Fire District' },
    { value: 'barangay', label: 'Barangay Responders' },
    { value: 'pnp', label: 'Police (PNP)' },
    { value: 'red_cross', label: 'Philippine Red Cross' },
    { value: 'national_911', label: '911' },
    { value: AGENCY_OTHER, label: 'Other' },
];

/** Select value for "no ETA given"; sent to the server as null. */
export const ETA_UNKNOWN = 'unknown';
export const ETA_MORE_THAN_HOUR = 61;

export const ETA_OPTIONS: SelectOption[] = [
    { value: '5', label: '5 minutes' },
    { value: '10', label: '10 minutes' },
    { value: '15', label: '15 minutes' },
    { value: '20', label: '20 minutes' },
    { value: '30', label: '30 minutes' },
    { value: '45', label: '45 minutes' },
    { value: '60', label: '1 hour' },
    { value: String(ETA_MORE_THAN_HOUR), label: 'More than 1 hour' },
    { value: ETA_UNKNOWN, label: 'Unknown' },
];

export const OUTCOME_OTHER = 'other';

export const OUTCOME_OPTIONS: SelectOption[] = [
    { value: 'hospital', label: 'Brought to hospital' },
    { value: 'treated_on_site', label: 'Treated on site' },
    { value: 'rider_ok', label: 'Rider OK, no help needed' },
    { value: 'false_alarm', label: 'False alarm' },
    { value: OUTCOME_OTHER, label: 'Other' },
];

export interface DispatchFormData {
    responder_agency: string;
    responder_agency_other: string | null;
    eta_minutes: number | null;
    responder_first_name: string | null;
    responder_last_name: string | null;
}

export interface ResolveFormData {
    outcome: string;
    resolution_note: string | null;
}

/** "about 15 min", "more than 1 hour" or "unknown". */
export function etaLabel(minutes: number | null | undefined): string {
    if (minutes == null) return 'unknown';
    if (minutes >= ETA_MORE_THAN_HOUR) return 'more than 1 hour';
    if (minutes === 60) return 'about 1 hour';
    return `about ${minutes} min`;
}

/** Status word shown on badges; `acknowledged` is the "help dispatched" step. */
export function statusLabel(status: string, onSceneAt?: string | null): string {
    if (status === 'acknowledged') return onSceneAt ? 'On scene' : 'Dispatched';
    if (status === 'resolved') return 'Resolved';
    if (status === 'pending') return 'Pending';
    return status;
}

/** Minutes between two ISO times, as "12 min" / "1 h 5 min"; null if either is missing. */
export function minutesBetween(from: string | null | undefined, to: string | null | undefined): string | null {
    if (!from || !to) return null;
    const mins = Math.max(0, Math.round((new Date(to).getTime() - new Date(from).getTime()) / 60000));
    if (mins < 60) return `${mins} min`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return m ? `${h} h ${m} min` : `${h} h`;
}

/**
 * True when help was dispatched with an ETA, that ETA has passed, and nobody
 * has marked the responders on scene yet — time for the admin to call back.
 */
export function isEtaOverdue(
    dispatchedAt: string | null | undefined,
    etaMinutes: number | null | undefined,
    onSceneAt: string | null | undefined,
    now: number = Date.now(),
): boolean {
    if (!dispatchedAt || etaMinutes == null || onSceneAt) return false;
    const etaMs = Math.min(etaMinutes, 60) * 60_000;
    return now > new Date(dispatchedAt).getTime() + etaMs;
}

/** First error message from a Laravel 422 response, or a fallback. */
export function firstValidationError(error: unknown, fallback: string): string {
    const data = (error as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } })
        ?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return first ?? data?.message ?? fallback;
}
