import { type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { Shield, UserCircle } from 'lucide-react';
import AdminLayout from '@/layouts/AdminLayout';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { UserStats, type UserStatsData } from './components/users/UserStats';
import { UserManager, type UsersTab } from './components/users/UserManager';
import { type User, type PaginatedData } from '@/types/models';

interface UsersPageProps {
    users: PaginatedData<User>;
    stats: UserStatsData;
    filters: {
        search?: string;
        role: UsersTab;
    };
}

export default function Users({ users, stats, filters }: UsersPageProps) {
    const tab: UsersTab = filters.role === 'admin' ? 'admin' : 'rider';

    const changeTab = (next: string) => {
        if (next === tab) return;
        // A new tab starts with an empty search.
        router.get(route('users.index'), { role: next }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Users" />

            <div className="mb-6">
                <h1 className="text-3xl font-heading font-bold tracking-tight">Users</h1>
                <p className="text-muted-foreground mt-1">
                    Riders sign up in the AVISO app. Admins are added here by another admin.
                </p>
            </div>

            <Tabs value={tab} onValueChange={changeTab} className="mb-6">
                <TabsList>
                    <TabsTrigger value="rider" className="gap-1.5">
                        <UserCircle className="h-4 w-4" aria-hidden="true" />
                        Riders
                    </TabsTrigger>
                    <TabsTrigger value="admin" className="gap-1.5">
                        <Shield className="h-4 w-4" aria-hidden="true" />
                        Admins
                    </TabsTrigger>
                </TabsList>
            </Tabs>

            <UserStats stats={stats} tab={tab} />

            {/* Keyed by tab so the search box and dialogs reset when switching. */}
            <UserManager key={tab} users={users} search={filters.search} tab={tab} />
        </>
    );
}

// Persistent layout: Inertia keeps AdminLayout mounted across navigation only
// when it is assigned here, which is what lets the global SOS subscription and
// alarm survive a page change.
Users.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
