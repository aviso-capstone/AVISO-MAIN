import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { AlertCircle, Clock, Download as DownloadIcon, Smartphone } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Separator } from "@/components/ui/separator";
import AuthLayout from "@/layouts/AuthLayout";
import { cn } from "@/lib/utils";

type AvailableBuild = {
    version: string;
    build_number: string | null;
    release_notes: string;
    built_at: string;
    expires_at: string | null;
    file_size: string | null;
};

type DownloadProps = {
    build: AvailableBuild | { error: string };
};

const DAY_MS = 24 * 60 * 60 * 1000;
const WARN_BELOW_MS = 3 * DAY_MS;
const URGENT_BELOW_MS = DAY_MS;

const INSTALL_STEPS = [
    "Tap Download and wait for the file to finish.",
    "Open the downloaded file from your notifications or Downloads folder.",
    'If asked, allow your browser to "Install unknown apps".',
    "Tap Install, then open AVISO.",
];

function useCountdown(target: string | null): number | null {
    const [msLeft, setMsLeft] = useState<number | null>(() =>
        target ? new Date(target).getTime() - Date.now() : null,
    );

    useEffect(() => {
        if (!target) return;
        const end = new Date(target).getTime();
        const tick = () => setMsLeft(end - Date.now());
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [target]);

    return msLeft;
}

function formatRemaining(ms: number): string {
    const total = Math.max(0, Math.floor(ms / 1000));
    const d = Math.floor(total / 86400);
    const h = Math.floor((total % 86400) / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${d}d ${pad(h)}h ${pad(m)}m ${pad(s)}s`;
}

const dateFormat = new Intl.DateTimeFormat("en-PH", {
    dateStyle: "long",
    timeStyle: "short",
    timeZone: "Asia/Manila",
});

function Unavailable({ message }: { message: string }) {
    return (
        <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}

function BuildDetails({ build }: { build: AvailableBuild }) {
    const msLeft = useCountdown(build.expires_at);
    const expired = msLeft !== null && msLeft <= 0;
    const warning = msLeft !== null && !expired && msLeft < WARN_BELOW_MS;
    const urgent = msLeft !== null && !expired && msLeft < URGENT_BELOW_MS;

    return (
        <div className="space-y-5">
            {expired ? (
                <Unavailable message="This download has expired. A new version is coming soon." />
            ) : (
                <Button asChild size="lg" className="w-full h-12 text-base">
                    {/* Plain <a>: /download/apk redirects off-site to the APK file. */}
                    <a href={route("download.apk")}>
                        <DownloadIcon className="h-5 w-5" />
                        Download APK
                        {build.file_size && (
                            <span className="opacity-80">({build.file_size})</span>
                        )}
                    </a>
                </Button>
            )}

            {msLeft !== null && !expired && (
                <div
                    className={cn(
                        "flex items-center justify-center gap-2 rounded-lg border px-3 py-2 text-sm tabular-nums",
                        urgent
                            ? "border-destructive/40 bg-destructive/10 text-destructive"
                            : warning
                            ? "border-orange-500/40 bg-orange-500/10 text-orange-600 dark:text-orange-400"
                            : "border-border bg-muted/50 text-muted-foreground",
                    )}
                    role="timer"
                    aria-live="off"
                >
                    <Clock className="h-4 w-4 shrink-0" />
                    <span>
                        Download available for{" "}
                        <span className="font-semibold">{formatRemaining(msLeft)}</span>
                    </span>
                </div>
            )}

            <dl className="grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt className="text-muted-foreground">Version</dt>
                    <dd className="font-medium">
                        {build.version}
                        {build.build_number && (
                            <span className="text-muted-foreground"> (build {build.build_number})</span>
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Released</dt>
                    <dd className="font-medium">{dateFormat.format(new Date(build.built_at))}</dd>
                </div>
            </dl>

            <div className="space-y-1 text-sm">
                <p className="text-muted-foreground">What's new</p>
                <p className="whitespace-pre-line break-words">{build.release_notes}</p>
            </div>

            <Separator />

            <div className="space-y-2 text-sm">
                <p className="flex items-center gap-2 font-medium">
                    <Smartphone className="h-4 w-4" />
                    How to install (Android)
                </p>
                <ol className="list-decimal space-y-1 pl-5 text-muted-foreground">
                    {INSTALL_STEPS.map((step) => (
                        <li key={step}>{step}</li>
                    ))}
                </ol>
            </div>
        </div>
    );
}

export default function Download({ build }: DownloadProps) {
    return (
        <AuthLayout>
            <Head title="Download the app" />

            <Card className="w-full shadow-2xl border-border/50 bg-card/50 backdrop-blur-xl">
                <CardHeader className="flex flex-col items-center space-y-2">
                    <div className="w-24 h-24 flex items-center justify-center mx-auto mb-2">
                        <img
                            src="/logo-removebg.png"
                            alt="Aviso Logo"
                            className="w-full h-full object-contain drop-shadow-xl"
                        />
                    </div>
                    <CardTitle className="text-2xl font-heading tracking-tight text-center">
                        AVISO
                    </CardTitle>
                    <CardDescription className="text-center">
                        Road hazard alerts and crash detection for riders
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    {"error" in build ? (
                        <Unavailable message={build.error} />
                    ) : (
                        <BuildDetails build={build} />
                    )}
                </CardContent>
            </Card>
        </AuthLayout>
    );
}
