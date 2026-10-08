import { useEffect } from 'react';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAddressCascade } from '@/hooks/useAddressCascade';
import { sanitizeText } from '@/lib/sanitize';

export interface AddressValue {
    street:      string;
    province_id: string;
    city_id:     string;
    barangay_id: string;
    region_id:   string;
}

interface AddressFieldsProps {
    value:    AddressValue;
    onChange: (field: keyof AddressValue, val: string) => void;
    errors?:  Partial<Record<keyof AddressValue, string>>;
}

const ZAMBOANGA_PROVINCE_ID = '09317';
const ZAMBOANGA_CITY_ID     = '0931700';
const ZAMBOANGA_REGION_ID   = '09';

export function AddressFields({ value, onChange, errors }: AddressFieldsProps) {
    const { barangays, loading } = useAddressCascade();

    // Lock province/city/region to Zamboanga City on mount
    useEffect(() => {
        if (value.province_id !== ZAMBOANGA_PROVINCE_ID) onChange('province_id', ZAMBOANGA_PROVINCE_ID);
        if (value.city_id     !== ZAMBOANGA_CITY_ID)     onChange('city_id',     ZAMBOANGA_CITY_ID);
        if (value.region_id   !== ZAMBOANGA_REGION_ID)   onChange('region_id',   ZAMBOANGA_REGION_ID);
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="space-y-3 pt-1">
            <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                Address
            </p>

            {/* Locked location — always Zamboanga City */}
            <div className="rounded-md border border-border/60 bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground">
                City of Zamboanga, Zamboanga Peninsula (Region IX)
            </div>

            <div className="space-y-2">
                <Label htmlFor="barangay">Barangay</Label>
                <Select
                    value={value.barangay_id || ''}
                    onValueChange={v => onChange('barangay_id', v)}
                    disabled={loading}
                >
                    <SelectTrigger id="barangay">
                        <SelectValue placeholder={loading ? 'Loading barangays…' : 'Select barangay'} />
                    </SelectTrigger>
                    <SelectContent className="max-h-60">
                        {barangays.map(b => (
                            <SelectItem key={b.code} value={b.code}>
                                {b.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {errors?.barangay_id && (
                    <p className="text-xs text-destructive">{errors.barangay_id}</p>
                )}
            </div>

            <div className="space-y-2">
                <Label htmlFor="street">
                    Street / House No.{' '}
                    <span className="text-muted-foreground font-normal">(Optional)</span>
                </Label>
                <Input
                    id="street"
                    value={value.street}
                    onChange={e => onChange('street', e.target.value)}
                    onBlur={e => onChange('street', sanitizeText(e.target.value))}
                    placeholder="e.g. 123 Rizal St., Purok 5"
                />
                {errors?.street && (
                    <p className="text-xs text-destructive">{errors.street}</p>
                )}
            </div>
        </div>
    );
}
