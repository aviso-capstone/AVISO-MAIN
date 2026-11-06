<?php

namespace App\Notifications;

use App\Models\EmergencyAlert;
use App\Models\HazardLog;

/**
 * The rider app's notifications. Categories: My SOS, My reports, My hazards,
 * Account. Never names an admin or a responder (same rule as the SOS status
 * card). Links are app screen keys the app maps to its own routes.
 */
final class RiderNotifications
{
    public const SCREEN_CRASH_LOGS         = 'crash-logs';
    public const SCREEN_MAP                = 'map';
    public const SCREEN_HAZARD_LOGS        = 'hazard-logs';
    public const SCREEN_PROFILE            = 'profile';
    public const SCREEN_EMERGENCY_CONTACTS = 'emergency-contacts';

    // ── My SOS ───────────────────────────────────────────────────────────

    public static function sosReceived(EmergencyAlert $alert): AvisoNotification
    {
        return self::sos($alert, 'sos.received', 'SOS received', 'The AVISO admin received your SOS and is contacting responders for you.');
    }

    public static function sosDispatched(EmergencyAlert $alert): AvisoNotification
    {
        $help = implode(', ', array_filter([$alert->agencyLabel(), $alert->etaLabel()]));

        return self::sos($alert, 'sos.dispatched', 'Help is on the way', $help !== '' ? "{$help}." : 'Responders were dispatched to your location.');
    }

    public static function sosOnScene(EmergencyAlert $alert): AvisoNotification
    {
        $who = $alert->agencyLabel() ?? 'Responders';

        return self::sos($alert, 'sos.on_scene', 'Responders arrived', "{$who} is at your location.");
    }

    public static function sosResolved(EmergencyAlert $alert): AvisoNotification
    {
        $outcome = $alert->outcomeLabel();

        return self::sos(
            $alert, 'sos.resolved', 'Emergency resolved',
            $outcome ? "Your emergency was resolved: {$outcome}." : 'Your emergency was resolved.',
            // Crash logs list crashes only; a manual SOS has nothing to open.
            $alert->trigger_type === EmergencyAlert::TRIGGER_MANUAL ? null : self::SCREEN_CRASH_LOGS,
        );
    }

    /**
     * SOS steps open nothing: the app's SOS screen starts a new countdown, so
     * it must never be opened from here.
     */
    private static function sos(EmergencyAlert $alert, string $event, string $title, string $body, ?string $screen = null): AvisoNotification
    {
        return new AvisoNotification(AvisoNotification::MY_SOS, $event, $title, $body, "sos:{$alert->id}", $screen);
    }

    // ── My reports ───────────────────────────────────────────────────────

    public static function reportReviewed(HazardLog $hazard, bool $confirmed): AvisoNotification
    {
        return new AvisoNotification(
            AvisoNotification::MY_REPORTS,
            $confirmed ? 'report.confirmed' : 'report.rejected',
            $confirmed ? 'Report confirmed' : 'Hazard still there',
            $confirmed
                ? "Thank you! {$hazard->type} {$hazard->haz_code} was removed from the map."
                : "An admin checked {$hazard->type} {$hazard->haz_code}: it is still there, so it stays on the map.",
            "hazard:{$hazard->id}", self::SCREEN_MAP,
        );
    }

    // ── My hazards ───────────────────────────────────────────────────────

    public static function hazardFixed(HazardLog $hazard): AvisoNotification
    {
        $where = $hazard->area ? " in {$hazard->area}" : '';
        $type  = strtolower($hazard->type);

        return new AvisoNotification(
            AvisoNotification::MY_HAZARDS, 'hazard.fixed', 'Hazard fixed',
            "A {$type} you detected{$where} was fixed and removed from the map.",
            "hazard:{$hazard->id}", self::SCREEN_HAZARD_LOGS,
        );
    }

    // ── Account ──────────────────────────────────────────────────────────

    public static function passwordReset(): AvisoNotification
    {
        return self::account('account.password_reset', 'Password reset', 'Your password was changed with Forgot Password. If this was not you, contact AVISO.', self::SCREEN_PROFILE);
    }

    public static function detailsUpdated(): AvisoNotification
    {
        return self::account('account.details_updated', 'Account updated', 'An AVISO admin updated your account details.', self::SCREEN_PROFILE);
    }

    public static function contactAdded(string $contactName): AvisoNotification
    {
        return self::account('account.contact_added', 'Emergency contact added', "An AVISO admin added {$contactName} as your emergency contact.", self::SCREEN_EMERGENCY_CONTACTS);
    }

    public static function contactRemoved(string $contactName): AvisoNotification
    {
        return self::account('account.contact_removed', 'Emergency contact removed', "An AVISO admin removed {$contactName} from your emergency contacts.", self::SCREEN_EMERGENCY_CONTACTS);
    }

    private static function account(string $event, string $title, string $body, string $screen): AvisoNotification
    {
        return new AvisoNotification(AvisoNotification::ACCOUNT, $event, $title, $body, 'account', $screen);
    }
}
