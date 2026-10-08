import { Head, Link } from "@inertiajs/react";
import { useState } from "react";
import { ArrowLeft } from "lucide-react";
import { Card, CardFooter } from "@/components/ui/card";
import AuthLayout from "@/layouts/AuthLayout";
import { StepEmail } from "./components/StepEmail";
import { StepOtp } from "./components/StepOtp";
import { StepNewPassword } from "./components/StepNewPassword";

type Step = 1 | 2 | 3;

export default function ForgotPassword() {
    const [step, setStep] = useState<Step>(1);
    const [email, setEmail] = useState("");
    const [resetToken, setResetToken] = useState("");
    const [isDone, setIsDone] = useState(false);

    return (
        <AuthLayout>
            <Head title="Reset Password" />

            <Card className="w-full shadow-2xl border-border/50 bg-card/50 backdrop-blur-xl">
                {step === 1 && (
                    <StepEmail
                        onSuccess={(e) => {
                            setEmail(e);
                            setStep(2);
                        }}
                    />
                )}

                {step === 2 && (
                    <StepOtp
                        email={email}
                        onSuccess={(token) => {
                            setResetToken(token);
                            setStep(3);
                        }}
                        onBack={() => setStep(1)}
                    />
                )}

                {step === 3 && (
                    <StepNewPassword 
                        email={email} 
                        token={resetToken} 
                        onSuccess={() => setIsDone(true)}
                    />
                )}

                {!isDone && (
                    <CardFooter className="flex justify-center border-t p-4">
                        <Link
                            href={route("login")}
                            className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                        >
                            <ArrowLeft className="size-3.5" />
                            Return to Login
                        </Link>
                    </CardFooter>
                )}
            </Card>
        </AuthLayout>
    );
}
