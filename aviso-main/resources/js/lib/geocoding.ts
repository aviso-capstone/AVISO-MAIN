export async function reverseGeocode(lng: number, lat: number): Promise<string> {
    const token = import.meta.env.VITE_MAPBOX_TOKEN as string | undefined;
    if (!token) throw new Error('No Mapbox token');
    const res = await fetch(
        `https://api.mapbox.com/geocoding/v5/mapbox.places/${lng},${lat}.json?types=neighborhood,locality,district,place&access_token=${token}&language=en&limit=1`
    );
    if (!res.ok) throw new Error('Geocoding request failed');
    const data = await res.json();
    const feature = data.features?.[0];
    if (!feature) throw new Error('No results');

    const ctx: Array<{ id: string; text: string }> = feature.context ?? [];
    const neighborhood = ctx.find(c => c.id.startsWith('neighborhood'))?.text;
    const locality = ctx.find(c => c.id.startsWith('locality'))?.text;
    const district = ctx.find(c => c.id.startsWith('district'))?.text;

    return neighborhood ?? locality ?? district ?? feature.text ?? 'Unknown';
}
