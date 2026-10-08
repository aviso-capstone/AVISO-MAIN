export interface EmergencyHotline {
    label: string;
    numbers: string[];
}

// Curated quick-reference list for Zamboanga City — top-level city hotlines
// only. Individual barangay fire sub-stations are intentionally omitted to
// keep the panel scannable during an actual emergency.
export const emergencyHotlines: EmergencyHotline[] = [
    {
        label: 'ZCDRRMO Hotline',
        numbers: ['995-9601', '990-1171'],
    },
    {
        label: 'Emergency Operations Center',
        numbers: ['0966-731-6242', '0955-004-3682', '0928-896-6279'],
    },
    {
        label: 'Technical Rescue / Fire Auxiliary',
        numbers: ['0926-091-2492'],
    },
    {
        label: 'Emergency Medical Services',
        numbers: ['926-1848'],
    },
    {
        label: 'Zamboanga City Fire District',
        numbers: ['991-2267', '0955-781-6063'],
    },
];
