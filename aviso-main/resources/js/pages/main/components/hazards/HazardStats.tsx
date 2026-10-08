import { Card, CardContent } from "@/components/ui/card";
import {
    AlertCircle,
    Cone,
    Construction,
} from "lucide-react";

interface HazardStatsProps {
    totalHazards: number;
    types: string[];
    stats: { [key: string]: number };
}

export function HazardStats({ totalHazards, types, stats }: HazardStatsProps) {
    const getIcon = (type: string) => {
        switch (type) {
            case "Pothole":
                return <AlertCircle className="w-4 h-4" />;
            case "Road Excavation":
                return <Construction className="w-4 h-4" />;
            case "Road Barrier":
                return <Cone className="w-4 h-4" />;
            default:
                return <AlertCircle className="w-4 h-4" />;
        }
    };

    const getColor = (type: string) => {
        switch (type) {
            case "Pothole":
                return "text-red-600 bg-red-100 border-red-200 dark:bg-red-900/30 dark:border-red-900";
            case "Road Excavation":
                return "text-orange-600 bg-orange-100 border-orange-200 dark:bg-orange-900/30 dark:border-orange-900";
            case "Road Barrier":
                return "text-yellow-600 bg-yellow-100 border-yellow-200 dark:bg-yellow-900/30 dark:border-yellow-900";
            default:
                return "text-gray-600 bg-gray-100 border-gray-200";
        }
    };

    return (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
            {/* Total */}
            <Card className="col-span-2 bg-primary text-primary-foreground shadow-sm">
                <CardContent className="p-4 flex flex-col justify-center h-full">
                    <p className="text-sm font-medium opacity-80 mb-1">
                        Total Records
                    </p>
                    <p className="text-3xl font-bold font-heading">
                        {totalHazards}
                    </p>
                </CardContent>
            </Card>

            {/* Individual Types */}
            {types.map((type) => (
                <Card key={type} className="shadow-sm border-border/50">
                    <CardContent className="p-4">
                        <div className="flex items-center gap-2 mb-2">
                            <div className={getColor(type).split(" ")[0]}>
                                {getIcon(type)}
                            </div>
                            <span
                                className="text-xs font-medium text-muted-foreground truncate"
                                title={type}
                            >
                                {type}
                            </span>
                        </div>
                        <p className="text-2xl font-bold font-heading">
                            {stats[type] || 0}
                        </p>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
