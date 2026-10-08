import { type HazardLog } from '@/types/models';

export type LngLat = [number, number];

export type RiderDef = {
    id: string;
    name: string;
    colorBase: string;
    userInfo: {
        fullName: string;
        username: string;
        contact: string;
        address: string;
    };
};

export interface EmergencyAlert {
    id: string;
    riderId: string;
    riderName: string;
    colorBase: string;
    coords: LngLat;
    userInfo: RiderDef['userInfo'];
    triggeredAt: string;
    nearestHazard: HazardLog | null;
    /** Only meaningful for alerts opened from history — live arrivals are
     * always freshly 'pending' so this is left undefined for them. */
    status?: string;
}

export const RIDER_DEFS: RiderDef[] = [
    {
        id: 'rider-1',
        name: 'Rider 1 - San Jose Gusu',
        colorBase: 'blue',
        userInfo: {
            fullName: 'Maria Santos',
            username: 'rider_maria',
            contact: '09171234002',
            address: 'Calarian, Zamboanga City',
        },
    },
    {
        id: 'rider-2',
        name: 'Rider 2 - Pasonanca Road',
        colorBase: 'green',
        userInfo: {
            fullName: 'Pedro Reyes',
            username: 'rider_pedro',
            contact: '09171234003',
            address: 'San Roque, Zamboanga City',
        },
    },
    {
        id: 'rider-3',
        name: 'Rider 3 - Putik to Tugbungan',
        colorBase: 'orange',
        userInfo: {
            fullName: 'Ana Gomez',
            username: 'rider_ana',
            contact: '09171234004',
            address: 'Tugbungan, Zamboanga City',
        },
    },
    {
        id: 'rider-4',
        name: 'Rider 4 - Calarian',
        colorBase: 'blue',
        userInfo: {
            fullName: 'Juan Cruz',
            username: 'rider_juan',
            contact: '09185001001',
            address: 'Calarian, Zamboanga City',
        },
    },
    {
        id: 'rider-5',
        name: 'Rider 5 - Sta Maria',
        colorBase: 'green',
        userInfo: {
            fullName: 'Rosa Dela Cruz',
            username: 'rider_rosa',
            contact: '09185001002',
            address: 'Sta. Maria, Zamboanga City',
        },
    },
    {
        id: 'rider-6',
        name: 'Rider 6 - Lunzuran',
        colorBase: 'orange',
        userInfo: {
            fullName: 'Carlo Bautista',
            username: 'rider_carlo',
            contact: '09185001003',
            address: 'Lunzuran, Zamboanga City',
        },
    },
    {
        id: 'rider-7',
        name: 'Rider 7 - Baliwasan',
        colorBase: 'blue',
        userInfo: {
            fullName: 'Lisa Manalo',
            username: 'rider_lisa',
            contact: '09185001004',
            address: 'Baliwasan, Zamboanga City',
        },
    },
    {
        id: 'rider-8',
        name: 'Rider 8 - Tumaga',
        colorBase: 'green',
        userInfo: {
            fullName: 'Marco Dela Vega',
            username: 'rider_marco',
            contact: '09185001005',
            address: 'Tumaga, Zamboanga City',
        },
    },
    {
        id: 'rider-9',
        name: 'Rider 9 - Talon-Talon',
        colorBase: 'orange',
        userInfo: {
            fullName: 'Elena Fernandez',
            username: 'rider_elena',
            contact: '09185001006',
            address: 'Talon-Talon, Zamboanga City',
        },
    },
    {
        id: 'rider-10',
        name: 'Rider 10 - City Proper',
        colorBase: 'blue',
        userInfo: {
            fullName: 'Jose Villanueva',
            username: 'rider_jose',
            contact: '09185001007',
            address: 'City Proper, Zamboanga City',
        },
    },
];
