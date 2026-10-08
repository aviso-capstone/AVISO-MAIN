import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, type ReactNode } from 'react';
import { useTheme } from 'next-themes';
import AdminLayout from '@/layouts/AdminLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { User, SystemSetting } from '@/types/models';
import { Sun, Moon, Monitor, User as UserIcon, Shield, Settings2, AlertTriangle } from 'lucide-react';
import { AddressFields, type AddressValue } from '@/pages/main/components/users/AddressFields';
import { sanitizeText, sanitizeAlphanumeric } from '@/lib/sanitize';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

interface SettingsProps {
    systemSettings: SystemSetting;
}

const ALL_HAZARD_TYPES = [
    { value: 'Pothole',               label: 'Pothole',               emergency: true  },
    { value: 'Road Excavation',       label: 'Road Excavation',       emergency: true  },
    { value: 'Road Barrier',          label: 'Road Barrier',          emergency: true  },
];

const MAP_THEMES = [
    { value: 'day',   label: 'Day'   },
    { value: 'night', label: 'Night' },
    { value: 'dusk',  label: 'Dusk'  },
    { value: 'dawn',  label: 'Dawn'  },
] as const;

const SORT_OPTIONS = [
    { value: 'detected_at', label: 'Detection Time' },
    { value: 'confidence',  label: 'Confidence'     },
    { value: 'distance',    label: 'Distance'       },
    { value: 'type',        label: 'Type'           },
    { value: 'area',        label: 'Area'           },
    { value: 'haz_code',    label: 'Hazard Code'    },
];

