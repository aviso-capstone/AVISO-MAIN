<?php

namespace App\Notifications;

use App\Models\EmergencyAlert;
use App\Models\EmergencyContact;
use App\Models\HazardLog;
use App\Models\HazardReport;
use App\Models\User;

/**
 * The admin website's notifications. Categories: Emergency, Hazard reports,
 * Accounts (sent to every active admin) and My account (only the admin whose
 * account another admin changed). Links are website paths.
 */
final class AdminNotifications
{
    // ── Emergency ────────────────────────────────────────────────────────

    public static function sosTriggered(EmergencyAlert $alert, ?string $barangayName): AvisoNotification
    {
        $title = match ($alert->trigger_type) {
            EmergencyAlert::TRIGGER_CRASH_IOT   => 'Possible crash (IoT unit)',
            EmergencyAlert::TRIGGER_CRASH_PHONE => 'Possible crash (phone)',
            default                             => 'New SOS',
        };
        $where = $barangayName ? " in {$barangayName}" : '';

        return self::sos($alert, 'sos.triggered', $title, self::riderName($alert->user) . " ({$alert->rider_code}){$where}.");
    }

    public static function sosDispatched(EmergencyAlert $alert, User $admin): AvisoNotification
    {
        $help = implode(', ', array_filter([$alert->agencyLabel(), $alert->etaLabel()])) ?: 'responders';

        return self::sos(
            $alert, 'sos.dispatched', 'Help dispatched',
            self::name($admin) . " dispatched {$help} for " . self::riderName($alert->user) . '.',
            $admin,
        );
    }

    public static function sosOnScene(EmergencyAlert $alert, ?User $admin): AvisoNotification
    {
        $who = $alert->agencyLabel() ?? 'Responders';
        $by  = $admin ? ' Marked by ' . self::name($admin) . '.' : '';

        return self::sos(
            $alert, 'sos.on_scene', 'Responders on scene',
            "{$who} reached " . self::riderName($alert->user) . ".{$by}",
            $admin,
        );
    }

    public static function sosResolved(EmergencyAlert $alert, ?User $admin): AvisoNotification
    {
        $outcome = $alert->outcomeLabel() ?? 'Resolved';
        $by      = $admin ? ' Closed by ' . self::name($admin) . '.' : '';

        return self::sos(
            $alert, 'sos.resolved', 'SOS resolved',
            self::riderName($alert->user) . "'s emergency: {$outcome}.{$by}",
            $admin,
        );
    }

    /** A text to one emergency contact did not go out: the admin should call them. */
    public static function smsFailed(EmergencyAlert $alert, EmergencyContact $contact): AvisoNotification
    {
        return self::sos(
            $alert, 'sos.sms_failed', 'SMS to emergency contact failed',
            "The text to {$contact->name} ({$contact->contact_number}) about " . self::riderName($alert->user)
            . "'s SOS was not sent. Call them directly.",
        );
    }

    private static function sos(EmergencyAlert $alert, string $event, string $title, string $body, ?User $actor = null): AvisoNotification
    {
        return new AvisoNotification(
            AvisoNotification::EMERGENCY, $event, $title, $body,
            "sos:{$alert->id}", "/map?alert={$alert->id}", $actor?->id,
        );
    }

    // ── Hazard reports ───────────────────────────────────────────────────

    public static function hazardReportSubmitted(HazardReport $report, HazardLog $hazard, User $rider): AvisoNotification
    {
        return new AvisoNotification(
            AvisoNotification::HAZARD_REPORTS, 'hazard_report.submitted', 'Hazard reported as gone',
            "{$rider->username} reported {$hazard->type} {$hazard->haz_code} as no longer there, with a photo.",
            "hazard:{$hazard->id}", '/map',
        );
    }

    public static function hazardReportReviewed(HazardReport $report, HazardLog $hazard, User $admin, bool $confirmed): AvisoNotification
    {
        $name = self::name($admin);

        return new AvisoNotification(
            AvisoNotification::HAZARD_REPORTS,
            $confirmed ? 'hazard_report.confirmed' : 'hazard_report.rejected',
            $confirmed ? 'Report confirmed' : 'Report rejected',
            $confirmed
                ? "{$name} confirmed {$hazard->type} {$hazard->haz_code} is gone. It was resolved."
                : "{$name} checked {$hazard->type} {$hazard->haz_code}: it is still there.",
            "hazard:{$hazard->id}", '/hazards', $admin->id,
        );
    }

    // ── Accounts ─────────────────────────────────────────────────────────

    public static function riderRegistered(User $rider): AvisoNotification
    {
        return self::accounts('accounts.rider_registered', 'New rider signed up', self::riderName($rider) . " (@{$rider->username}).", $rider, '/users');
    }

    public static function adminAdded(User $newAdmin, User $actor): AvisoNotification
    {
        return self::accounts('accounts.admin_added', 'Admin added', self::name($actor) . ' added ' . self::name($newAdmin) . ' as an admin.', $newAdmin, '/users?role=admin', $actor);
    }

    public static function adminActiveChanged(User $admin, bool $active, User $actor): AvisoNotification
    {
        return $active
            ? self::accounts('accounts.admin_reactivated', 'Admin reactivated', self::name($actor) . ' reactivated ' . self::name($admin) . '.', $admin, '/users?role=admin', $actor)
            : self::accounts('accounts.admin_deactivated', 'Admin deactivated', self::name($actor) . ' deactivated ' . self::name($admin) . '. They can no longer log in.', $admin, '/users?role=admin', $actor);
    }

    public static function riderDeleted(User $rider, User $actor): AvisoNotification
    {
        return self::accounts('accounts.rider_deleted', 'Rider deleted', self::name($actor) . ' deleted rider ' . self::riderName($rider) . " (@{$rider->username}).", $rider, '/users', $actor);
    }

    private static function accounts(string $event, string $title, string $body, User $subject, string $link, ?User $actor = null): AvisoNotification
    {
        return new AvisoNotification(AvisoNotification::ACCOUNTS, $event, $title, $body, "user:{$subject->id}", $link, $actor?->id);
    }

    // ── My account (another admin changed it) ────────────────────────────

    public static function myPasswordChanged(User $actor): AvisoNotification
    {
        return new AvisoNotification(
            AvisoNotification::MY_ACCOUNT, 'my_account.password_changed', 'Your password was changed',
            self::name($actor) . ' set a new password for your account. If you did not expect this, talk to them.',
            'my-account', '/settings',
        );
    }

    public static function myDetailsChanged(User $actor): AvisoNotification
    {
        return new AvisoNotification(
            AvisoNotification::MY_ACCOUNT, 'my_account.details_changed', 'Your account was updated',
            self::name($actor) . ' updated your account details.',
            'my-account', '/settings',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function name(?User $user): string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : 'An admin';
    }

    private static function riderName(?User $rider): string
    {
        return $rider ? trim("{$rider->first_name} {$rider->last_name}") : 'A rider';
    }
}
