import { type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { UserStats } from './components/users/UserStats';
import { UserManager } from './components/users/UserManager';
import { type User, type PaginatedData } from '@/types/models';

interface PageProps {
    users: PaginatedData<User>;
    stats: {
        total: number;
        admins: number;
        riders: number;
    };
    filters: {
        search?: string;
        role?: string;
    };
}

export default function Users({ users, stats, filters }: PageProps) {
    return (
        <>
            <Head title="Riders" />

            {/* Header */}
            <div className="mb-6">
                <h1 className="text-3xl font-heading font-bold tracking-tight">Riders</h1>
                <p className="text-muted-foreground mt-1">
                    Manage administrators and edge-device riders.
                </p>
            </div>

            <UserStats stats={stats} />

            <UserManager 
                users={users} 
                filters={filters} 
            />
            
        </>
    );
}

// Persistent layout: Inertia keeps AdminLayout mounted across navigation only
// when it is assigned here, which is what lets the global SOS subscription and
// alarm survive a page change.
Users.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
