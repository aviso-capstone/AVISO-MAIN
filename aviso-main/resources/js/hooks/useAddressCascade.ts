import { useEffect, useState } from 'react';

export interface Barangay { code: string; name: string }

const ZAMBOANGA_CITY_ID = '0931700';

export function useAddressCascade() {
    const [barangays, setBarangays] = useState<Barangay[]>([]);
    const [loading,   setLoading]   = useState(false);

    useEffect(() => {
        setLoading(true);
        fetch(`/api/address/barangays/${ZAMBOANGA_CITY_ID}`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(setBarangays)
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    return { barangays, loading };
}
