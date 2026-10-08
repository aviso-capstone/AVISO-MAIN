import { useCallback, useEffect, useRef } from 'react';
import { toast } from '@/lib/toast';

/**
 * The admin SOS alarm: a looping Jarvis siren plus a per-rider spoken
 * announcement, both served as cached WAV from the backend TTS endpoints.
 *
 * Lifted out of MapPage so the alarm survives navigation — mount this once in a
 * persistent layout and the sound keeps playing while the admin works on any
 * page, instead of dying the moment they leave the live map.
 *
 * Chrome refuses to create a running AudioContext without a user gesture, so
 * initialisation is deferred to the first click/keypress/touch anywhere in the
 * document. An alarm requested before that is queued and fires as soon as the
 * context unlocks.
 */
export function useSosAlarm() {
    const audioCtxRef      = useRef<AudioContext | null>(null);
    const sosBufRef        = useRef<AudioBuffer | null>(null);
    const alarmSrcRef      = useRef<AudioBufferSourceNode | null>(null);
    const riderAlertSrcRef = useRef<AudioBufferSourceNode | null>(null);
    const alarmActiveRef   = useRef(false);
    const pendingAlarmRef  = useRef(false);
    const startLoopRef     = useRef<() => void>(() => {});

    useEffect(() => {
        let initing = false;

        const tryInit = async () => {
            if (audioCtxRef.current || initing) return;
            initing = true;
            try {
                const ctx = new AudioContext();

                // Race resume against 2s — prevents `initing` from staying true if
                // Chrome queues the promise (e.g. called without a real gesture).
                await Promise.race([
                    ctx.resume(),
                    new Promise<void>((_, reject) =>
                        setTimeout(() => reject(new Error('resume timeout')), 2000),
                    ),
                ]);

                if (ctx.state !== 'running') {
                    await ctx.close();
                    initing = false;
                    return;
                }

                audioCtxRef.current = ctx;

                const res = await fetch('/jarvis/sos', { credentials: 'same-origin' });
                if (res.ok) sosBufRef.current = await ctx.decodeAudioData(await res.arrayBuffer());

                if (pendingAlarmRef.current) {
                    pendingAlarmRef.current = false;
                    startLoopRef.current();
                }
            } catch {
                initing = false;
            }
        };

        toast.info({
            title: 'Click to enable audio',
            description: 'Click anywhere to activate emergency audio alerts.',
        });

        // Do NOT call tryInit() on mount — Chrome blocks AudioContext without a
        // gesture, and the pending ctx.resume() keeps initing=true, which would
        // block every later click.
        document.addEventListener('click',      tryInit);
        document.addEventListener('keydown',    tryInit);
        document.addEventListener('touchstart', tryInit);

        return () => {
            document.removeEventListener('click',      tryInit);
            document.removeEventListener('keydown',    tryInit);
            document.removeEventListener('touchstart', tryInit);
        };
    }, []);

    /** Stop any currently playing rider-name announcement. */
    const stopRiderAlert = useCallback(() => {
        try { riderAlertSrcRef.current?.stop(); } catch { /* already stopped */ }
        riderAlertSrcRef.current = null;
    }, []);

    const startAlarm = useCallback(() => {
        if (!audioCtxRef.current || !sosBufRef.current) {
            pendingAlarmRef.current = true; // retry once a gesture unlocks audio
            return;
        }
        if (alarmActiveRef.current) return;
        alarmActiveRef.current = true;

        const loop = () => {
            if (!alarmActiveRef.current || !audioCtxRef.current || !sosBufRef.current) return;
            const src = audioCtxRef.current.createBufferSource();
            src.buffer = sosBufRef.current;
            src.connect(audioCtxRef.current.destination);
            src.onended = () => {
                alarmSrcRef.current = null;
                if (alarmActiveRef.current) setTimeout(loop, 3000);
            };
            alarmSrcRef.current = src;
            src.start();
        };
        loop();
    }, []);

    // Keep the ref in sync so the deferred-unlock path and the announcement's
    // onended callback can start the loop without capturing a stale closure.
    startLoopRef.current = startAlarm;

    const stopAlarm = useCallback(() => {
        alarmActiveRef.current = false;
        pendingAlarmRef.current = false;
        stopRiderAlert();
        try { alarmSrcRef.current?.stop(); } catch { /* already stopped */ }
        alarmSrcRef.current = null;
    }, [stopRiderAlert]);

    /**
     * Speak the rider's name, then fall into the looping alarm. Falls straight
     * through to the alarm if audio is locked or the announcement fails.
     */
    const announceRider = useCallback((riderName: string) => {
        const ctx = audioCtxRef.current;
        if (!ctx) {
            startAlarm();
            return;
        }

        // Stop whatever is playing so voices never overlap.
        stopRiderAlert();
        stopAlarm();

        fetch(`/jarvis/rider-alert?name=${encodeURIComponent(riderName)}`, {
            credentials: 'same-origin',
        })
            .then(res => (res.ok ? res.arrayBuffer() : Promise.reject(res.status)))
            .then(buf => ctx.decodeAudioData(buf))
            .then(buffer => {
                // Re-check: something may have started while we were fetching.
                stopRiderAlert();

                const src = ctx.createBufferSource();
                src.buffer = buffer;
                src.connect(ctx.destination);
                riderAlertSrcRef.current = src;
                src.onended = () => {
                    riderAlertSrcRef.current = null;
                    startLoopRef.current();
                };
                src.start();
            })
            .catch(() => startLoopRef.current());
    }, [startAlarm, stopAlarm, stopRiderAlert]);

    // Never leave a detached loop running that can no longer be silenced.
    useEffect(() => () => stopAlarm(), [stopAlarm]);

    return { startAlarm, stopAlarm, announceRider };
}
