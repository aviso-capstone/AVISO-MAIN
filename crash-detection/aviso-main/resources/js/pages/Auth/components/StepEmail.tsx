import { useState } from "react";
import { Mail } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { toast } from "@/lib/toast";
import { StepIndicator } from "./StepIndicator";
import axios from "axios";

interface Props {
    onSuccess: (email: string) => void;
}

export function StepEmail({ onSuccess }: Props) {
    const [email, setEmail] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setError("");
        setLoading(true);
        try {
            await axios.post(route("password.send-otp"), { email });
            toast.success({
                title: "OTP Sent!",
                description: `A 6-digit code was sent to ${email}`,
            });
            onSuccess(email);
        } catch (err: any) {
            const msg =
                err?.response?.data?.errors?.email?.[0] ??
                "Something went wrong. Please try again.";
            setError(msg);
        } finally {
            setLoading(false);
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
                    Reset Password
                </CardTitle>
                <CardDescription className="text-center">
                    Enter your admin email address and we'll send you a
                    6-digit verification code.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <StepIndicator current={1} />

                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="fp-email">Email Address</Label>
                        <div className="relative">
                            <Mail className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                            <Input
                                id="fp-email"
                                type="email"
                                value={email}
                                onChange={(e) => {
                                    setEmail(e.target.value);
                                    setError("");
                                }}
                                className={`pl-9 ${error ? "border-destructive" : ""}`}
                                placeholder="admin@aviso.com"
                                autoFocus
                                required
                            />
                        </div>
                        {error && (
                            <p className="text-sm text-destructive">{error}</p>
                        )}
                    </div>

                    <Button
                        className="w-full mt-2"
                        type="submit"
                        disabled={loading}
                    >
                        {loading ? "Sending Code…" : "Send Verification Code"}
                    </Button>
                </form>
            </CardContent>
        </>
    );
}