export default function Settings({ systemSettings }: SettingsProps) {
    const { props } = usePage<{ auth: { user: User } }>();
    const user = props.auth.user;
    const { resolvedTheme, setTheme } = useTheme();

    // ── Profile form ────────────────────────────────────────────
    const profileForm = useForm({
        first_name:     user.first_name ?? '',
        middle_name:    user.middle_name ?? '',
        last_name:      user.last_name ?? '',
        username:       user.username ?? '',
        email:          user.email ?? '',
        contact_number: user.contact_number ?? '',
        street:         user.street ?? '',
        barangay_id:    user.barangay_id ?? '',
        city_id:        user.city_id ?? '',
        province_id:    user.province_id ?? '',
        region_id:      user.region_id ?? '',
    });

    const submitProfile = (e: FormEvent) => {
        e.preventDefault();
        profileForm.post(route('settings.profile'), {
            preserveScroll: true,
            onSuccess: () => toast.success('Profile updated successfully'),
            onError: () => toast.error({ title: 'Failed to save profile', description: 'Please check the form for errors.' }),
        });
    };

    // ── Password form ───────────────────────────────────────────
    const passwordForm = useForm({
        current_password: '',
        password:         '',
        password_confirmation: '',
    });

    const submitPassword = (e: FormEvent) => {
        e.preventDefault();
        passwordForm.post(route('settings.password'), {
            preserveScroll: true,
            onSuccess: () => {
                passwordForm.reset();
                toast.success('Password changed successfully');
            },
            onError: () => toast.error({ title: 'Failed to change password', description: 'Check your current password and try again.' }),
        });
    };

    // ── System settings form ────────────────────────────────────
    const systemForm = useForm({
        confidence_threshold:   systemSettings.confidence_threshold,
        items_per_page:         systemSettings.items_per_page,
        default_sort:           systemSettings.default_sort,
        emergency_hazard_types: systemSettings.emergency_hazard_types,
    });

    const toggleEmergencyType = (type: string) => {
        const current = systemForm.data.emergency_hazard_types;
        const updated = current.includes(type)
            ? current.filter(t => t !== type)
            : [...current, type];
        systemForm.setData('emergency_hazard_types', updated);
    };

    const submitSystem = (e: FormEvent) => {
        e.preventDefault();
        systemForm.post(route('settings.system'), {
            preserveScroll: true,
            onSuccess: () => toast.success('Settings saved successfully'),
            onError: () => toast.error('Failed to save settings'),
        });
    };

    // ── Map theme (localStorage) ────────────────────────────────
    const savedMapTheme = (localStorage.getItem('aviso_map_theme') ?? 'day') as typeof MAP_THEMES[number]['value'];

    const handleMapTheme = (val: typeof MAP_THEMES[number]['value']) => {
        localStorage.setItem('aviso_map_theme', val);
    };

    return (
        <>
            <Head title="Settings" />

            <div className="mb-6">
                <h1 className="text-3xl font-heading font-bold tracking-tight">Settings</h1>
                <p className="text-muted-foreground mt-1">Manage your profile, appearance, and system configuration.</p>
            </div>

            <Tabs defaultValue="profile" className="space-y-6">
                <TabsList className="grid grid-cols-4 w-full max-w-2xl">
                    <TabsTrigger value="profile" className="gap-2">
                        <UserIcon className="w-4 h-4" /> Profile
                    </TabsTrigger>
                    <TabsTrigger value="appearance" className="gap-2">
                        <Sun className="w-4 h-4" /> Appearance
                    </TabsTrigger>
                    <TabsTrigger value="hazards" className="gap-2">
                        <Settings2 className="w-4 h-4" /> Hazard Log
                    </TabsTrigger>
                    <TabsTrigger value="emergency" className="gap-2">
                        <AlertTriangle className="w-4 h-4" /> Emergency
                    </TabsTrigger>
                </TabsList>

                {/* ── PROFILE TAB ─────────────────────────────────────── */}
                <TabsContent value="profile" className="space-y-6">

                    {/* Profile info */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Profile Information</CardTitle>
                            <CardDescription>Update your personal details and contact information.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitProfile} className="space-y-4">
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="first_name">First Name</Label>
                                        <Input
                                            id="first_name"
                                            value={profileForm.data.first_name}
                                            onChange={e => profileForm.setData('first_name', e.target.value)}
                                            onBlur={e => profileForm.setData('first_name', sanitizeText(e.target.value))}
                                        />
                                        {profileForm.errors.first_name && (
                                            <p className="text-xs text-destructive">{profileForm.errors.first_name}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="middle_name">Middle Name</Label>
                                        <Input
                                            id="middle_name"
                                            value={profileForm.data.middle_name}
                                            onChange={e => profileForm.setData('middle_name', e.target.value)}
                                            onBlur={e => profileForm.setData('middle_name', sanitizeText(e.target.value))}
                                            placeholder="Optional"
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="last_name">Last Name</Label>
                                        <Input
                                            id="last_name"
                                            value={profileForm.data.last_name}
                                            onChange={e => profileForm.setData('last_name', e.target.value)}
                                            onBlur={e => profileForm.setData('last_name', sanitizeText(e.target.value))}
                                        />
                                        {profileForm.errors.last_name && (
                                            <p className="text-xs text-destructive">{profileForm.errors.last_name}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="username">Username</Label>
                                        <Input
                                            id="username"
                                            value={profileForm.data.username}
                                            onChange={e => profileForm.setData('username', sanitizeAlphanumeric(e.target.value))}
                                        />
                                        {profileForm.errors.username && (
                                            <p className="text-xs text-destructive">{profileForm.errors.username}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={profileForm.data.email}
                                            onChange={e => profileForm.setData('email', e.target.value)}
                                        />
                                        {profileForm.errors.email && (
                                            <p className="text-xs text-destructive">{profileForm.errors.email}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <Label htmlFor="contact_number">Contact Number</Label>
                                    <Input
                                        id="contact_number"
                                        value={profileForm.data.contact_number}
                                        onChange={e => profileForm.setData('contact_number', e.target.value)}
                                        onBlur={e => profileForm.setData('contact_number', sanitizeText(e.target.value))}
                                        placeholder="e.g. 09171234567"
                                    />
                                </div>

                                <AddressFields
                                    value={{
                                        street:      profileForm.data.street,
                                        province_id: profileForm.data.province_id,
                                        city_id:     profileForm.data.city_id,
                                        barangay_id: profileForm.data.barangay_id,
                                        region_id:   profileForm.data.region_id,
                                    }}
                                    onChange={(field, val) => profileForm.setData(field as any, val)}
                                    errors={profileForm.errors}
                                />

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={profileForm.processing}>
                                        {profileForm.processing ? 'Saving…' : 'Save Profile'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Change password */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Shield className="w-4 h-4" /> Change Password
                            </CardTitle>
                            <CardDescription>Use a strong password with uppercase, lowercase, and numbers (min. 8 characters).</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitPassword} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="current_password">Current Password</Label>
                                    <Input
                                        id="current_password"
                                        type="password"
                                        value={passwordForm.data.current_password}
                                        onChange={e => passwordForm.setData('current_password', e.target.value)}
                                        autoComplete="current-password"
                                    />
                                    {passwordForm.errors.current_password && (
                                        <p className="text-xs text-destructive">{passwordForm.errors.current_password}</p>
                                    )}
                                </div>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="new_password">New Password</Label>
                                        <Input
                                            id="new_password"
                                            type="password"
                                            value={passwordForm.data.password}
                                            onChange={e => passwordForm.setData('password', e.target.value)}
                                            autoComplete="new-password"
                                        />
                                        {passwordForm.errors.password && (
                                            <p className="text-xs text-destructive">{passwordForm.errors.password}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="password_confirmation">Confirm New Password</Label>
                                        <Input
                                            id="password_confirmation"
                                            type="password"
                                            value={passwordForm.data.password_confirmation}
                                            onChange={e => passwordForm.setData('password_confirmation', e.target.value)}
                                            autoComplete="new-password"
                                        />
                                    </div>
                                </div>
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={passwordForm.processing}>
                                        {passwordForm.processing ? 'Updating…' : 'Update Password'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </TabsContent>

                {/* ── APPEARANCE TAB ──────────────────────────────────── */}
                <TabsContent value="appearance" className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Theme</CardTitle>
                            <CardDescription>Choose how the admin portal looks. Preference is saved in your browser.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-3 gap-3 max-w-sm">
                                {[
                                    { value: 'light',  label: 'Light',  Icon: Sun     },
                                    { value: 'dark',   label: 'Dark',   Icon: Moon    },
                                    { value: 'system', label: 'System', Icon: Monitor },
                                ].map(({ value, label, Icon }) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => setTheme(value)}
                                        className={cn(
                                            'flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition-colors cursor-pointer',
                                            resolvedTheme === value || (value === 'system' && !['light','dark'].includes(resolvedTheme ?? ''))
                                                ? 'border-primary bg-primary/5'
                                                : 'border-border hover:border-muted-foreground/40'
                                        )}
                                    >
                                        <Icon className="w-5 h-5" />
                                        <span className="text-xs font-medium">{label}</span>
                                    </button>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Map Default Theme</CardTitle>
                            <CardDescription>Sets the default lighting preset when you open the Live Map.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-4 gap-3 max-w-lg">
                                {MAP_THEMES.map(({ value, label }) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => handleMapTheme(value)}
                                        className={cn(
                                            'flex flex-col items-center gap-2 rounded-xl border-2 p-4 transition-colors cursor-pointer',
                                            savedMapTheme === value
                                                ? 'border-primary bg-primary/5'
                                                : 'border-border hover:border-muted-foreground/40'
                                        )}
                                    >
                                        <span className="text-lg">
                                            {value === 'day' ? '☀️' : value === 'night' ? '🌙' : value === 'dusk' ? '🌆' : '🌅'}
                                        </span>
                                        <span className="text-xs font-medium">{label}</span>
                                    </button>
                                ))}
                            </div>
                            <p className="text-xs text-muted-foreground mt-3">Takes effect the next time you open the Live Map page.</p>
                        </CardContent>
                    </Card>
                </TabsContent>

                {/* ── HAZARD LOG TAB ──────────────────────────────────── */}
                <TabsContent value="hazards">
                    <Card>
                        <CardHeader>
                            <CardTitle>Hazard Log Defaults</CardTitle>
                            <CardDescription>Control how hazard data is filtered and displayed in the log.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitSystem} className="space-y-8">
                                {/* Confidence threshold */}
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <Label>Minimum Confidence Threshold</Label>
                                            <p className="text-xs text-muted-foreground mt-0.5">Only show hazards at or above this confidence level.</p>
                                        </div>
                                        <span className="font-mono font-bold text-lg tabular-nums text-primary">
                                            {systemForm.data.confidence_threshold}%
                                        </span>
                                    </div>
                                    <Slider
                                        value={[systemForm.data.confidence_threshold]}
                                        onValueChange={([val]) => systemForm.setData('confidence_threshold', val)}
                                        min={0}
                                        max={100}
                                        step={5}
                                        className="max-w-md"
                                    />
                                    <div className="flex justify-between text-[10px] text-muted-foreground max-w-md">
                                        <span>0% (show all)</span>
                                        <span>100%</span>
                                    </div>
                                </div>

                                <Separator />

                                {/* Items per page */}
                                <div className="flex items-center justify-between max-w-md">
                                    <div>
                                        <Label>Items Per Page</Label>
                                        <p className="text-xs text-muted-foreground mt-0.5">Default number of rows shown in the hazard log table.</p>
                                    </div>
                                    <Select
                                        value={String(systemForm.data.items_per_page)}
                                        onValueChange={val => systemForm.setData('items_per_page', Number(val))}
                                    >
                                        <SelectTrigger className="w-24">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {[10, 15, 25, 50].map(n => (
                                                <SelectItem key={n} value={String(n)}>{n} rows</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <Separator />

                                {/* Default sort */}
                                <div className="flex items-center justify-between max-w-md">
                                    <div>
                                        <Label>Default Sort</Label>
                                        <p className="text-xs text-muted-foreground mt-0.5">Column the hazard log sorts by on first load.</p>
                                    </div>
                                    <Select
                                        value={systemForm.data.default_sort}
                                        onValueChange={val => systemForm.setData('default_sort', val)}
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {SORT_OPTIONS.map(opt => (
                                                <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={systemForm.processing}>
                                        {systemForm.processing ? 'Saving…' : 'Save Hazard Settings'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </TabsContent>

                {/* ── EMERGENCY TAB ───────────────────────────────────── */}
                <TabsContent value="emergency">
                    <Card>
                        <CardHeader>
                            <CardTitle>Emergency Alert Rules</CardTitle>
                            <CardDescription>
                                Select which hazard types can trigger emergency SOS markers on the Live Map.
                                Only physical road hazards should trigger emergency alerts.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submitSystem} className="space-y-6">
                                <div className="space-y-4 max-w-sm">
                                    {ALL_HAZARD_TYPES.map(({ value, label, emergency }) => (
                                        <div key={value} className="flex items-center justify-between py-2">
                                            <div>
                                                <p className="text-sm font-medium">{label}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {emergency ? 'Physical road hazard' : 'Informational only'}
                                                </p>
                                            </div>
                                            <Switch
                                                checked={systemForm.data.emergency_hazard_types.includes(value)}
                                                onCheckedChange={() => toggleEmergencyType(value)}
                                            />
                                        </div>
                                    ))}
                                </div>

                                {systemForm.errors.emergency_hazard_types && (
                                    <p className="text-xs text-destructive">{systemForm.errors.emergency_hazard_types}</p>
                                )}

                                <p className="text-xs text-muted-foreground">
                                    At least one hazard type must remain enabled.
                                </p>

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={systemForm.processing}>
                                        {systemForm.processing ? 'Saving…' : 'Save Emergency Rules'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </>
    );
}

// Persistent layout: Inertia keeps AdminLayout mounted across navigation only
// when it is assigned here, which is what lets the global SOS subscription and
// alarm survive a page change.
Settings.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
