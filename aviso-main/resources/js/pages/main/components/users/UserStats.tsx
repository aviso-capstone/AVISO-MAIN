import { Card, CardContent } from '@/components/ui/card';
import { UserCircle, Shield, ShieldCheck } from 'lucide-react';
import { type UsersTab } from './UserManager';

export interface UserStatsData {
    riders: number;
    admins: number;
    active_admins: number;
}

interface UserStatsProps {
    stats: UserStatsData;
    tab: UsersTab;
}

export function UserStats({ stats, tab }: UserStatsProps) {
    if (tab === 'rider') {
        return (
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <Card className="bg-primary text-primary-foreground shadow-sm">
                    <CardContent className="p-4 flex flex-col justify-center h-full">
                        <div className="flex items-center gap-2 mb-1">
                            <UserCircle className="w-4 h-4 opacity-80" aria-hidden="true" />
                            <p className="text-sm font-medium opacity-80">Total Riders</p>
                        </div>
                        <p className="text-3xl font-bold font-heading tabular-nums">{stats.riders}</p>
                    </CardContent>
                </Card>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <Card className="bg-primary text-primary-foreground shadow-sm">
                <CardContent className="p-4 flex flex-col justify-center h-full">
                    <div className="flex items-center gap-2 mb-1">
                        <Shield className="w-4 h-4 opacity-80" aria-hidden="true" />
                        <p className="text-sm font-medium opacity-80">Total Admins</p>
                    </div>
                    <p className="text-3xl font-bold font-heading tabular-nums">{stats.admins}</p>
                </CardContent>
            </Card>
            <Card className="shadow-sm border-border/50">
                <CardContent className="p-4 flex flex-col justify-center h-full">
                    <div className="flex items-center gap-2 mb-1">
                        <ShieldCheck className="w-4 h-4 text-green-600" aria-hidden="true" />
                        <p className="text-sm font-medium text-muted-foreground">Active Admins</p>
                    </div>
                    <p className="text-3xl font-bold font-heading text-foreground tabular-nums">{stats.active_admins}</p>
                </CardContent>
            </Card>
        </div>
    );
}
