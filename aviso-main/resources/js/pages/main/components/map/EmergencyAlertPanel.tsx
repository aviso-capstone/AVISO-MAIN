import { useState, useEffect, useRef } from 'react';
import { X, AlertTriangle, MapPin, MapPinned, CheckCircle2, Hand, Siren, Truck, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { type EmergencyAlert } from './riderData';
import { type SosDispatchFields, type SosTriggerType } from '@/types/models';
import { DispatchDialog } from '../sos/DispatchDialog';
import { ResolveDialog } from '../sos/ResolveDialog';
import {
    etaLabel,
    isEtaOverdue,
    minutesBetween,
    type DispatchFormData,
    type ResolveFormData,
} from '../sos/dispatchOptions';
import { getRiderThemeColor } from './riderUtils';
import { getHazardTailwindColors } from '@/lib/hazards';
import { reverseGeocode } from '@/lib/geocoding';

/** How each kind of SOS is labelled. A manual SOS is a real emergency too: the
 * rider held the button and let the countdown run, so it is never shown as "no crash". */
const TRIGGER_INFO: Record<SosTriggerType, { label: string; detail: string; tone: 'manual' | 'crash' }> = {
    manual: {
        label: 'SOS — reported by rider',
        detail: 'The rider pressed SOS. Treat it as a real emergency.',
        tone: 'manual',
    },
    crash_iot: {
        label: 'Crash detected — IoT unit',
        detail: 'Impact and fall detected by the device.',
        tone: 'crash',
    },
    crash_phone: {
        label: 'Crash detected — phone sensor',
        detail: 'Impact detected by the phone (IoT unit not connected).',
        tone: 'crash',
    },
};

interface EmergencyAlertPanelProps {
    emergency: EmergencyAlert;
    theme: 'day' | 'night' | 'dusk' | 'dawn';
    onClose: () => void;
    /** Each step rejects on failure so its dialog can show the reason. */
    onDispatch: (data: DispatchFormData) => Promise<void>;
    onOnScene: () => Promise<void>;
    onResolve: (data: ResolveFormData) => Promise<void>;
    /** Set when viewing a past record from SOS Alerts history rather than a
     * live in-progress emergency — softens the "active emergency" framing
     * (no pulsing live indicator, no "Current Position" wording) since a
     * resolved/historical incident isn't something currently unfolding. */
    isHistorical?: boolean;
}

export function EmergencyAlertPanel({
    emergency,
    theme,
    onClose,
    onDispatch,
    onOnScene,
    onResolve,
    isHistorical = false,
}: EmergencyAlertPanelProps) {
    const riderColor = getRiderThemeColor(emergency.colorBase, theme);
    const isResolved = emergency.status === 'resolved';
    const isDispatched = emergency.status === 'acknowledged';
    const dispatch = emergency.dispatch;
    const [dispatchOpen, setDispatchOpen] = useState(false);
    const [resolveOpen, setResolveOpen] = useState(false);
    const [onSceneSaving, setOnSceneSaving] = useState(false);

    // Re-check the ETA every 30 s so the overdue warning appears on its own.
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!isDispatched || dispatch.on_scene_at) return;
        const timer = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(timer);
    }, [isDispatched, dispatch.on_scene_at]);
    const overdue = isDispatched && isEtaOverdue(dispatch.dispatched_at, dispatch.eta_minutes, dispatch.on_scene_at, now);

    const handleOnScene = async () => {
        setOnSceneSaving(true);
        try {
            await onOnScene();
        } catch {
            // The caller already showed the error.
        } finally {
            setOnSceneSaving(false);
        }
    };
    const hazard = emergency.hazardBefore;
    const trigger = emergency.triggerType ? TRIGGER_INFO[emergency.triggerType] : null;
    const lng = Number(emergency.coords[0]);
    const lat = Number(emergency.coords[1]);
    const [resolvedArea, setResolvedArea] = useState<string | null>(null);

    useEffect(() => {
        setResolvedArea(null);
        reverseGeocode(lng, lat)
            .then(area => setResolvedArea(area))
            .catch(() => setResolvedArea('Unknown'));
    }, [lng, lat]);

    const [elapsed, setElapsed] = useState('');
    const triggeredDate = new Date(emergency.triggeredAt);
    const triggeredTime = triggeredDate.toLocaleTimeString('en-PH', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });

    const elapsedTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    useEffect(() => {
        const update = () => {
            const secs = Math.floor((Date.now() - triggeredDate.getTime()) / 1000);
            if (secs < 60)        setElapsed(`${secs}s ago`);
            else if (secs < 3600) setElapsed(`${Math.floor(secs / 60)}m ago`);
            else if (secs < 86400) setElapsed(`${Math.floor(secs / 3600)}h ago`);
            else                  setElapsed(`${Math.floor(secs / 86400)}d ago`);
        };
        update();
        elapsedTimerRef.current = setInterval(update, 10_000);
        return () => { if (elapsedTimerRef.current) clearInterval(elapsedTimerRef.current); };
    }, [emergency.triggeredAt]);

    return (
        // Height capped to the map (minus the toolbar row above and the gap below) so the
        // rider name and status at the top are never pushed out of view; the rest scrolls.
        <div className={`absolute bottom-8 left-3 z-10 max-h-[calc(100%-6.5rem)] overflow-y-auto bg-background/98 backdrop-blur shadow-2xl border rounded-xl p-4 w-80 transition-all ${
            !isHistorical && !isResolved ? 'border-destructive/30' : 'border-border/60'
        }`}>
            {/* Header */}
            <div className="flex justify-between items-start mb-3">
                <div className="flex items-center gap-2">
                    <div
                        className="w-3 h-3 rounded-full shadow-sm flex-shrink-0"
                        style={{ backgroundColor: riderColor }}
                    />
                    <h3 className="font-bold text-sm tracking-tight">{emergency.riderName}</h3>
                </div>
                <button
                    onClick={onClose}
                    aria-label="Close emergency panel"
                    className="text-muted-foreground hover:text-foreground hover:bg-muted p-1 rounded-md transition-colors"
                >
                    <X className="w-3.5 h-3.5" />
                </button>
            </div>

            {/* SOS Status */}
            <div className={`flex items-center justify-between mb-3 p-2.5 rounded-lg border ${
                isResolved
                    ? 'bg-green-500/10 border-green-500/20'
                    : isDispatched
                        ? 'bg-amber-500/10 border-amber-500/30'
                        : 'bg-destructive/10 border-destructive/20'
            }`}>
                <div className="flex items-center gap-2">
                    {isResolved ? (
                        <CheckCircle2 className="h-3 w-3 text-green-600" />
                    ) : isDispatched ? (
                        <Truck className="h-3.5 w-3.5 text-amber-600" />
                    ) : isHistorical ? (
                        <AlertTriangle className="h-3 w-3 text-destructive" />
                    ) : (
                        <span className="relative flex h-2.5 w-2.5">
                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-destructive opacity-75" />
                            <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-destructive" />
                        </span>
                    )}
                    <span className={`text-xs font-bold uppercase tracking-wide ${
                        isResolved ? 'text-green-600' : isDispatched ? 'text-amber-600' : 'text-destructive'
                    }`}>
                        {isResolved
                            ? 'Resolved'
                            : isDispatched
                                ? (dispatch.on_scene_at ? 'Responders on scene' : 'Help on the way')
                                : isHistorical ? 'Pending Response' : 'Active Emergency'}
                    </span>
                </div>
                <div className="text-right">
                    <div className="text-[10px] text-muted-foreground font-mono">{triggeredTime}</div>
                    <div className="text-[9px] text-muted-foreground/70">{elapsed}</div>
                </div>
            </div>

            {/* How the SOS started */}
            {trigger ? (
                <div className={`flex items-start gap-2 mb-3 p-2.5 rounded-lg border ${
                    trigger.tone === 'manual'
                        ? 'bg-orange-500/10 border-orange-500/30'
                        : 'bg-destructive/10 border-destructive/20'
                }`}>
                    {trigger.tone === 'manual' ? (
                        <Hand className="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-orange-600" />
                    ) : (
                        <Siren className="w-3.5 h-3.5 mt-0.5 flex-shrink-0 text-destructive" />
                    )}
                    <div>
                        <div className={`text-xs font-bold ${trigger.tone === 'manual' ? 'text-orange-600' : 'text-destructive'}`}>
                            {trigger.label}
                        </div>
                        <div className="text-[11px] text-muted-foreground">{trigger.detail}</div>
                    </div>
                </div>
            ) : (
                <div className="mb-3 p-2.5 rounded-lg border border-border/60 bg-muted/30 text-[11px] text-muted-foreground">
                    How this SOS started was not recorded (older alert).
                </div>
            )}

            {/* Location */}
            <div className="border border-border/60 rounded-lg p-3 mb-3 bg-muted/30">
                <div className="flex items-center gap-1.5 mb-2">
                    <MapPin className="w-3.5 h-3.5 text-destructive" />
                    <span className="text-[10px] font-bold uppercase tracking-wider text-destructive">
                        {isHistorical ? 'Incident Location' : 'Current Position'}
                    </span>
                </div>
                <div className="space-y-1 text-[11px] text-muted-foreground">
                    <div className="flex justify-between">
                        <span>Latitude:</span>
                        <span className="font-mono text-foreground">{lat.toFixed(6)}°</span>
                    </div>
                    <div className="flex justify-between">
                        <span>Longitude:</span>
                        <span className="font-mono text-foreground">{lng.toFixed(6)}°</span>
                    </div>
                    <div className="flex justify-between items-center">
                        <span>Area:</span>
                        {resolvedArea !== null ? (
                            <span className="text-foreground font-medium">{resolvedArea}</span>
                        ) : (
                            <span className="text-muted-foreground/60 animate-pulse text-[10px]">Resolving…</span>
                        )}
                    </div>
                </div>
            </div>

            {/* Rider Info */}
            <div className="space-y-1.5 text-sm text-muted-foreground mb-3">
                <strong className="text-foreground block text-xs uppercase tracking-wider mb-2">Assigned Rider</strong>
                <div className="flex justify-between">
                    <span>Name:</span>
                    <span className="font-medium text-foreground">{emergency.userInfo.fullName}</span>
                </div>
                <div className="flex justify-between">
                    <span>Username:</span>
                    <span className="font-mono text-xs">{emergency.userInfo.username}</span>
                </div>
                <div className="flex justify-between">
                    <span>Contact:</span>
                    <span>{emergency.userInfo.contact}</span>
                </div>
                <div className="flex justify-between">
                    <span>Address:</span>
                    <span className="truncate max-w-[160px] text-right" title={emergency.userInfo.address}>
                        {emergency.userInfo.address}
                    </span>
                </div>
            </div>

            {/* Hazard this rider detected just before the SOS (same rider, ≤ 2 min, ≤ 100 m) */}
            {hazard ? (
                <div className="border border-border/60 rounded-lg p-3 mb-3 bg-muted/30">
                    <div className="flex items-center gap-1.5 mb-2">
                        <AlertTriangle className="w-3.5 h-3.5 text-destructive" />
                        <span className="text-[10px] font-bold uppercase tracking-wider text-destructive">
                            Hazard detected just before the SOS
                        </span>
                    </div>
                    <div className="flex items-center justify-between mb-2">
                        <Badge
                            variant="outline"
                            className={`text-[10px] py-0.5 px-2 ${getHazardTailwindColors(hazard.type)}`}
                        >
                            {hazard.type}
                        </Badge>
                        <span className="font-mono text-[10px] text-muted-foreground">{hazard.haz_code}</span>
                    </div>
                    <div className="space-y-1 text-[11px] text-muted-foreground">
                        <div className="flex justify-between">
                            <span>Confidence:</span>
                            <span className="text-foreground font-medium">{hazard.confidence}%</span>
                        </div>
                        <div className="flex justify-between">
                            <span>From SOS spot:</span>
                            <span className="text-foreground font-medium">{hazard.meters_from_sos} m</span>
                        </div>
                        {hazard.detected_at && (
                            <div className="flex justify-between">
                                <span>Detected:</span>
                                <span className="text-foreground font-medium">
                                    {new Date(hazard.detected_at).toLocaleTimeString('en-PH', {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                        second: '2-digit',
                                    })}
                                    {' '}
                                    ({Math.max(0, Math.round((triggeredDate.getTime() - new Date(hazard.detected_at).getTime()) / 1000))} s before)
                                </span>
                            </div>
                        )}
                    </div>
                </div>
            ) : (
                <div className="border border-border/60 rounded-lg p-3 mb-3 bg-muted/30 text-xs text-muted-foreground text-center">
                    No hazard detected right before the SOS.
                </div>
            )}

            {/* Response timeline: triggered → dispatched → on scene → resolved */}
            <ResponseTimeline triggeredAt={emergency.triggeredAt} dispatch={dispatch} />

            {overdue && (
                <div
                    role="alert"
                    className="flex items-start gap-2 mb-3 p-2.5 rounded-lg border border-amber-500/40 bg-amber-500/15 text-[11px] text-amber-700 dark:text-amber-400"
                >
                    <AlertTriangle className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
                    <span>
                        <strong>ETA passed</strong> — call {dispatch.agency_label ?? 'the agency'} to check if the responders arrived.
                    </span>
                </div>
            )}

            {/* Checklist actions */}
            {isResolved ? (
                <div className="w-full flex items-center justify-center gap-1.5 py-2 rounded-md bg-green-500/10 text-green-600 text-sm font-medium">
                    <CheckCircle2 className="w-4 h-4" />
                    Already Resolved
                </div>
            ) : (
                <div className="space-y-2">
                    {!isDispatched && (
                        <Button
                            size="sm"
                            className="w-full gap-1.5 bg-amber-600 text-white hover:bg-amber-700"
                            onClick={() => setDispatchOpen(true)}
                        >
                            <Truck className="w-4 h-4" />
                            Mark help dispatched
                        </Button>
                    )}
                    {isDispatched && !dispatch.on_scene_at && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="w-full gap-1.5 border-amber-500/50 text-amber-700 dark:text-amber-400"
                            onClick={handleOnScene}
                            disabled={onSceneSaving}
                        >
                            {onSceneSaving ? <Loader2 className="w-4 h-4 animate-spin" /> : <MapPinned className="w-4 h-4" />}
                            Responders on scene
                        </Button>
                    )}
                    <Button
                        variant={isDispatched ? 'default' : 'outline'}
                        size="sm"
                        className={`w-full gap-1.5 ${isDispatched ? 'bg-green-600 text-white hover:bg-green-700' : ''}`}
                        onClick={() => setResolveOpen(true)}
                    >
                        <CheckCircle2 className="w-4 h-4" />
                        Resolve emergency
                    </Button>
                </div>
            )}

            <DispatchDialog
                open={dispatchOpen}
                onOpenChange={setDispatchOpen}
                riderName={emergency.riderName}
                onSubmit={onDispatch}
            />
            <ResolveDialog
                open={resolveOpen}
                onOpenChange={setResolveOpen}
                riderName={emergency.riderName}
                onSubmit={onResolve}
            />
        </div>
    );
}

