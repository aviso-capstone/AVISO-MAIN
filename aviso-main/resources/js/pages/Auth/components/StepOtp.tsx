import { useState, useEffect } from "react";
import { ArrowLeft, RotateCcw } from "lucide-react";
import { CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { toast } from "@/lib/toast";
import { StepIndicator } from "./StepIndicator";
import axios from "axios";

interface Props {
    email: string;
    onSuccess: (token: string) => void;
    onBack: () => void;
}

export function StepOtp({ email, onSuccess, onBack }: Props) {
    const [otp, setOtp] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    const [resendCooldown, setResendCooldown] = useState(60);
    const [resending, setResending] = useState(false);

    // Countdown timer for resend button
    useEffect(() => {
        if (resendCooldown <= 0) return;
        const t = setTimeout(() => setResendCooldown((c) => c - 1), 1000);
        return () => clearTimeout(t);
    }, [resendCooldown]);

    const submitOtp = async (code: string) => {
        setError("");
        setLoading(true);
        try {
            const { data } = await axios.post(
                route("password.verify-otp"),
                { email, otp: code },
            );
            toast.success({
                title: "Code Verified!",
                description: "Now set your new password.",
            });
            onSuccess(data.token);
        } catch (err: any) {
            const msg =
                err?.response?.data?.errors?.otp?.[0] ??
                "Invalid or expired code.";
            setError(msg);
            setOtp("");
        } finally {
            setLoading(false);
        }
    };

    const handleComplete = (code: string) => {
        submitOtp(code);
    };

    const handleResend = async () => {
        setResending(true);
        setError("");
        try {
            await axios.post(route("password.send-otp"), { email });
            setOtp("");
            setResendCooldown(60);
            toast.info({
                title: "New Code Sent",
                description: `A fresh OTP was sent to ${email}`,
            });
        } catch {
            toast.error({
                title: "Failed to resend",
                description: "Please try again.",
            });
        } finally {
            setResending(false);
        }
    };

    return (
        <>
            <CardHeader className="flex flex-col items-center pb-6 space-y-2">
                <div className="w-24 h-24 flex items-center justify-center mx-auto mb-2">
                    <img
                        src="/logo-removebg.png"
                        alt="Aviso Logo"
                        className="w-full h-full object-contain drop-shadow-xl"
                    />
                </div>
                <CardTitle className="text-2xl font-heading tracking-tight text-center">
                    Enter Verification Code
                </CardTitle>
                <CardDescription className="text-center">
                    We sent a 6-digit code to{" "}
                    <span className="font-medium text-foreground">{email}</span>
                </CardDescription>
            </CardHeader>

            <CardContent>
                <StepIndicator current={2} />

                {/* OTP boxes using your shadcn input-otp component */}
                <div className="flex justify-center mb-4">
                    <InputOTP
                        maxLength={6}
                        value={otp}
                        onChange={(value) => {
                            setOtp(value);
                            setError("");
                        }}
                        onComplete={handleComplete}
                        disabled={loading}
                        autoFocus
                    >
                        <InputOTPGroup>
                            <InputOTPSlot index={0} className={error ? "border-destructive text-destructive" : ""} />
                            <InputOTPSlot index={1} className={error ? "border-destructive text-destructive" : ""} />
                            <InputOTPSlot index={2} className={error ? "border-destructive text-destructive" : ""} />
                            <InputOTPSlot index={3} className={error ? "border-destructive text-destructive" : ""} />
                            <InputOTPSlot index={4} className={error ? "border-destructive text-destructive" : ""} />
                            <InputOTPSlot index={5} className={error ? "border-destructive text-destructive" : ""} />
                        </InputOTPGroup>
                    </InputOTP>
                </div>

                {error && (
                    <p className="text-sm text-destructive text-center mb-3">
                        {error}
                    </p>
                )}

                {loading && (
                    <p className="text-sm text-muted-foreground text-center mb-3 animate-pulse">
                        Verifying…
                    </p>
                )}

                <div className="flex items-center justify-between text-sm mt-2">
                    <button
                        type="button"
                        onClick={onBack}
                        className="flex items-center gap-1 text-muted-foreground hover:text-primary transition-colors"
                    >
                        <ArrowLeft className="size-3.5" /> Wrong email?
                    </button>

                    <button
                        type="button"
                        onClick={handleResend}
                        disabled={resendCooldown > 0 || resending}
                        className="flex items-center gap-1 text-muted-foreground hover:text-primary transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <RotateCcw className="size-3.5" />
                        {resendCooldown > 0
                            ? `Resend in ${resendCooldown}s`
                            : resending
                            ? "Sending…"
                            : "Resend Code"}
                    </button>
                </div>
            </CardContent>
        </>
    );
}
