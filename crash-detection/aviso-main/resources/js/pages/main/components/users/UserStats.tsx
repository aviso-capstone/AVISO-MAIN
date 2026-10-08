import { Card, CardContent } from '@/components/ui/card';
import { UserCircle, Shield } from 'lucide-react';

interface UserStatsProps {
    stats: {
        total: number;
        admins: number;
        riders: number;
    };
}

export function UserStats({ stats }: UserStatsProps) {
    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <Card className="bg-primary text-primary-foreground shadow-sm">
                <CardContent className="p-4 flex flex-col justify-center h-full">
                    <p className="text-sm font-medium opacity-80 mb-1">Total Users</p>
                    <p className="text-3xl font-bold font-heading">{stats.total}</p>
                </CardContent>
            </Card>
            <Card className="shadow-sm border-border/50">
                <CardContent className="p-4 flex flex-col justify-center h-full">
                    <div className="flex items-center gap-2 mb-1">
                        <Shield className="w-4 h-4 text-purple-600" />
                        <p className="text-sm font-medium text-muted-foreground">Administrators</p>
                    </div>
                    <p className="text-3xl font-bold font-heading text-foreground">{stats.admins}</p>
                </CardContent>
            </Card>
            <Card className="shadow-sm border-border/50">
                <CardContent className="p-4 flex flex-col justify-center h-full">
                    <div className="flex items-center gap-2 mb-1">
                        <UserCircle className="w-4 h-4 text-blue-600" />
                        <p className="text-sm font-medium text-muted-foreground">Riders</p>
                    </div>
                    <p className="text-3xl font-bold font-heading text-foreground">{stats.riders}</p>
                </CardContent>
            </Card>
        </div>
    );
}