const timeOf = (iso: string) =>
    new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true });

/** The dispatch checklist as a short vertical timeline. */
function ResponseTimeline({ triggeredAt, dispatch }: { triggeredAt: string; dispatch: SosDispatchFields }) {
    const responder = [dispatch.responder_first_name, dispatch.responder_last_name].filter(Boolean).join(' ');
    const responseTime = minutesBetween(triggeredAt, dispatch.on_scene_at);

    const steps: { done: boolean; title: string; time: string | null; detail?: string }[] = [
        { done: true, title: 'SOS received', time: timeOf(triggeredAt) },
        {
            done: !!dispatch.dispatched_at,
            title: dispatch.agency_label ? `Dispatched to ${dispatch.agency_label}` : 'Help dispatched',
            time: dispatch.dispatched_at ? timeOf(dispatch.dispatched_at) : null,
            detail: dispatch.dispatched_at
                ? [
                    `ETA ${etaLabel(dispatch.eta_minutes)}`,
                    responder || null,
                    dispatch.handled_by_name ? `by ${dispatch.handled_by_name}` : null,
                ].filter(Boolean).join(' · ')
                : undefined,
        },
        {
            done: !!dispatch.on_scene_at,
            title: 'Responders on scene',
            time: dispatch.on_scene_at ? timeOf(dispatch.on_scene_at) : null,
            detail: responseTime ? `${responseTime} after the SOS` : undefined,
        },
        {
            done: !!dispatch.resolved_at,
            title: dispatch.outcome_label ? `Resolved — ${dispatch.outcome_label}` : 'Resolved',
            time: dispatch.resolved_at ? timeOf(dispatch.resolved_at) : null,
            detail: dispatch.resolution_note ?? undefined,
        },
    ];

    return (
        <div className="border border-border/60 rounded-lg p-3 mb-3 bg-muted/30">
            <strong className="text-foreground block text-[10px] uppercase tracking-wider mb-2">Response</strong>
            <ol className="space-y-2">
                {steps.map(step => (
                    <li key={step.title} className="flex gap-2 text-[11px]">
                        <span
                            className={`mt-1 h-2 w-2 flex-shrink-0 rounded-full ${
                                step.done ? 'bg-green-600' : 'border border-muted-foreground/50'
                            }`}
                            aria-hidden
                        />
                        <div className="min-w-0 flex-1">
                            <div className="flex justify-between gap-2">
                                <span className={step.done ? 'font-medium text-foreground' : 'text-muted-foreground'}>
                                    {step.title}
                                </span>
                                {step.time && <span className="font-mono text-muted-foreground">{step.time}</span>}
                            </div>
                            {step.detail && <div className="text-muted-foreground break-words">{step.detail}</div>}
                        </div>
                    </li>
                ))}
            </ol>
        </div>
    );
}
