import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { PhoneCall } from "lucide-react";
import { emergencyHotlines } from "./emergencyHotlines";

export function EmergencyHotlinesPanel() {
    return (
        <Card className="border-border/50 shadow-sm h-fit">
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <PhoneCall className="w-4 h-4 text-destructive" />
                    Emergency Hotlines
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                    Zamboanga City government contacts for SOS dispatch.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                {emergencyHotlines.map((hotline) => (
                    <div key={hotline.label}>
                        <p className="text-sm font-medium mb-1">{hotline.label}</p>
                        <div className="flex flex-col gap-1">
                            {hotline.numbers.map((number) => (
                                <a
                                    key={number}
                                    href={`tel:${number.replace(/\D/g, "")}`}
                                    className="text-sm font-mono text-muted-foreground hover:text-destructive transition-colors w-fit"
                                >
                                    {number}
                                </a>
                            ))}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
