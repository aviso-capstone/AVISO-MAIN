import { type LngLat } from './riderData';

export function getRiderThemeColor(base: string, theme: 'day' | 'night' | 'dusk' | 'dawn'): string {
    if (theme === 'night') {
        if (base === 'blue') return '#00f0ff';
        if (base === 'green') return '#39ff14';
        if (base === 'orange') return '#ff9900';
    } else if (theme === 'dusk') {
        if (base === 'blue') return '#4c1d95';
        if (base === 'green') return '#15803d';
        if (base === 'orange') return '#c2410c';
    } else if (theme === 'dawn') {
        if (base === 'blue') return '#818cf8';
        if (base === 'green') return '#86efac';
        if (base === 'orange') return '#fdba74';
    }
    if (base === 'blue') return '#3b82f6';
    if (base === 'green') return '#10b981';
    if (base === 'orange') return '#f59e0b';
    return '#3b82f6';
}

// Haversine distance in meters between two [lng, lat] points
export function haversineDistance(p1: LngLat, p2: LngLat): number {
    const R = 6_371_000;
    const toRad = (deg: number) => (deg * Math.PI) / 180;
    const dLat = toRad(p2[1] - p1[1]);
    const dLon = toRad(p2[0] - p1[0]);
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(p1[1])) * Math.cos(toRad(p2[1])) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}
