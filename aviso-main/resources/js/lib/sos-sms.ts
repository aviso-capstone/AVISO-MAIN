import { toast } from '@/lib/toast';
import { type SosStepSms } from '@/types/models';

/**
 * The line under an SOS step's success toast: how many emergency contacts got
 * the SMS update. A failed text also gets its own warning (the admin should
 * call that contact; the Notifications page names them).
 */
export function smsStepDescription(sms: SosStepSms | null | undefined): string | undefined {
    if (!sms) return undefined;

    if (sms.failed > 0) {
        toast.error({
            title: `SMS update not sent to ${sms.failed} emergency contact${sms.failed === 1 ? '' : 's'}`,
            description: 'See Notifications for the number and call them directly.',
        });
    }

    if (sms.contacts === 0) return 'This rider has no emergency contacts to text.';
    if (sms.sent === 0) return undefined;

    return `Update texted to ${sms.sent} emergency contact${sms.sent === 1 ? '' : 's'}.`;
}
