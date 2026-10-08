import { Link, usePage, router } from "@inertiajs/react";
import { useState } from "react";
import { toast } from "@/lib/toast";
import {
    Map,
    AlertTriangle,
    BarChart3,
    Settings,
    LogOut,
    LayoutDashboard,
    Users,
    Siren,
} from "lucide-react";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { useSosAlerts } from "@/components/sos/SosAlertProvider";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
const navGroups = [
    {
        title: "TRACKING & LOGS",
        items: [
            {
                title: "Live Map",
                url: "/map",
                icon: Map,
            },
            {
                title: "Hazards Log",
                url: "/hazards",
                icon: AlertTriangle,
            },
            {
                title: "SOS Alerts",
                url: "/sos-alerts",
                icon: Siren,
            },
        ],
    },
    {
        title: "MANAGEMENT",
        items: [
            {
                title: "Riders",
                url: "/users",
                icon: Users,
            },
        ],
    },
    {
        title: "CONFIGURATION",
        items: [
            {
                title: "Settings",
                url: "/settings",
                icon: Settings,
            },
        ],
    },
];

/** Nav entries that carry the unresolved-SOS count. */
const SOS_BADGED_URLS = ["/map", "/sos-alerts"];

export default function AdminSidebar() {
    const { url, props } = usePage<any>();
    const user = props.auth?.user;
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false);
    const { alerts } = useSosAlerts();
    const sosCount = alerts.length;

    const executeLogout = () => {
        toast.setPending("info", "You have been successfully logged out.");
        router.post(route("logout"));
    };

    const initial = user?.first_name ? user.first_name.charAt(0).toUpperCase() : "A";
    const fullName = user ? `${user.first_name} ${user.last_name}` : "Admin";
    const roleLabel = user?.role ? user.role.charAt(0).toUpperCase() + user.role.slice(1) : "Admin";

    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <div className="flex items-center space-x-3 p-4 group-data-[collapsible=icon]:p-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:space-x-0">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center">
                        <img
                            src="/logo-removebg.png"
                            alt="Aviso Logo"
                            className="w-full h-full object-contain rounded-lg"
                        />
                    </div>
                    <div className="group-data-[collapsible=icon]:hidden">
                        <h2 className="font-heading font-bold text-lg leading-tight">
                            Aviso
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Admin Portal
                        </p>
                    </div>
                </div>
            </SidebarHeader>
            <SidebarContent>
                {/* Prominent Dashboard Block */}
                <SidebarGroup className="pb-0 mt-2 px-3">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                className={`text-base py-4 rounded-md mb-2 transition-colors duration-200 ${
                                    url === "/dashboard" || url.startsWith("/dashboard?")
                                        ? "bg-black text-white dark:bg-white dark:text-black font-semibold shadow-sm"
                                        : "text-muted-foreground hover:bg-primary/10 hover:text-primary"
                                }`}
                            >
                                <Link href="/dashboard" className="flex items-center">
                                    <LayoutDashboard className="w-5 h-5 mr-3" />
                                    <span>Dashboard</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>

                {/* Grouped Navigation */}
                {navGroups.map((group) => (
                    <SidebarGroup key={group.title} className="pt-2 px-3">
                        <SidebarGroupLabel className="text-xs uppercase tracking-widest text-muted-foreground font-bold mb-1">
                            {group.title}
                        </SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                {group.items.map((item) => {
                                    const isActive = item.url !== "#" && url.startsWith(item.url);
                                    const showSosCount = sosCount > 0 && SOS_BADGED_URLS.includes(item.url);
                                    return (
                                        <SidebarMenuItem key={item.title}>
                                            <SidebarMenuButton
                                                asChild
                                                className={`text-base py-4 rounded-md my-0.5 ${
                                                    isActive
                                                        ? "bg-black text-white dark:bg-white dark:text-black font-semibold shadow-sm"
                                                        : "text-muted-foreground hover:bg-primary/10 hover:text-primary"
                                                }`}
                                            >
                                                <Link href={item.url} className="flex items-center">
                                                    <item.icon className="w-5 h-5 mr-3" />
                                                    <span>{item.title}</span>
                                                </Link>
                                            </SidebarMenuButton>
                                            {showSosCount ? (
                                                <SidebarMenuBadge className="bg-red-500 text-white font-bold">
                                                    {sosCount}
                                                </SidebarMenuBadge>
                                            ) : null}
                                        </SidebarMenuItem>
                                    );
                                })}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                ))}
            </SidebarContent>
            
            <SidebarFooter className="p-4 border-t border-border/50">
                {/* Admin Profile Section */}
                <div className="flex items-center gap-3 mb-4 px-2 group-data-[collapsible=icon]:justify-center">
                    <div className="w-10 h-10 shrink-0 rounded-full bg-primary flex items-center justify-center text-primary-foreground font-bold text-lg">
                        {initial}
                    </div>
                    <div className="flex flex-col group-data-[collapsible=icon]:hidden">
                        <span className="font-semibold text-sm leading-tight text-foreground truncate max-w-[140px]">{fullName}</span>
                        <span className="text-xs text-muted-foreground">{roleLabel}</span>
                    </div>
                </div>

                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            asChild
                            className="bg-destructive text-white hover:bg-destructive/90 hover:text-white font-semibold text-base py-5 rounded-md cursor-pointer justify-center group-data-[collapsible=icon]:justify-center transition-colors"
                        >
                            <a onClick={() => setIsLogoutModalOpen(true)} className="flex items-center w-full justify-center">
                                <LogOut className="w-5 h-5 group-data-[collapsible=icon]:mr-0 mr-2" />
                                <span className="group-data-[collapsible=icon]:hidden">Log Out</span>
                            </a>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>

            <AlertDialog open={isLogoutModalOpen} onOpenChange={setIsLogoutModalOpen}>
                <AlertDialogContent className="sm:max-w-[400px]">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-destructive">Log Out</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to end your session and log out of the admin portal?
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={executeLogout}
                            className="bg-destructive text-white hover:bg-destructive/90"
                        >
                            Log Out
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </Sidebar>
    );
}
