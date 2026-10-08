import { CheckCircle2 } from "lucide-react";

type Step = 1 | 2 | 3;

const STEPS = [
    { n: 1 as Step, label: "Email" },
    { n: 2 as Step, label: "Verify" },
    { n: 3 as Step, label: "Reset" },
];

interface Props {
    current: Step;
}

export function StepIndicator({ current }: Props) {
    return (
        <div className="flex items-center justify-center gap-2 mb-6">
            {STEPS.map((s, i) => (
                <div key={s.n} className="flex items-center gap-2">
                    <div className="flex flex-col items-center gap-1">
                        <div
                            className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-300 ${
                                current > s.n
                                    ? "bg-primary text-primary-foreground"
                                    : current === s.n
                                    ? "bg-primary text-primary-foreground ring-4 ring-primary/20"
                                    : "bg-muted text-muted-foreground"
                            }`}
                        >
                            {current > s.n ? (
                                <CheckCircle2 className="w-4 h-4" />
                            ) : (
                                s.n
                            )}
                        </div>
                        <span
                            className={`text-[10px] font-medium ${current >= s.n ? "text-primary" : "text-muted-foreground"}`}
                        >
                            {s.label}
                        </span>
                    </div>
                    {i < STEPS.length - 1 && (
                        <div
                            className={`w-10 h-0.5 mb-4 rounded-full transition-all duration-500 ${
                                current > s.n ? "bg-primary" : "bg-border"
                            }`}
                        />
                    )}
                </div>
            ))}
        </div>
    );
}
