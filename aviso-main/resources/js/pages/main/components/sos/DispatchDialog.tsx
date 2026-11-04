import { useEffect, useState, type FormEvent } from 'react';
import { Loader2, Truck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AGENCY_OPTIONS,
    AGENCY_OTHER,
    ETA_OPTIONS,
    ETA_UNKNOWN,
    firstValidationError,
    type DispatchFormData,
} from './dispatchOptions';

interface DispatchDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    riderName: string;
    /** Saves the dispatch; reject to keep the dialog open and show the error. */
    onSubmit: (data: DispatchFormData) => Promise<void>;
}

/**
 * "Help dispatched" form: filled in after the admin has called an agency.
 * Everything is a dropdown except the responder's name (optional) and the
 * agency name when "Other" is picked (then required).
 */
export function DispatchDialog({ open, onOpenChange, riderName, onSubmit }: DispatchDialogProps) {
    const [agency, setAgency] = useState('');
    const [agencyOther, setAgencyOther] = useState('');
    const [eta, setEta] = useState('');
    const [firstName, setFirstName] = useState('');
    const [lastName, setLastName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // Fresh form every time it opens.
    useEffect(() => {
        if (!open) return;
        setAgency('');
        setAgencyOther('');
        setEta('');
        setFirstName('');
        setLastName('');
        setError(null);
    }, [open]);

    const isOther = agency === AGENCY_OTHER;

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (!agency) return setError('Choose the agency you called.');
        if (isOther && !agencyOther.trim()) return setError('Type the name of the agency.');
        if (!eta) return setError('Choose the ETA the agency gave (or Unknown).');

        setSaving(true);
        setError(null);
        try {
            await onSubmit({
                responder_agency: agency,
                responder_agency_other: isOther ? agencyOther.trim() : null,
                eta_minutes: eta === ETA_UNKNOWN ? null : Number(eta),
                responder_first_name: firstName.trim() || null,
                responder_last_name: lastName.trim() || null,
            });
            onOpenChange(false);
        } catch (err) {
            setError(firstValidationError(err, 'Could not save. Please try again.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={handleSubmit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Truck className="h-4 w-4 text-amber-600" />
                            Help dispatched
                        </DialogTitle>
                        <DialogDescription>
                            Record who you called for {riderName}. This stops the alarm and tells the rider help is on the way.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-1.5">
                        <Label htmlFor="dispatch-agency">Agency</Label>
                        <Select value={agency} onValueChange={setAgency}>
                            <SelectTrigger id="dispatch-agency" className="w-full">
                                <SelectValue placeholder="Choose the agency you called" />
                            </SelectTrigger>
                            <SelectContent>
                                {AGENCY_OPTIONS.map(o => (
                                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {isOther && (
                        <div className="space-y-1.5">
                            <Label htmlFor="dispatch-agency-other">Agency name</Label>
                            <Input
                                id="dispatch-agency-other"
                                value={agencyOther}
                                onChange={e => setAgencyOther(e.target.value)}
                                maxLength={60}
                                placeholder="e.g. Tetuan Barangay Tanod"
                                autoFocus
                            />
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="dispatch-eta">ETA</Label>
                        <Select value={eta} onValueChange={setEta}>
                            <SelectTrigger id="dispatch-eta" className="w-full">
                                <SelectValue placeholder="How long until they arrive?" />
                            </SelectTrigger>
                            <SelectContent>
                                {ETA_OPTIONS.map(o => (
                                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="dispatch-first-name">
                                Responder first name <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="dispatch-first-name"
                                value={firstName}
                                onChange={e => setFirstName(e.target.value)}
                                maxLength={50}
                                autoComplete="off"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="dispatch-last-name">
                                Last name <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <Input
                                id="dispatch-last-name"
                                value={lastName}
                                onChange={e => setLastName(e.target.value)}
                                maxLength={50}
                                autoComplete="off"
                            />
                        </div>
                    </div>

                    {error && <p className="text-sm text-destructive" role="alert">{error}</p>}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving} className="bg-amber-600 text-white hover:bg-amber-700">
                            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                            Save — help dispatched
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
