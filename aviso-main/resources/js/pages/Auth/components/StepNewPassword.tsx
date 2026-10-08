import { useState } from "react";
import { Eye, EyeOff, KeyRound, Lock, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Link } from "@inertiajs/react";
import { toast } from "@/lib/toast";
import { StepIndicator } from "./StepIndicator";
import axios from "axios";
import { PasswordChecker } from "@/components/ui/PasswordChecker";

interface Props {
    email: string;
    token: string;
    onSuccess?: () => void;
}

export function StepNewPassword({ email, token, onSuccess }: Props) {
    const [password, setPassword] = useState("");
    const [confirmation, setConfirmation] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const [loading, setLoading] = useState(false);
    const [errors, setErrors] = useState<{
        password?: string;
        token?: string;
    }>({});
    const [done, setDone] = useState(false);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setErrors({});

        if (password !== confirmation) {
            setErrors({ password: "Passwords do not match." });
            return;
        }

        setLoading(true);
        try {
            await axios.post(token, {
                email,
                password,
                password_confirmation: confirmation,
            });

            setDone(true);
            toast.setPending("success", "Password reset successfully. Please log in.");
            if (onSuccess) onSuccess();
        } catch (err: any) {
            const errs = err?.response?.data?.errors ?? {};
            setErrors(errs);
            if (!errs.password && !errs.token) {
                toast.error({
                    title: "Reset Failed",
                    description: "Please try again.",
                });
            }
        } finally {
            setLoading(false);
        }
    };

    // ── Success state ──────────────────────────────────────────────────────────
    if (done) {
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
                        Password Reset!
                    </CardTitle>
                    <CardDescription className="text-center">
                        Your password has been updated successfully.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col items-center gap-4 pb-6">
                    <div className="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center">
                        <CheckCircle2 className="w-8 h-8 text-primary" />
                    </div>
                    <Link href={route("login")} className="w-full">
                        <Button className="w-full">Go to Login</Button>
                    </Link>
                </CardContent>
            </>
        );
    }

    // ── Form state ─────────────────────────────────────────────────────────────
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
                    Set New Password
                </CardTitle>
                <CardDescription className="text-center">
                    Choose a strong password for your admin account.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <StepIndicator current={3} />

                {errors.token && (
                    <div className="mb-4 text-sm text-destructive bg-destructive/10 border border-destructive/20 rounded-md px-3 py-2">
                        {errors.token}{" "}
                        <Link
                            href={route("password.request")}
                            className="underline font-medium"
                        >
                            Start over
                        </Link>
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="np-password">New Password</Label>
                        <div className="relative">
                            <Lock className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <Input
                                id="np-password"
                                type={showPassword ? "text" : "password"}
                                value={password}
                                onChange={(e) => {
                                    setPassword(e.target.value);
                                    setErrors({});
                                }}
                                className={`pl-9 pr-10 ${errors.password ? "border-destructive" : ""}`}
                                placeholder="Minimum 8 characters"
                                autoFocus
                                required
                                minLength={8}
                            />
                            {password.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowPassword(!showPassword)
                                    }
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-primary transition-colors focus:outline-none"
                                >
                                    {showPassword ? (
                                        <EyeOff className="size-4" />
                                    ) : (
                                        <Eye className="size-4" />
                                    )}
                                </button>
                            )}
                        </div>
                        {password && <PasswordChecker password={password} />}
                        {errors.password && (
                            <p className="text-sm text-destructive mt-2">
                                {errors.password}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="np-confirm">Confirm Password</Label>
                        <div className="relative">
                            <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <Input
                                id="np-confirm"
                                type={showConfirm ? "text" : "password"}
                                value={confirmation}
                                onChange={(e) => {
                                    setConfirmation(e.target.value);
                                    setErrors({});
                                }}
                                className={`pl-9 pr-10 ${errors.password ? "border-destructive" : ""}`}
                                placeholder="Repeat your password"
                                required
                            />
                            {confirmation.length > 0 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowConfirm(!showConfirm)
                                    }
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-primary transition-colors focus:outline-none"
                                >
                                    {showConfirm ? (
                                        <EyeOff className="size-4" />
                                    ) : (
                                        <Eye className="size-4" />
                                    )}
                                </button>
                            )}
                        </div>
                    </div>

                    <Button
                        className="w-full mt-2"
                        type="submit"
                        disabled={loading}
                    >
                        {loading ? "Resetting…" : "Reset Password"}
                    </Button>
                </form>
            </CardContent>
        </>
    );
}
