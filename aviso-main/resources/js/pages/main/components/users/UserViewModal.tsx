import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { User } from '@/types/models';
import { toast } from '@/lib/toast';
import { Trash2, Plus, PhoneCall, Shield, UserCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

interface EmergencyContact {
    id: number;
    user_id: number;
    name: string;
    relationship: string | null;
    contact_number: string;
    created_at: string;
}

interface UserViewModalProps {
    user: User | null;
    isOpen: boolean;
    onClose: () => void;
}

export function UserViewModal({ user, isOpen, onClose }: UserViewModalProps) {
    const [contacts, setContacts] = useState<EmergencyContact[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isAdding, setIsAdding] = useState(false);

    const { data: formData, setData: setFormData, post, processing, reset, errors, clearErrors } = useForm({
        name: '',
        relationship: '',
        contact_number: '',
    });

    const fetchContacts = async () => {
        if (!user) return;
        setIsLoading(true);
        try {
            const response = await axios.get(route('users.emergency-contacts.index', user.id));
            setContacts(response.data.contacts);
        } catch (error) {
            toast.error('Failed to load emergency contacts.');
        } finally {
            setIsLoading(false);
        }
    };

    useEffect(() => {
        if (isOpen && user) {
            fetchContacts();
            setIsAdding(false);
            reset();
            clearErrors();
        } else {
            setContacts([]);
        }
    }, [isOpen, user]);

    const handleAddContact = (e: React.FormEvent) => {
        e.preventDefault();
        if (!user) return;

        axios.post(route('users.emergency-contacts.store', user.id), formData)
            .then((response) => {
                toast.success('Emergency contact added successfully.');
                setContacts([response.data.contact, ...contacts]);
                setIsAdding(false);
                reset();
            })
            .catch((error) => {
                const errs = error.response?.data?.errors;
                if (errs) {
                    // Set errors manually or show toast
                    toast.error({ title: 'Validation Error', description: Object.values(errs)[0] as string });
                } else {
                    toast.error('Failed to add emergency contact.');
                }
            });
    };

    const handleDeleteContact = (contactId: number) => {
        if (!confirm('Are you sure you want to delete this emergency contact?')) return;

        axios.delete(route('users.emergency-contacts.destroy', contactId))
            .then(() => {
                toast.success('Emergency contact deleted.');
                setContacts(contacts.filter(c => c.id !== contactId));
            })
            .catch(() => {
                toast.error('Failed to delete emergency contact.');
            });
    };

    if (!user) return null;

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-[550px] max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>User Profile</DialogTitle>
                    <DialogDescription>
                        View user details and manage emergency contacts.
                    </DialogDescription>
                </DialogHeader>

                {/* Basic Details */}
                <div className="py-4 space-y-4">
                    <div className="flex items-start gap-4 p-4 rounded-lg bg-muted/20 border">
                        <div className="flex-1 space-y-1">
                            <div className="flex items-center gap-2">
                                <h3 className="font-semibold text-lg">
                                {user.first_name}{user.middle_name ? ` ${user.middle_name}` : ''} {user.last_name}
                            </h3>
                                <Badge variant={user.role === 'admin' ? 'default' : 'secondary'} className={user.role === 'admin' ? 'bg-purple-600' : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300'}>
                                    {user.role === 'admin' ? <Shield className="w-3 h-3 mr-1" /> : <UserCircle className="w-3 h-3 mr-1" />}
                                    {user.role.charAt(0).toUpperCase() + user.role.slice(1)}
                                </Badge>
                            </div>
                            <p className="text-sm text-muted-foreground">@{user.username} • {user.email}</p>
                            {user.contact_number && <p className="text-sm text-muted-foreground">Phone: {user.contact_number}</p>}
                            {user.address && <p className="text-sm text-muted-foreground">Address: {user.address}</p>}
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <h4 className="font-semibold text-md flex items-center gap-2">
                                <PhoneCall className="w-4 h-4 text-primary" />
                                Emergency Contacts
                            </h4>
                            {!isAdding && (
                                <Button size="sm" variant="outline" onClick={() => setIsAdding(true)}>
                                    <Plus className="w-4 h-4 mr-1" /> Add Contact
                                </Button>
                            )}
                        </div>

                        {/* Add Contact Form */}
                        {isAdding && (
                            <form onSubmit={handleAddContact} className="p-4 rounded-lg border bg-muted/10 space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="contact_name">Name</Label>
                                        <Input 
                                            id="contact_name" 
                                            placeholder="John Doe" 
                                            value={formData.name} 
                                            onChange={(e) => setFormData('name', e.target.value)} 
                                            required 
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="contact_relationship">Relationship</Label>
                                        <Input 
                                            id="contact_relationship" 
                                            placeholder="e.g. Spouse, Parent" 
                                            value={formData.relationship} 
                                            onChange={(e) => setFormData('relationship', e.target.value)} 
                                        />
                                    </div>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="contact_number">Contact Number</Label>
                                    <Input 
                                        id="contact_number" 
                                        placeholder="+63 900 000 0000" 
                                        value={formData.contact_number} 
                                        onChange={(e) => setFormData('contact_number', e.target.value)} 
                                        required 
                                    />
                                </div>
                                <div className="flex gap-2 justify-end pt-2">
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setIsAdding(false)}>Cancel</Button>
                                    <Button type="submit" size="sm" disabled={processing}>Save Contact</Button>
                                </div>
                            </form>
                        )}

                        {/* Contact List */}
                        <div className="space-y-2">
                            {isLoading ? (
                                <div className="text-center py-4 text-muted-foreground text-sm">Loading contacts...</div>
                            ) : contacts.length === 0 ? (
                                <div className="text-center py-6 bg-muted/20 border border-dashed rounded-lg text-muted-foreground text-sm">
                                    No emergency contacts registered yet.
                                </div>
                            ) : (
                                contacts.map(contact => (
                                    <div key={contact.id} className="flex items-center justify-between p-3 border rounded-lg hover:bg-muted/10 transition-colors">
                                        <div>
                                            <p className="font-medium text-sm">
                                                {contact.name}
                                                {contact.relationship && <span className="text-muted-foreground font-normal ml-2 text-xs">({contact.relationship})</span>}
                                            </p>
                                            <p className="text-sm text-muted-foreground font-mono mt-0.5">{contact.contact_number}</p>
                                        </div>
                                        <Button 
                                            variant="ghost" 
                                            size="icon" 
                                            className="text-destructive hover:bg-destructive/10" 
                                            onClick={() => handleDeleteContact(contact.id)}
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </Button>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
