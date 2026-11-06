import { useState } from 'react';
import { useForm, router, usePage } from '@inertiajs/react';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import {
    Search,
    UserCircle,
    Shield,
    Edit,
    ChevronLeft,
    ChevronRight,
    UserPlus,
    Eye,
    UserX,
    UserCheck,
} from 'lucide-react';
import { UserFormDialog } from './UserFormDialog';
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
import { type User, type PaginatedData } from '@/types/models';
import { type PageProps } from '@/types';
import { toast } from '@/lib/toast';
import { UserViewModal } from './UserViewModal';
import { LIMITS, sanitizeText, sanitizeUsername } from '@/lib/sanitize';

/** The Users page tab. */
export type UsersTab = 'rider' | 'admin';

interface UserManagerProps {
    users: PaginatedData<User>;
    search?: string;
    tab: UsersTab;
}

/** True only for an explicit false (missing before the migration runs = active). */
const isDeactivated = (user: User) => user.is_active === false;

export function UserManager({ users, search, tab }: UserManagerProps) {
    const isAdminTab = tab === 'admin';
    const currentUserId = usePage<PageProps>().props.auth.user.id;
    const [searchQuery, setSearchQuery] = useState(search || '');
    const [userToToggle, setUserToToggle] = useState<User | null>(null);
    const [toggling, setToggling] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isViewModalOpen, setIsViewModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<User | null>(null);
    const [viewingUser, setViewingUser] = useState<User | null>(null);

    const { data: formData, setData: setFormData, transform, post, put, processing, errors, reset, clearErrors } = useForm({
        first_name: '',
        middle_name: '',
        last_name: '',
        username: '',
        email: '',
        contact_number: '',
        address: '',
        street: '',
        province_id: '',
        city_id: '',
        barangay_id: '',
        region_id: '',
        password: '',
        password_confirmation: '',
    });

    transform((data) => ({
        ...data,
        first_name:      sanitizeText(data.first_name),
        middle_name:     sanitizeText(data.middle_name),
        last_name:       sanitizeText(data.last_name),
        username:        sanitizeUsername(data.username),
        contact_number:  sanitizeText(data.contact_number),
        street:          sanitizeText(data.street),
    }));

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('users.index'), { search: searchQuery, role: tab }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const openCreateModal = () => {
        setEditingUser(null);
        clearErrors();
        reset();
        setIsModalOpen(true);
    };

    const openViewModal = (user: User) => {
        setViewingUser(user);
        setIsViewModalOpen(true);
    };

    const openEditModal = (user: User) => {
        setEditingUser(user);
        clearErrors();
        setFormData({
            first_name:           user.first_name,
            middle_name:          user.middle_name || '',
            last_name:            user.last_name,
            username:             user.username,
            email:                user.email,
            contact_number:       user.contact_number || '',
            address:              user.address || '',
            street:               user.street || '',
            province_id:          user.province_id || '',
            city_id:              user.city_id || '',
            barangay_id:          user.barangay_id || '',
            region_id:            user.region_id || '',
            password:             '',
            password_confirmation: '',
        });
        setIsModalOpen(true);
    };

    const submitUser = (e: React.FormEvent) => {
        e.preventDefault();

        if (editingUser) {
            put(route('users.update', editingUser.id), {
                // The success message comes from the server (flash toast).
                onSuccess: () => setIsModalOpen(false),
                onError: (err) => {
                    toast.error({
                        title: 'Failed to update user',
                        description: Object.values(err)[0] as string || 'Please check the form for errors.'
                    });
                }
            });
        } else {
            post(route('users.store'), {
                onSuccess: () => setIsModalOpen(false),
                onError: (err) => {
                    toast.error({
                        title: 'Failed to add admin',
                        description: Object.values(err)[0] as string || 'Please check the form for errors.'
                    });
                }
            });
        }
    };

    /**
     * Deactivate an active account, or reactivate a deactivated one. Accounts
     * are never deleted: hazards, trips, crash logs and SOS history stay linked.
     */
    const executeToggle = () => {
        if (!userToToggle) return;
        router.patch(
            route('users.active', userToToggle.id),
            { is_active: isDeactivated(userToToggle) },
            {
                preserveScroll: true,
                onStart: () => setToggling(true),
                onFinish: () => {
                    setToggling(false);
                    setUserToToggle(null);
                },
            },
        );
    };

    return (
        <>


            <Card className="border-border/50 shadow-sm">
                {/* Filters */}
                <div className="p-4 border-b flex flex-col sm:flex-row gap-4 items-center bg-muted/20">
                    <form onSubmit={submitSearch} className="relative w-full sm:w-80">
                        <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search name, username, email..."
                            maxLength={LIMITS.search}
                            className="pl-9 bg-background"
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                        />
                    </form>

                    {isAdminTab && (
                        <div className="flex w-full sm:w-auto gap-3 flex-1 sm:justify-end">
                            <Button onClick={openCreateModal} className="gap-2">
                                <UserPlus className="h-4 w-4" aria-hidden="true" />
                                Add Admin
                            </Button>
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="relative w-full overflow-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-muted/30">
                                <TableHead className="font-semibold">Name</TableHead>
                                <TableHead className="font-semibold">Username</TableHead>
                                <TableHead className="font-semibold">Email</TableHead>
                                <TableHead className="font-semibold">Contact</TableHead>
                                {!isAdminTab && <TableHead className="font-semibold">Address</TableHead>}
                                <TableHead className="font-semibold">Status</TableHead>
                                <TableHead className="font-semibold">Created At</TableHead>
                                <TableHead className="font-semibold text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={isAdminTab ? 7 : 8} className="h-32 text-center text-muted-foreground">
                                        {isAdminTab ? 'No admins found.' : 'No riders found.'}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                users.data.map((user) => (
                                    <TableRow key={user.id} className="hover:bg-muted/20">
                                        <TableCell className="font-medium whitespace-nowrap">
                                            {user.first_name}{user.middle_name ? ` ${user.middle_name}` : ''} {user.last_name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">@{user.username}</TableCell>
                                        <TableCell>{user.email}</TableCell>
                                        <TableCell className="text-muted-foreground">{user.contact_number || '—'}</TableCell>
                                        {!isAdminTab && (
                                            <TableCell className="text-muted-foreground max-w-[180px] truncate" title={user.address || undefined}>
                                                {user.address || '—'}
                                            </TableCell>
                                        )}
                                        <TableCell>
                                            {isDeactivated(user) ? (
                                                <Badge variant="secondary" className="bg-muted text-muted-foreground">Deactivated</Badge>
                                            ) : (
                                                <Badge variant="secondary" className="bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                                                    {isAdminTab && <Shield className="w-3 h-3 mr-1" aria-hidden="true" />}
                                                    Active
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                            {new Date(user.created_at).toLocaleDateString()}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                {!isAdminTab && (
                                                    <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-primary" onClick={() => openViewModal(user)} aria-label={`View ${user.first_name} ${user.last_name}`}>
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground" onClick={() => openEditModal(user)} aria-label={`Edit ${user.first_name} ${user.last_name}`}>
                                                    <Edit className="h-4 w-4" />
                                                </Button>
                                                {user.id !== currentUserId && (
                                                        isDeactivated(user) ? (
                                                            <Button variant="ghost" size="icon" className="h-8 w-8 text-green-600 hover:text-green-700 hover:bg-green-600/10" onClick={() => setUserToToggle(user)} aria-label={`Reactivate ${user.first_name} ${user.last_name}`} title="Reactivate">
                                                                <UserCheck className="h-4 w-4" />
                                                            </Button>
                                                        ) : (
                                                            <Button variant="ghost" size="icon" className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10" onClick={() => setUserToToggle(user)} aria-label={`Deactivate ${user.first_name} ${user.last_name}`} title="Deactivate">
                                                                <UserX className="h-4 w-4" />
                                                            </Button>
                                                        )
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Pagination */}
                {users.last_page > 1 && (
                    <div className="p-4 border-t flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            Showing <span className="font-medium text-foreground">{(users.current_page - 1) * users.per_page + 1}</span> to{' '}
                            <span className="font-medium text-foreground">{Math.min(users.current_page * users.per_page, users.total)}</span> of{' '}
                            <span className="font-medium text-foreground">{users.total}</span> entries
                        </div>
                        <div className="flex items-center gap-2">
                            <Button 
                                variant="outline" 
                                size="icon" 
                                className="h-8 w-8"
                                disabled={users.current_page === 1}
                                onClick={() => router.get(users.links[0].url as string)}
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            
                            <div className="flex items-center gap-1">
                                {users.links.slice(1, -1).map((link, i) => {
                                    if (link.label === '...') return <span key={i} className="px-2">...</span>;
                                    return (
                                        <Button
                                            key={i}
                                            variant={link.active ? "default" : "outline"}
                                            size="sm"
                                            className={`h-8 w-8 p-0 ${link.active ? 'bg-primary text-primary-foreground' : ''}`}
                                            onClick={() => link.url && router.get(link.url)}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    );
                                })}
                            </div>

                            <Button 
                                variant="outline" 
                                size="icon"
                                className="h-8 w-8"
                                disabled={users.current_page === users.last_page}
                                onClick={() => router.get(users.links[users.links.length - 1].url as string)}
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}
            </Card>

            {/* Create / Edit Modal */}
            <UserFormDialog
                isOpen={isModalOpen}
                onOpenChange={setIsModalOpen}
                editingUser={editingUser}
                isAdminForm={isAdminTab}
                onSubmit={submitUser}
                processing={processing}
                errors={errors}
                formData={formData}
                setFormData={setFormData}
            />

            {/* Deactivate / Reactivate Confirmation */}
            <AlertDialog open={userToToggle !== null} onOpenChange={(open) => { if (!open && !toggling) setUserToToggle(null); }}>
                <AlertDialogContent className="sm:max-w-[420px]">
                    {userToToggle && (isDeactivated(userToToggle) ? (
                        <AlertDialogHeader>
                            <AlertDialogTitle>{isAdminTab ? 'Reactivate Admin' : 'Reactivate Rider'}</AlertDialogTitle>
                            <AlertDialogDescription>
                                Reactivate <strong>{userToToggle.first_name} {userToToggle.last_name}</strong>?
                                {isAdminTab
                                    ? ' They will be able to log in to the admin website again with their current password.'
                                    : ' They will be able to log in to the AVISO app again with their current password.'}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                    ) : (
                        <AlertDialogHeader>
                            <AlertDialogTitle className="text-destructive">{isAdminTab ? 'Deactivate Admin' : 'Deactivate Rider'}</AlertDialogTitle>
                            <AlertDialogDescription>
                                Deactivate <strong>{userToToggle.first_name} {userToToggle.last_name}</strong>?
                                {isAdminTab
                                    ? " They will be logged out and can't log in until reactivated. Their name stays on the SOS alerts, hazards and reports they handled."
                                    : " They will be logged out of the AVISO app and can't log in or send an SOS from the app until reactivated. Their hazard logs, trips, crash logs and SOS history are kept."}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                    ))}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={toggling}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={(e) => { e.preventDefault(); executeToggle(); }}
                            disabled={toggling}
                            className={userToToggle && isDeactivated(userToToggle) ? '' : 'bg-destructive text-white hover:bg-destructive/90'}
                        >
                            {userToToggle && isDeactivated(userToToggle) ? 'Reactivate' : 'Deactivate'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* View User & Emergency Contacts Modal */}
            <UserViewModal 
                user={viewingUser} 
                isOpen={isViewModalOpen} 
                onClose={() => setIsViewModalOpen(false)} 
            />
        </>
    );
}
