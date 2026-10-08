import { Phone, Siren, X } from 'lucide-react';

/** Zamboanga City government contacts for SOS dispatch. Static reference data —
 * surfaced on the live map alongside (but separate from) the rider SOS panel so
 * an operator can call the right agency the moment an emergency comes in. */
const HOTLINES: { agency: string; numbers: string[] }[] = [
    { agency: 'ZCDRRMO Hotline',                 numbers: ['995-9601', '990-1171'] },
    { agency: 'Emergency Operations Center',     numbers: ['0966-731-6242', '0955-004-3682', '0928-896-6279'] },
    { agency: 'Technical Rescue / Fire Auxiliary', numbers: ['0926-091-2492'] },
    { agency: 'Emergency Medical Services',      numbers: ['926-1848'] },
    { agency: 'Zamboanga City Fire District',    numbers: ['991-2267', '0955-781-6063'] },
];

interface EmergencyHotlinesPanelProps {
    onClose: () => void;
}

export function EmergencyHotlinesPanel({ onClose }: EmergencyHotlinesPanelProps) {
    return (
        <div className="absolute bottom-8 right-3 z-10 flex max-h-[70%] w-72 flex-col rounded-xl border border-destructive/30 bg-background/98 p-4 shadow-2xl backdrop-blur">
            {/* Header */}
            <div className="mb-3 flex items-start justify-between">
                <div className="flex items-center gap-2">
                    <div className="rounded-lg bg-destructive/10 p-1.5">
                        <Siren className="h-4 w-4 text-destructive" />
                    </div>
                    <div>
                        <h3 className="text-sm font-bold leading-none tracking-tight">Emergency Hotlines</h3>
                        <p className="mt-1 text-[10px] leading-tight text-muted-foreground">
                            Zamboanga City contacts for SOS dispatch
                        </p>
                    </div>
                </div>
                <button
                    onClick={onClose}
                    aria-label="Close hotlines panel"
                    className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            </div>

            {/* Agency list */}
            <div className="space-y-2.5 overflow-y-auto pr-1">
                {HOTLINES.map((hotline) => (
                    <div key={hotline.agency} className="rounded-lg border border-border/60 bg-muted/30 p-2.5">
                        <p className="mb-1.5 text-[11px] font-semibold text-foreground">{hotline.agency}</p>
                        <div className="flex flex-wrap gap-1.5">
                            {hotline.numbers.map((num) => (
                                <a
                                    key={num}
                                    href={`tel:${num.replace(/[^0-9+]/g, '')}`}
                                    className="inline-flex items-center gap-1 rounded-md border border-border/60 bg-background px-2 py-1 font-mono text-[11px] text-foreground transition-colors hover:border-destructive/40 hover:bg-destructive/5"
                                >
                                    <Phone className="h-3 w-3 text-destructive" />
                                    {num}
                                </a>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
