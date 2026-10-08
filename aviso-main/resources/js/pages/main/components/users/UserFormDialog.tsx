import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { CheckCircle2, XCircle } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { type User } from "@/types/models";
import { sanitizeText, sanitizeAlphanumeric } from "@/lib/sanitize";
import { PasswordChecker } from "@/components/ui/PasswordChecker";
import { AddressFields, type AddressValue } from './AddressFields';

interface UserFormDialogProps {
    isOpen: boolean;
    onOpenChange: (open: boolean) => void;
    editingUser: User | null;
    onSubmit: (e: React.FormEvent) => void;
    processing: boolean;
    errors: Record<string, string>;
    formData: {
        first_name: string;
        middle_name: string;
        last_name: string;
        username: string;
        email: string;
        contact_number: string;
        address: string;
        street: string;
        province_id: string;
        city_id: string;
        barangay_id: string;
        region_id: string;
        role: string;
        password: string;
        password_confirmation: string;
    };
    setFormData: (key: string, value: any) => void;
}

export function UserFormDialog({
    isOpen,
    onOpenChange,
    editingUser,
    onSubmit,
    processing,
    errors,
    formData,
    setFormData,
}: UserFormDialogProps) {
    return (
        <Dialog open={isOpen} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[520px] max-h-[90vh] overflow-y-auto">
                <form onSubmit={onSubmit}>
                    <DialogHeader>
                        <DialogTitle>{editingUser ? 'Edit User' : 'Create New User'}</DialogTitle>
                        <DialogDescription>
                            {editingUser
                                ? 'Update the user details below.'
                                : 'Add a new administrator or rider account to the system.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="first_name">First Name</Label>
                                <Input
                                    id="first_name"
                                    value={formData.first_name}
                                    onChange={e => setFormData('first_name', e.target.value)}
                                    onBlur={e => setFormData('first_name', sanitizeText(e.target.value))}
                                    required
                                />
                                {errors.first_name && <p className="text-xs text-destructive">{errors.first_name}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="last_name">Last Name</Label>
                                <Input
                                    id="last_name"
                                    value={formData.last_name}
                                    onChange={e => setFormData('last_name', e.target.value)}
                                    onBlur={e => setFormData('last_name', sanitizeText(e.target.value))}
                                    required
                                />
                                {errors.last_name && <p className="text-xs text-destructive">{errors.last_name}</p>}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="middle_name">
                                Middle Name <span className="text-muted-foreground font-normal">(Optional)</span>
                            </Label>
                            <Input
                                id="middle_name"
                                value={formData.middle_name}
                                onChange={e => setFormData('middle_name', e.target.value)}
                                onBlur={e => setFormData('middle_name', sanitizeText(e.target.value))}
                                placeholder="Middle name"
                            />
                            {errors.middle_name && <p className="text-xs text-destructive">{errors.middle_name}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    value={formData.username}
                                    onChange={e => setFormData('username', sanitizeAlphanumeric(e.target.value))}
                                    disabled={editingUser?.role === 'rider'}
                                    required
                                />
                                {errors.username && <p className="text-xs text-destructive">{errors.username}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="role">Role</Label>
                                <Select 
                                    value={formData.role} 
                                    onValueChange={v => setFormData('role', v)}
                                    disabled={editingUser?.role === 'rider'}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="admin">Administrator</SelectItem>
                                        <SelectItem value="rider">Rider</SelectItem>
                                    </SelectContent>
                                </Select>
                                {errors.role && <p className="text-xs text-destructive">{errors.role}</p>}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="email">Email Address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={formData.email}
                                onChange={e => setFormData('email', e.target.value)}
                                disabled={editingUser?.role === 'rider'}
                                required
                            />
                            {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="contact_number">
                                Contact Number <span className="text-muted-foreground font-normal">(Optional)</span>
                            </Label>
                            <Input
                                id="contact_number"
                                value={formData.contact_number}
                                onChange={e => setFormData('contact_number', e.target.value)}
                                onBlur={e => setFormData('contact_number', sanitizeText(e.target.value))}
                            />
                            {errors.contact_number && <p className="text-xs text-destructive">{errors.contact_number}</p>}
                        </div>

                        <AddressFields
                            value={{
                                street:      formData.street,
                                province_id: formData.province_id,
                                city_id:     formData.city_id,
                                barangay_id: formData.barangay_id,
                                region_id:   formData.region_id,
                            }}
                            onChange={(field, val) => setFormData(field, val)}
                            errors={errors}
                        />

                        {(!editingUser || editingUser.role !== 'rider') && (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="password">
                                        {editingUser ? 'New Password (leave blank to keep current)' : 'Password'}
                                    </Label>
                                    <Input
                                        id="password"
                                        type="password"
                                        value={formData.password}
                                        onChange={e => setFormData('password', e.target.value)}
                                        required={!editingUser}
                                    />
                                    {formData.password && <PasswordChecker password={formData.password} />}
                                    {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
                                </div>

                                {(formData.password || !editingUser) && (
                                    <div className="space-y-2">
                                        <Label htmlFor="password_confirmation">Confirm Password</Label>
                                        <Input
                                            id="password_confirmation"
                                            type="password"
                                            value={formData.password_confirmation}
                                            onChange={e => setFormData('password_confirmation', e.target.value)}
                                            required={!!formData.password || !editingUser}
                                        />
                                        {errors.password_confirmation && (
                                            <p className="text-xs text-destructive">{errors.password_confirmation}</p>
                                        )}
                                        {formData.password_confirmation.length > 0 && (
                                            <div className="flex items-center gap-1.5 mt-2">
                                                {formData.password === formData.password_confirmation ? (
                                                    <>
                                                        <CheckCircle2 className="w-4 h-4 text-green-500" />
                                                        <span className="text-xs text-green-500">Passwords match</span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <XCircle className="w-4 h-4 text-red-500" />
                                                        <span className="text-xs text-red-500">Passwords do not match</span>
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {editingUser ? 'Save Changes' : 'Create User'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
