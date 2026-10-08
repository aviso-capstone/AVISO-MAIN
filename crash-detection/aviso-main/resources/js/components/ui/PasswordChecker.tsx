import { CheckCircle2, Circle } from "lucide-react";

interface PasswordCheckerProps {
    password: string;
}

export function PasswordChecker({ password }: PasswordCheckerProps) {
    const rules = [
        {
            label: "At least 8 characters",
            met: password.length >= 8,
        },
        {
            label: "At least one uppercase letter",
            met: /[A-Z]/.test(password),
        },
        {
            label: "At least one lowercase letter",
            met: /[a-z]/.test(password),
        },
    ];

    return (
        <div className="flex flex-col gap-1.5 mt-2">
            {rules.map((rule, i) => (
                <div
                    key={i}
                    className={`flex items-center text-xs transition-colors duration-200 ${
                        rule.met
                            ? "text-green-600 dark:text-green-500"
                            : "text-muted-foreground"
                    }`}
                >
                    {rule.met ? (
                        <CheckCircle2 className="w-3.5 h-3.5 mr-2" />
                    ) : (
                        <Circle className="w-3.5 h-3.5 mr-2 opacity-50" />
                    )}
                    {rule.label}
                </div>
            ))}
        </div>
    );
}
