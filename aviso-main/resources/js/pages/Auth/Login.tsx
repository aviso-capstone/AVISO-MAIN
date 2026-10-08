import { Head, Link, useForm } from "@inertiajs/react";
import { FormEventHandler, useState } from "react";
import { toast } from "@/lib/toast";
import { Eye, EyeOff, CheckCircle2 } from "lucide-react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import AuthLayout from "@/layouts/AuthLayout";

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword?: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        username: "",
        password: "",
        remember: false,
    });

    const [showPassword, setShowPassword] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route("login"), {
            onFinish: () => reset("password"),
            onError: (err) => {
                toast.error({
                    title: "Login Failed",
                    description: Object.values(err)[0] as string || "Invalid credentials."
                });
            }
        });
    };

    return (
        <AuthLayout>
            <Head title="Log in" />

            <Card className="w-full shadow-2xl border-border/50 bg-card/50 backdrop-blur-xl">
                <CardHeader className="flex flex-col items-center pb-8 space-y-2">
                    <div className="w-32 h-32 flex items-center justify-center mx-auto mb-6">
                        <img
                            src="/logo-removebg.png"
                            alt="Aviso Logo"
                            className="w-full h-full object-contain drop-shadow-xl"
                        />
                    </div>
                    <CardTitle className="text-2xl font-heading tracking-tight text-center">
                        Aviso Admin
                    </CardTitle>
                    <CardDescription className="text-center">
                        Enter your credentials to access the Road Hazard
                        Dashboard
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    {status && (
                        <Alert className="mb-4 border-primary/30 bg-primary/10 text-primary [&>svg]:text-primary">
                            <CheckCircle2 className="h-4 w-4" />
                            <AlertDescription>{status}</AlertDescription>
                        </Alert>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                type="text"
                                name="username"
                                value={data.username}
                                onChange={(e) =>
                                    setData("username", e.target.value)
                                }
                                className={
                                    errors.username ? "border-destructive" : ""
                                }
                                autoFocus
                                required
                            />
                            {errors.username && (
                                <p className="text-sm text-destructive">
                                    {errors.username}
                                </p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">Password</Label>
                            <div className="relative">
                                <Input
                                    id="password"
                                    type={showPassword ? "text" : "password"}
                                    name="password"
                                    value={data.password}
                                    onChange={(e) =>
                                        setData("password", e.target.value)
                                    }
                                    className={`pr-10 ${errors.password ? "border-destructive" : ""}`}
                                    required
                                />
                                {data.password.length > 0 && (
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
                            {errors.password && (
                                <p className="text-sm text-destructive">
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <div className="flex items-center justify-between pt-4 pb-2">
                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    checked={data.remember}
                                    onCheckedChange={(checked) =>
                                        setData("remember", checked === true)
                                    }
                                    className="h-5 w-5 border-2 border-primary/50 data-[state=checked]:border-primary data-[state=checked]:bg-primary"
                                />
                                <Label
                                    htmlFor="remember"
                                    className="text-sm font-medium leading-none cursor-pointer select-none"
                                >
                                    Remember me
                                </Label>
                            </div>
                            
                            {canResetPassword && (
                                <Link
                                    href={route("password.request")}
                                    className="text-sm font-medium text-primary hover:underline"
                                >
                                    Forgot password?
                                </Link>
                            )}
                        </div>

                        <Button
                            className="w-full mt-4"
                            type="submit"
                            disabled={processing}
                        >
                            Log in
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </AuthLayout>
    );
}
