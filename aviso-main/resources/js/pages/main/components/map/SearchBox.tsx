import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useMap } from '@/components/ui/map';
import { Search } from 'lucide-react';
import { useState, useEffect } from 'react';

export function SearchBox() {
    const { map } = useMap();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<any[]>([]);

    useEffect(() => {
        if (!query.trim()) {
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
                // Only show results if query still matches what they typed
                setResults(data.features || []);
            } catch (err) {
                console.error(err);
            }
        }, 350); // 350ms debounce delay

        return () => clearTimeout(timer);
    }, [query]);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        // Pressing enter flies to the top result automatically
        if (results.length > 0) {
            flyTo(results[0]);
        }
    };

    const flyTo = (feature: any) => {
        if (!map) return;
        map.flyTo({ center: feature.center, zoom: 15 });
        setResults([]);
        setQuery(feature.place_name);
    };

    return (
        <div className="absolute top-3 right-14 z-10 w-72">
            <form onSubmit={handleSearch} className="relative">
                <Input 
                    placeholder="Search in Zamboanga City..." 
                    value={query}
                    onChange={e => setQuery(e.target.value)}
                    className="bg-background/95 backdrop-blur shadow-sm pr-8"
                />
                <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                    <Search className="w-4 h-4" />
                </button>
            </form>
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
