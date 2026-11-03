import { useEffect, useState, type FormEvent } from 'react';
import { CheckCircle2, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
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
    OUTCOME_OPTIONS,
    OUTCOME_OTHER,
    firstValidationError,
    type ResolveFormData,
} from './dispatchOptions';

interface ResolveDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    riderName: string;
    /** Saves the resolution; reject to keep the dialog open and show the error. */
    onSubmit: (data: ResolveFormData) => Promise<void>;
}

/** Close an alert with how it ended. The note is required only for "Other". */
export function ResolveDialog({ open, onOpenChange, riderName, onSubmit }: ResolveDialogProps) {
    const [outcome, setOutcome] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setOutcome('');
        setNote('');
        setError(null);
    }, [open]);

    const noteRequired = outcome === OUTCOME_OTHER;

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (!outcome) return setError('Choose how the emergency ended.');
        if (noteRequired && !note.trim()) return setError('Write what happened when the outcome is "Other".');

        setSaving(true);
        setError(null);
        try {
            await onSubmit({ outcome, resolution_note: note.trim() || null });
            onOpenChange(false);
        } catch (err) {
            setError(firstValidationError(err, 'Could not resolve. Please try again.'));
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
                            <CheckCircle2 className="h-4 w-4 text-green-600" />
                            Resolve emergency
                        </DialogTitle>
                        <DialogDescription>How did the emergency of {riderName} end?</DialogDescription>
                    </DialogHeader>

                    <div className="space-y-1.5">
                        <Label htmlFor="resolve-outcome">Outcome</Label>
                        <Select value={outcome} onValueChange={setOutcome}>
                            <SelectTrigger id="resolve-outcome" className="w-full">
                                <SelectValue placeholder="Choose the outcome" />
                            </SelectTrigger>
                            <SelectContent>
                                {OUTCOME_OPTIONS.map(o => (
                                    <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="resolve-note">
                            Note{' '}
                            <span className="text-muted-foreground font-normal">
                                {noteRequired ? '(required)' : '(optional)'}
                            </span>
                        </Label>
                        <Textarea
                            id="resolve-note"
                            value={note}
                            onChange={e => setNote(e.target.value)}
                            maxLength={300}
                            rows={3}
                            placeholder="e.g. Minor injuries, rider brought home by family"
                        />
                    </div>

                    {error && <p className="text-sm text-destructive" role="alert">{error}</p>}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving} className="bg-green-600 text-white hover:bg-green-700">
                            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                            Resolve
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
