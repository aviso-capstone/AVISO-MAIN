import { Card } from '@/components/ui/card';
import { getHazardColor } from '@/lib/hazards';
import { type HazardLog } from '@/types/models';
import { AlertCircle, Cone, Construction } from 'lucide-react';
import { useMemo } from 'react';

const ICON_MAP: Record<string, React.ReactNode> = {
    'Pothole': <AlertCircle className="w-4 h-4 text-white" />,
    'Road Excavation': <Construction className="w-4 h-4 text-white" />,
    'Road Barrier': <Cone className="w-4 h-4 text-white" />,
};

interface LiveFeedSidebarProps {
    hazards: HazardLog[];
    onFlyTo: (coords: [number, number]) => void;
}

export function LiveFeedSidebar({ hazards, onFlyTo }: LiveFeedSidebarProps) {
    // Sort hazards by newest first
    const sortedFeed = useMemo(() => {
        return [...hazards].sort((a, b) => new Date(b.detected_at).getTime() - new Date(a.detected_at).getTime());
    }, [hazards]);

    return (
        <Card className="h-[700px] flex flex-col border-border/50 shadow-md bg-background/50 backdrop-blur-sm overflow-hidden">
            <div className="p-4 border-b bg-muted/20">
                <h2 className="font-heading font-semibold text-lg flex items-center gap-2">
                    <span className="relative flex h-3 w-3">
                      <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                      <span className="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                    </span>
                    Live Detection Feed
                </h2>
                <p className="text-xs text-muted-foreground mt-1">Real-time edge device reports</p>
            </div>
            
            <div className="flex-1 overflow-y-auto p-3 space-y-3 custom-scrollbar">
                {sortedFeed.map((hazard: HazardLog) => (
                    <div 
                        key={hazard.id} 
                        onClick={() => onFlyTo([hazard.longitude, hazard.latitude])}
                        className="p-3 rounded-lg border bg-card hover:bg-muted/50 cursor-pointer transition-colors shadow-sm group"
                    >
                        <div className="flex justify-between items-start mb-2">
                            <div className="flex items-center gap-2">
                                <div 
                                    className="p-1.5 rounded-md shadow-sm"
                                    style={{ backgroundColor: getHazardColor(hazard.type) }}
                                >
                                    {ICON_MAP[hazard.type]}
                                </div>
                                <div>
                                    <h3 className="font-medium text-sm leading-none">{hazard.type}</h3>
                                    <span className="text-[10px] text-muted-foreground font-mono mt-1 block">ID: {hazard.id}</span>
                                </div>
                            </div>
                            <div className="text-[10px] text-muted-foreground whitespace-nowrap">
                                {new Date(hazard.detected_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                            </div>
                        </div>
                        
                        <div className="flex items-center justify-between mt-3 text-xs">
                            <div className="flex items-center gap-1.5 bg-background border px-2 py-1 rounded text-muted-foreground">
                                <span>Confidence:</span>
                                <span className={hazard.confidence > 90 ? "text-green-600 font-semibold" : "text-yellow-600 font-semibold"}>
                                    {hazard.confidence}%
                                </span>
                            </div>
                            <span className="text-muted-foreground bg-background border px-2 py-1 rounded">
                                {hazard.distance}m away
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </Card>
    );
}
