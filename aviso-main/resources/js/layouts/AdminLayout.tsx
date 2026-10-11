import { ReactNode } from 'react';
import AdminSidebar from '@/components/admin/AdminSidebar';
import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { TooltipProvider } from '@/components/ui/tooltip';
import { Button } from '@/components/ui/button';
import { SosAlertProvider } from '@/components/sos/SosAlertProvider';
import { SosAlertBanner } from '@/components/sos/SosAlertBanner';
import { useTheme } from 'next-themes';
import { Sun, Moon } from 'lucide-react';

/**
 * The sidebar saves its open/collapsed state in this cookie on every toggle;
 * reading it back on load keeps a collapsed sidebar collapsed after a reload.
 */
function sidebarStartsOpen(): boolean {
    if (typeof document === 'undefined') return true;
    return !document.cookie.split('; ').includes('sidebar_state=false');
}

interface AdminLayoutProps {
    children: ReactNode;
}

export default function AdminLayout({ children }: AdminLayoutProps) {
    const { resolvedTheme, setTheme } = useTheme();

    return (
        // SosAlertProvider owns the riders.live subscription and the alarm audio
        // for the whole admin session. Every admin page assigns this layout via
        // the static `.layout` property, so Inertia keeps it mounted across
        // navigation and neither the socket nor the alarm is ever torn down
        // mid-emergency.
        <SosAlertProvider>
            <TooltipProvider delayDuration={0}>
            <SidebarProvider defaultOpen={sidebarStartsOpen()}>
                <div className="min-h-screen bg-background flex w-full">
                    <AdminSidebar />
                    {/* Exactly screen-height and scrolls on its own, so a page can
                        fill the remaining space (the Live Map does) while every
                        other page still scrolls normally. `scroll-region` lets
                        Inertia reset/restore this scroll on navigation. */}
                    <main scroll-region="" className="flex h-svh min-w-0 flex-1 flex-col overflow-auto p-8 w-full">
                        <div className="flex shrink-0 items-center justify-between mb-4">
                            <SidebarTrigger />
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => setTheme(resolvedTheme === 'dark' ? 'light' : 'dark')}
                                className="rounded-full"
                            >
                                {resolvedTheme === 'dark'
                                    ? <Sun className="w-5 h-5" />
                                    : <Moon className="w-5 h-5" />
                                }
                            </Button>
                        </div>
                        <div className="shrink-0">
                            <SosAlertBanner />
                        </div>
                        <div className="flex-1">{children}</div>
                    </main>
                </div>
            </SidebarProvider>
            </TooltipProvider>
        </SosAlertProvider>
    );
}
