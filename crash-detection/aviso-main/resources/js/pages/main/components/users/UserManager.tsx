import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import {
    Search,
    UserCircle,
    Shield,
    Trash2,
    Edit,
    ChevronLeft,
    ChevronRight,
    UserPlus,
    Eye
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
import { toast } from '@/lib/toast';
import { UserViewModal } from './UserViewModal';
import { sanitizeText, sanitizeAlphanumeric } from '@/lib/sanitize';

interface UserManagerProps {
    users: PaginatedData<User>;
    filters: {
        search?: string;
        role?: string;
    };
}

export function UserManager({ users, filters }: UserManagerProps) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
    const [isViewModalOpen, setIsViewModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<User | null>(null);
    const [userToDelete, setUserToDelete] = useState<User | null>(null);
    const [viewingUser, setViewingUser] = useState<User | null>(null);

    const { data: formData, setData: setFormData, transform, post, put, delete: destroy, processing, errors, reset, clearErrors } = useForm({
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
        role: 'rider',
        password: '',
        password_confirmation: '',
    });

    transform((data) => ({
        ...data,
        first_name:      sanitizeText(data.first_name),
        middle_name:     sanitizeText(data.middle_name),
        last_name:       sanitizeText(data.last_name),
        username:        sanitizeAlphanumeric(data.username),
        contact_number:  sanitizeText(data.contact_number),
        street:          sanitizeText(data.street),
    }));

    const handleFilterChange = (key: 'role', value: string) => {
        router.get(route('users.index'), { search: searchQuery, [key]: value }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('users.index'), { search: searchQuery, role: filters.role || 'all' }, {
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
            role:                 user.role,
            password:             '',
            password_confirmation: '',
        });
        setIsModalOpen(true);
    };

    const submitUser = (e: React.FormEvent) => {
        e.preventDefault();

        if (editingUser) {
            put(route('users.update', editingUser.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    toast.success('User updated successfully');
                },
                onError: (err) => {
                    toast.error({
                        title: 'Failed to update user',
                        description: Object.values(err)[0] as string || 'Please check the form for errors.'
                    });
                }
            });
        } else {
            post(route('users.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    toast.success('User created successfully');
                },
                onError: (err) => {
                    toast.error({
                        title: 'Failed to create user',
                        description: Object.values(err)[0] as string || 'Please check the form for errors.'
                    });
                }
            });
        }
    };

    const confirmDelete = (user: User) => {
        setUserToDelete(user);
        setIsDeleteModalOpen(true);
    };

    const executeDelete = () => {
        if (!userToDelete) return;
        destroy(route('users.destroy', userToDelete.id), {
            onSuccess: () => {
                setIsDeleteModalOpen(false);
                toast.success('User deleted successfully');
            },
            onError: () => {
                toast.error('Failed to delete user');
            }
        });
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
                            className="pl-9 bg-background"
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                        />
                    </form>

                    <div className="flex w-full sm:w-auto gap-3 flex-1 sm:justify-end">
                        <Select value={filters.role || 'all'} onValueChange={(v) => handleFilterChange('role', v)}>
                            <SelectTrigger className="w-[140px] bg-background">
                                <SelectValue placeholder="All Roles" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Roles</SelectItem>
                                <SelectItem value="admin">Admins</SelectItem>
                                <SelectItem value="rider">Riders</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
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
                                <TableHead className="font-semibold">Address</TableHead>
                                <TableHead className="font-semibold">Role</TableHead>
                                <TableHead className="font-semibold">Created At</TableHead>
                                <TableHead className="font-semibold text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} className="h-32 text-center text-muted-foreground">
                                        No users found.
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
                                        <TableCell className="text-muted-foreground max-w-[180px] truncate" title={user.address || undefined}>
                                            {user.address || '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={user.role === 'admin' ? 'default' : 'secondary'} className={user.role === 'admin' ? 'bg-purple-600 hover:bg-purple-700' : 'bg-blue-100 text-blue-800 hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-300'}>
                                                {user.role === 'admin' ? <Shield className="w-3 h-3 mr-1" /> : <UserCircle className="w-3 h-3 mr-1" />}
                                                {user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                                            {new Date(user.created_at).toLocaleDateString()}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-primary" onClick={() => openViewModal(user)} aria-label={`View ${user.first_name} ${user.last_name}`}>
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                                <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground" onClick={() => openEditModal(user)} aria-label={`Edit ${user.first_name} ${user.last_name}`}>
                                                    <Edit className="h-4 w-4" />
                                                </Button>
                                                <Button variant="ghost" size="icon" className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10" onClick={() => confirmDelete(user)} aria-label={`Delete ${user.first_name} ${user.last_name}`}>
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
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
                onSubmit={submitUser}
                processing={processing}
                errors={errors}
                formData={formData}
                setFormData={setFormData}
            />

            {/* Delete Confirmation Modal */}
            <AlertDialog open={isDeleteModalOpen} onOpenChange={setIsDeleteModalOpen}>
                <AlertDialogContent className="sm:max-w-[400px]">
                    <AlertDialogHeader>
                        <AlertDialogTitle className="text-destructive">Delete User</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete the account for{' '}
                            <strong>{userToDelete?.first_name} {userToDelete?.last_name}</strong>?
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={executeDelete}
                            disabled={processing}
                            className="bg-destructive text-white hover:bg-destructive/90"
                        >
                            Delete User
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
