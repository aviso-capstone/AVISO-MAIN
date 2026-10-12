import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useMap } from '@/components/ui/map';
import { formatCoordinates, parseCoordinates } from '@/lib/coordinates';
import mapboxgl from 'mapbox-gl';
import { MapPin, Search, X } from 'lucide-react';
import { useState, useEffect, useMemo, useRef } from 'react';

type PlaceResult = { place_name: string; center: [number, number] };

/**
 * Search box on the Live Map: place names (Mapbox geocoding, limited to Zamboanga City)
 * or coordinates such as "6.92314, 122.08251", which fly to that exact spot and drop a pin.
 */
export function SearchBox() {
    const { map } = useMap();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<PlaceResult[]>([]);
    const pinRef = useRef<mapboxgl.Marker | null>(null);

    const coordinates = useMemo(() => parseCoordinates(query), [query]);

    useEffect(() => {
        // Coordinates don't need the place search.
        if (!query.trim() || coordinates.kind !== 'not-coordinates') {
            setResults([]);
            return;
        }

        const timer = setTimeout(async () => {
            const token = import.meta.env.VITE_MAPBOX_TOKEN;
            // Bounding box for Zamboanga City approx: 121.9,6.8,122.3,7.2
            const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json?bbox=121.9,6.8,122.3,7.2&access_token=${token}`;

            try {
                const res = await fetch(url);
                const data = await res.json();
                setResults(data.features || []);
            } catch (err) {
                console.error(err);
            }
        }, 350); // 350ms debounce delay

        return () => clearTimeout(timer);
    }, [query, coordinates.kind]);

    // Remove the pin when the map page closes.
    useEffect(() => () => {
        pinRef.current?.remove();
    }, []);

    const removePin = () => {
        pinRef.current?.remove();
        pinRef.current = null;
    };

    const goToCoordinates = (latitude: number, longitude: number) => {
        if (!map) return;
        removePin();
        const label = formatCoordinates(latitude, longitude);
        pinRef.current = new mapboxgl.Marker({ color: '#2563eb' })
            .setLngLat([longitude, latitude])
            .setPopup(new mapboxgl.Popup({ offset: 24, closeButton: false }).setText(label))
            .addTo(map);
        map.flyTo({ center: [longitude, latitude], zoom: 17, duration: 1500, essential: true });
        setQuery(label);
    };

    const flyTo = (feature: PlaceResult) => {
        if (!map) return;
        removePin();
        map.flyTo({ center: feature.center, zoom: 15, duration: 1500, essential: true });
        setResults([]);
        setQuery(feature.place_name);
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        if (coordinates.kind === 'coordinates') {
            goToCoordinates(coordinates.latitude, coordinates.longitude);
        } else if (results.length > 0) {
            // Pressing enter flies to the top result automatically
            flyTo(results[0]);
        }
    };

    const clear = () => {
        setQuery('');
        setResults([]);
        removePin();
    };

    return (
        <div className="absolute top-3 right-14 z-10 w-72">
            <form onSubmit={handleSearch} className="relative">
                <Input
                    placeholder="Search a place or 6.92314, 122.08251"
                    aria-label="Search a place or coordinates"
                    value={query}
                    onChange={e => setQuery(e.target.value)}
                    className="bg-background/95 backdrop-blur shadow-sm pr-14"
                />
                {query && (
                    <button
                        type="button"
                        onClick={clear}
                        aria-label="Clear search"
                        className="absolute right-8 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                    >
                        <X className="w-4 h-4" />
                    </button>
                )}
                <button type="submit" aria-label="Search" className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                    <Search className="w-4 h-4" />
                </button>
            </form>
            {coordinates.kind === 'coordinates' && query !== formatCoordinates(coordinates.latitude, coordinates.longitude) && (
                <Card className="mt-1 absolute w-full shadow-lg z-50">
                    <button
                        type="button"
                        onClick={() => goToCoordinates(coordinates.latitude, coordinates.longitude)}
                        className="w-full px-3 py-2 text-sm hover:bg-muted text-left flex items-start gap-2"
                    >
                        <MapPin className="w-4 h-4 mt-0.5 shrink-0" />
                        <span>
                            Go to {formatCoordinates(coordinates.latitude, coordinates.longitude)}
                            {coordinates.outsideCity && (
                                <span className="block text-xs text-muted-foreground">Outside Zamboanga City</span>
                            )}
                        </span>
                    </button>
                </Card>
            )}
            {coordinates.kind === 'invalid' && (
                <Card className="mt-1 absolute w-full shadow-lg z-50 px-3 py-2 text-sm text-destructive">
                    Not valid coordinates (latitude −90 to 90, longitude −180 to 180)
                </Card>
            )}
            {results.length > 0 && (
                <Card className="mt-1 max-h-60 overflow-y-auto absolute w-full shadow-lg z-50">
                    <ul className="py-1">
                        {results.map((r, i) => (
                            <li
                                key={i}
                                onClick={() => flyTo(r)}
                                className="px-3 py-2 text-sm hover:bg-muted cursor-pointer truncate"
                            >
                                {r.place_name}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </div>
    );
}
