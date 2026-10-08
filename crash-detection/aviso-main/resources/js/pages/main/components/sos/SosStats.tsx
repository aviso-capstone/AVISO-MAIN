import { Card, CardContent } from "@/components/ui/card";
import { CalendarDays, CheckCircle2, Siren, TriangleAlert } from "lucide-react";

interface SosStatsProps {
    stats: {
        total_today: number;
        total_week: number;
        pending: number;
        resolved: number;
    };
}

export function SosStats({ stats }: SosStatsProps) {
    const cards = [
        {
            label: "SOS Today",
            value: stats.total_today,
            icon: Siren,
            iconClass: "text-destructive",
        },
        {
            label: "SOS This Week",
            value: stats.total_week,
            icon: CalendarDays,
            iconClass: "text-muted-foreground",
        },
        {
            label: "Pending",
            value: stats.pending,
            icon: TriangleAlert,
            iconClass: "text-destructive",
            cardClass: "bg-destructive/10 border-destructive/30",
        },
        {
            label: "Resolved",
            value: stats.resolved,
            icon: CheckCircle2,
            iconClass: "text-green-600",
        },
    ];

    return (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            {cards.map((card) => (
                <Card
                    key={card.label}
                    className={`shadow-sm border-border/50 ${card.cardClass ?? ""}`}
                >
                    <CardContent className="p-4">
                        <div className="flex items-center gap-2 mb-2">
                            <card.icon className={`w-4 h-4 ${card.iconClass}`} />
                            <span className="text-xs font-medium text-muted-foreground truncate">
                                {card.label}
                            </span>
                        </div>
                        <p className="text-2xl font-bold font-heading">
                            {card.value}
                        </p>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
