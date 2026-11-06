import { useState } from 'react';
import { Camera, CheckCircle2, ChevronLeft, ChevronDown, ImageOff, MapPin, XCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { MapMarker, MarkerContent, useMap } from '@/components/ui/map';
import { getHazardColor } from '@/lib/hazards';
import { type HazardReportPayload } from '@/types/models';

interface HazardReportsPanelProps {
    reports: HazardReportPayload[];
    theme?: 'day' | 'night' | 'dusk' | 'dawn';
    onConfirm: (id: number) => Promise<void>;
    onReject: (id: number) => Promise<void>;
}

function distanceLabel(meters: number | null): string {
    if (meters == null) return 'Rider location unknown';
    if (meters < 1000) return `Reported ${meters} m away`;
    return `Reported far away – ${(meters / 1000).toFixed(1)} km`;
}

function timeLabel(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * Live Map panel for riders' "hazard no longer there" reports. The admin opens
 * a report, looks at the rider's photo of the spot, and either resolves the
 * hazard (it is gone) or keeps it (still there).
 */
export function HazardReportsPanel({ reports, theme = 'day', onConfirm, onReject }: HazardReportsPanelProps) {
    const { map } = useMap();
    const [open, setOpen] = useState(true);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [busy, setBusy] = useState<'confirm' | 'reject' | null>(null);
    const [photoOpen, setPhotoOpen] = useState(false);

    // A report reviewed elsewhere disappears from the list; fall back to the list.
    const selected = reports.find(r => r.id === selectedId) ?? null;

    if (reports.length === 0) return null;

    const openReport = (report: HazardReportPayload) => {
        setSelectedId(report.id);
        if (report.hazard) {
            map?.flyTo({ center: [report.hazard.longitude, report.hazard.latitude], zoom: 18, duration: 1200, essential: true });
        }
    };

    const act = async (action: 'confirm' | 'reject') => {
        if (!selected) return;
        setBusy(action);
        try {
            await (action === 'confirm' ? onConfirm(selected.id) : onReject(selected.id));
            setSelectedId(null);
        } catch {
            // The provider already showed the error toast; the report stays open.
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            {/* The rider's position when reporting, while a report is open. */}
            {selected?.latitude != null && selected.longitude != null && (
                <MapMarker longitude={selected.longitude} latitude={selected.latitude}>
                    <MarkerContent>
                        <div
                            className="h-3.5 w-3.5 rounded-full border-2 border-white bg-sky-500 shadow-md"
                            title={`${selected.rider_name ?? selected.rider_code} reported from here`}
                        />
                    </MarkerContent>
                </MapMarker>
            )}

            <div className="absolute top-14 right-14 z-10 w-80 max-h-[calc(100%-8rem)] overflow-y-auto rounded-xl border bg-background/98 shadow-2xl backdrop-blur">
                <button
                    type="button"
                    onClick={() => setOpen(o => !o)}
                    aria-expanded={open}
                    className="flex w-full items-center gap-2 px-4 py-3 text-left"
                >
                    <Camera className="h-4 w-4 text-amber-600" />
                    <span className="flex-1 text-sm font-semibold">Hazard reports</span>
                    <span className="rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-white tabular-nums">
                        {reports.length}
                    </span>
                    <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${open ? 'rotate-180' : ''}`} />
                </button>

                {open && !selected && (
                    <ul className="border-t">
                        {reports.map(report => (
                            <li key={report.id}>
                                <button
                                    type="button"
                                    onClick={() => openReport(report)}
                                    className="flex w-full items-start gap-3 border-b px-4 py-3 text-left last:border-b-0 hover:bg-muted/40"
                                >
                                    <span
                                        className="mt-1 h-3 w-3 shrink-0 rounded-full"
                                        style={{ backgroundColor: getHazardColor(report.hazard?.type ?? 'Pothole', theme) }}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {report.hazard?.type ?? 'Hazard'} · <span className="font-mono text-xs">{report.hazard?.haz_code}</span>
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {report.rider_name ?? report.rider_code} · {timeLabel(report.created_at)}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">{distanceLabel(report.distance_m)}</span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {open && selected && (
                    <div className="space-y-3 border-t p-4">
                        <button
                            type="button"
                            onClick={() => setSelectedId(null)}
                            className="flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                        >
                            <ChevronLeft className="h-3.5 w-3.5" /> All reports
                        </button>

                        <p className="text-sm font-semibold">Rider says this hazard is no longer there</p>

                        {selected.photo_url ? (
                            <button type="button" onClick={() => setPhotoOpen(true)} className="block w-full" title="Tap to enlarge">
                                <img
                                    src={selected.photo_url}
                                    alt={`Photo of ${selected.hazard?.haz_code ?? 'the hazard'} spot`}
                                    className="h-44 w-full rounded-lg border object-cover"
                                />
                            </button>
                        ) : (
                            <div className="flex h-24 items-center justify-center gap-2 rounded-lg border text-xs text-muted-foreground">
                                <ImageOff className="h-4 w-4" /> Photo unavailable
                            </div>
                        )}

                        <dl className="space-y-1.5 text-xs">
                            <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Rider</dt><dd className="text-right font-medium">{selected.rider_name ?? selected.rider_code}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Reported</dt><dd className="text-right">{timeLabel(selected.created_at)}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Distance</dt><dd className="text-right">{distanceLabel(selected.distance_m)}</dd></div>
                            {selected.note && (
                                <div className="rounded-md bg-muted/50 p-2 italic">“{selected.note}”</div>
                            )}
                        </dl>

                        {selected.hazard && (
                            <dl className="space-y-1.5 rounded-lg border p-3 text-xs">
                                <div className="flex items-center gap-2 pb-1 font-semibold">
                                    <MapPin className="h-3.5 w-3.5" /> {selected.hazard.type} · <span className="font-mono">{selected.hazard.haz_code}</span>
                                </div>
                                <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Area</dt><dd className="text-right">{selected.hazard.area}</dd></div>
                                <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Detected</dt><dd className="text-right">{timeLabel(selected.hazard.detected_at)}</dd></div>
                                <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Detected by</dt><dd className="text-right font-mono">{selected.hazard.rider_code}</dd></div>
                                {selected.hazard.confidence != null && (
                                    <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Confidence</dt><dd className="text-right">{selected.hazard.confidence}%</dd></div>
                                )}
                            </dl>
                        )}

                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                size="sm"
                                className="bg-green-600 text-white hover:bg-green-700"
                                disabled={busy !== null}
                                onClick={() => act('confirm')}
                            >
                                <CheckCircle2 className="h-4 w-4" />
                                {busy === 'confirm' ? 'Resolving…' : 'Confirm resolved'}
                            </Button>
                            <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => act('reject')}>
                                <XCircle className="h-4 w-4" />
                                {busy === 'reject' ? 'Closing…' : 'Still there'}
                            </Button>
                        </div>
                        <p className="text-[11px] leading-snug text-muted-foreground">
                            Confirm resolved removes the pin (and duplicate saves of the same hazard) from both maps.
                            The record stays in Hazard Logs as Resolved, with this photo.
                        </p>
                    </div>
                )}
            </div>

            <Dialog open={photoOpen && !!selected?.photo_url} onOpenChange={setPhotoOpen}>
                <DialogContent className="max-w-3xl p-2">
                    <DialogTitle className="sr-only">Report photo</DialogTitle>
                    <DialogDescription className="sr-only">
                        Photo the rider took of the hazard spot.
                    </DialogDescription>
                    {selected?.photo_url && (
                        <img src={selected.photo_url} alt="Rider's photo of the hazard spot" className="max-h-[80vh] w-full rounded-md object-contain" />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
