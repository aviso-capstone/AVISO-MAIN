import { type ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';
import { Button } from '@/components/ui/button';
import { Download } from 'lucide-react';
import { HazardStats } from './components/hazards/HazardStats';
import { HazardTable } from './components/hazards/HazardTable';
import { type Barangay, type HazardLog, type PaginatedData } from '@/types/models';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

// Stats matches the key/value counts passed from the controller
interface StatsData {
    [key: string]: number;
}

interface PageProps {
    hazards: PaginatedData<HazardLog>;
    stats: StatsData;
    areaCounts: StatsData;
    filters: {
        search?: string;
        type?: string;
        barangay?: string;
        status?: string;
    };
    types: string[];
    barangays: Barangay[];
}

export default function HazardLogs({ hazards, stats, areaCounts, filters, types, barangays }: PageProps) {
    const totalHazards = hazards.total;

    const exportData = (format: 'csv' | 'pdf') => {
        // Construct the current URL with filters, but add export=format
        const url = new URL(window.location.href);
        url.searchParams.set('export', format);
        window.location.href = url.toString();
    };

    return (
        <>
            <Head title="Hazard Logs" />

            {/* Header */}
            <div className="flex justify-between items-end mb-6">
                <div>
                    <h1 className="text-3xl font-heading font-bold tracking-tight">Hazard Logs</h1>
                    <p className="text-muted-foreground mt-1">
                        Historical and active detections from edge devices.
                    </p>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="outline">
                            <Download className="w-4 h-4 mr-2" />
                            Export Data
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => exportData('csv')} className="cursor-pointer">
                            Export as CSV
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => exportData('pdf')} className="cursor-pointer">
                            Export as PDF
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <HazardStats 
                totalHazards={totalHazards} 
                types={types} 
                stats={stats} 
            />

            <HazardTable 
                hazards={hazards} 
                filters={filters} 
                types={types} 
                barangays={barangays} 
            />
            
        </>
    );
}

// Persistent layout: Inertia keeps AdminLayout mounted across navigation only
// when it is assigned here, which is what lets the global SOS subscription and
// alarm survive a page change.
HazardLogs.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
