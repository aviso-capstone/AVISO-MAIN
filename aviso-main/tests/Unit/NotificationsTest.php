<?php

namespace Tests\Unit;

use App\Models\EmergencyAlert;
use App\Models\EmergencyContact;
use App\Models\HazardLog;
use App\Models\User;
use App\Notifications\AdminNotifications;
use App\Notifications\AvisoNotification;
use App\Notifications\RiderNotifications;
use App\Services\EmergencyAlertService;
use App\Services\NotificationService;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Notifications pages (admin + rider) and the SMS updates to emergency
 * contacts. The production migrations use Postgres-only SQL, so this builds
 * minimal `users` and `notifications` tables in in-memory SQLite.
 */
class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('rider');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->string('category', 20);
            $table->string('context_key', 40)->nullable();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function user(string $role = 'admin', bool $active = true, string $first = 'Ana', string $last = 'Santos'): User
    {
        $username = $role . '_' . uniqid();

        return User::create([
            'first_name' => $first,
            'last_name'  => $last,
            'username'   => $username,
            'email'      => "{$username}@example.com",
            'password'   => 'Secret123',
            'role'       => $role,
            'is_active'  => $active,
        ]);
    }

    /** An SOS of rider Juan Dela Cruz, handled by admin Ana Santos via ZCDRRMO. */
    private function alert(array $attributes = []): EmergencyAlert
    {
        $alert = new EmergencyAlert(array_merge([
            'trigger_type'           => EmergencyAlert::TRIGGER_CRASH_IOT,
            'rider_code'             => 'juan01',
            'responder_agency'       => 'zcdrrmo',
            'eta_minutes'            => 10,
            'responder_first_name'   => 'Pedro',
            'responder_last_name'    => 'Reyes',
            'resolution_note'        => 'Private admin note',
        ], $attributes));
        $alert->id = 12;
        $alert->setRelation('user', new User(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'username' => 'juan01']));

        return $alert;
    }

    // ── What each notification says ──────────────────────────────────────

    public function test_admin_sos_steps_share_one_context_and_open_the_alert_on_the_map(): void
    {
        $admin = new User(['first_name' => 'Ana', 'last_name' => 'Santos']);
        $admin->id = 5;
        $alert = $this->alert();

        $triggered  = AdminNotifications::sosTriggered($alert, 'Tetuan');
        $dispatched = AdminNotifications::sosDispatched($alert, $admin);

        $this->assertSame(AvisoNotification::EMERGENCY, $triggered->category);
        $this->assertSame('Possible crash (IoT unit)', $triggered->title);
        $this->assertSame('Juan Dela Cruz (juan01) in Tetuan.', $triggered->body);
        $this->assertSame('sos:12', $triggered->contextKey);
        $this->assertSame('sos:12', $dispatched->contextKey);
        $this->assertSame('/map?alert=12', $dispatched->link);
        $this->assertSame('Ana Santos dispatched ZCDRRMO, about 10 min for Juan Dela Cruz.', $dispatched->body);
        $this->assertSame(5, $dispatched->actorId);
    }

    public function test_rider_notifications_never_name_admins_responders_or_the_note(): void
    {
        $alert = $this->alert(['outcome' => 'hospital', 'on_scene_at' => now()]);

        $all = [
            RiderNotifications::sosReceived($alert),
            RiderNotifications::sosDispatched($alert),
            RiderNotifications::sosOnScene($alert),
            RiderNotifications::sosResolved($alert),
        ];

        foreach ($all as $notification) {
            $this->assertSame(AvisoNotification::MY_SOS, $notification->category);
            foreach (['Ana', 'Santos', 'Pedro', 'Reyes', 'Private admin note'] as $secret) {
                $this->assertStringNotContainsString($secret, $notification->title . ' ' . $notification->body);
            }
        }

        $this->assertSame('ZCDRRMO, about 10 min.', $all[1]->body);
        $this->assertSame('Your emergency was resolved: Brought to hospital.', $all[3]->body);
    }

    public function test_rider_sos_notifications_never_open_the_sos_countdown_screen(): void
    {
        $alert = $this->alert();

        $this->assertNull(RiderNotifications::sosReceived($alert)->link);
        $this->assertNull(RiderNotifications::sosDispatched($alert)->link);
        $this->assertSame('crash-logs', RiderNotifications::sosResolved($alert)->link);
        $this->assertNull(RiderNotifications::sosResolved($this->alert(['trigger_type' => EmergencyAlert::TRIGGER_MANUAL]))->link);
    }

    public function test_hazard_notifications_read_naturally(): void
    {
        $hazard = new HazardLog(['type' => 'Pothole', 'haz_code' => 'POT-20261107-0001', 'area' => 'Tetuan']);
        $hazard->id = 7;

        $this->assertSame(
            'A pothole you detected in Tetuan was fixed and removed from the map.',
            RiderNotifications::hazardFixed($hazard)->body,
        );
        $this->assertSame('hazard:7', RiderNotifications::reportReviewed($hazard, true)->contextKey);
        $this->assertSame(AvisoNotification::MY_REPORTS, RiderNotifications::reportReviewed($hazard, false)->category);
    }

    // ── Who gets them ────────────────────────────────────────────────────

    public function test_every_active_admin_gets_it_and_the_actor_copy_is_already_read(): void
    {
        $actor       = $this->user(first: 'Ana');
        $other       = $this->user(first: 'Ben');
        $deactivated = $this->user(active: false, first: 'Cy');
        $rider       = $this->user('rider');

        app(NotificationService::class)->notifyAdmins(AdminNotifications::adminAdded($other, $actor));

        $this->assertSame(1, $actor->notifications()->count());
        $this->assertNotNull($actor->notifications()->first()->read_at);
        $this->assertSame(1, $other->unreadNotifications()->count());
        $this->assertSame(0, $deactivated->notifications()->count());
        $this->assertSame(0, $rider->notifications()->count());

        $saved = $other->notifications()->first();
        $this->assertSame(AvisoNotification::ACCOUNTS, $saved->category);
        $this->assertSame("user:{$other->id}", $saved->context_key);
    }

    public function test_the_actor_is_not_pinged_live_about_their_own_action(): void
    {
        $actor = $this->user();
        $other = $this->user();
        $notification = AdminNotifications::adminAdded($other, $actor);

        $this->assertNotContains('broadcast', $notification->via($actor));
        $this->assertContains('broadcast', $notification->via($other));
        $this->assertNotContains('broadcast', RiderNotifications::passwordReset()->via($this->user('rider')));
    }

    // ── Reading them ─────────────────────────────────────────────────────

    public function test_list_filters_by_category_and_unread(): void
    {
        $admin   = $this->user();
        $service = app(NotificationService::class);

        $service->notifyUser($admin, AdminNotifications::myDetailsChanged($this->user()));
        $service->notifyUser($admin, AdminNotifications::riderRegistered($this->user('rider')));

        $this->assertSame(2, $service->list($admin, [])->total());
        $this->assertSame(1, $service->list($admin, ['category' => AvisoNotification::MY_ACCOUNT])->total());

        $service->markRead($admin, $admin->notifications()->where('category', AvisoNotification::MY_ACCOUNT)->first()->id);
        $this->assertSame(1, $service->list($admin, ['unread' => true])->total());
        $this->assertSame(1, $service->unreadCount($admin));

        $service->markAllRead($admin);
        $this->assertSame(0, $service->unreadCount($admin));
    }

    public function test_nobody_can_mark_another_users_notification(): void
    {
        $owner   = $this->user();
        $other   = $this->user();
        $service = app(NotificationService::class);
        $service->notifyUser($owner, AdminNotifications::myDetailsChanged($other));

        $this->expectException(ModelNotFoundException::class);
        $service->markRead($other, $owner->notifications()->first()->id);
    }

    public function test_a_missing_notifications_table_never_breaks_anything(): void
    {
        Schema::drop('notifications');
        $admin   = $this->user();
        $service = app(NotificationService::class);

        $service->notifyAdmins(AdminNotifications::riderRegistered($this->user('rider')));

        $this->assertSame(0, $service->unreadCount($admin));
    }

    public function test_the_rider_api_lists_only_rider_categories(): void
    {
        $rider = $this->user('rider');
        app(NotificationService::class)->notifyUser($rider, RiderNotifications::passwordReset());

        $this->actingAs($rider, 'sanctum')
            ->getJson('/api/rider/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('notifications.0.category', AvisoNotification::ACCOUNT);

        $this->actingAs($rider, 'sanctum')
            ->getJson('/api/rider/notifications?category=emergency')
            ->assertUnprocessable();
    }

    // ── SMS updates to emergency contacts ────────────────────────────────

    public function test_each_step_has_a_short_sms_without_names_of_admins_responders_or_the_note(): void
    {
        $dispatched = EmergencyAlertService::stepMessage($this->alert(), EmergencyAlertService::STEP_DISPATCHED);
        $onScene    = EmergencyAlertService::stepMessage($this->alert(), EmergencyAlertService::STEP_ON_SCENE);
        $resolved   = EmergencyAlertService::stepMessage($this->alert(['outcome' => 'hospital']), EmergencyAlertService::STEP_RESOLVED);
        $false      = EmergencyAlertService::stepMessage($this->alert(['outcome' => 'false_alarm']), EmergencyAlertService::STEP_RESOLVED);

        $this->assertSame('AVISO update: Help is on the way for Juan Dela Cruz - ZCDRRMO, about 10 min.', $dispatched);
        $this->assertSame('AVISO update: Responders (ZCDRRMO) have reached Juan Dela Cruz.', $onScene);
        $this->assertSame("AVISO update: Juan Dela Cruz's emergency is resolved - Brought to hospital.", $resolved);
        $this->assertSame('AVISO update: The SOS from Juan Dela Cruz was a false alarm. No help is needed.', $false);

        foreach ([$dispatched, $onScene, $resolved, $false] as $sms) {
            foreach (['Ana', 'Pedro', 'Reyes', 'Private admin note'] as $secret) {
                $this->assertStringNotContainsString($secret, $sms);
            }
        }
    }

    public function test_unknown_eta_is_left_out(): void
    {
        $sms = EmergencyAlertService::stepMessage($this->alert(['eta_minutes' => null]), EmergencyAlertService::STEP_DISPATCHED);

        $this->assertSame('AVISO update: Help is on the way for Juan Dela Cruz - ZCDRRMO.', $sms);
    }

    public function test_the_longest_step_sms_is_still_one_message(): void
    {
        $alert = $this->alert([
            'responder_agency'       => EmergencyAlert::AGENCY_OTHER,
            'responder_agency_other' => str_repeat('Very Long Agency Name ', 5),
            'eta_minutes'            => EmergencyAlert::ETA_MORE_THAN_HOUR,
            'outcome'                => 'rider_ok',
        ]);
        $alert->setRelation('user', new User(['first_name' => str_repeat('Maximiliano', 3), 'last_name' => str_repeat('Dela Cruz', 3)]));

        foreach ([EmergencyAlertService::STEP_DISPATCHED, EmergencyAlertService::STEP_ON_SCENE, EmergencyAlertService::STEP_RESOLVED] as $step) {
            $this->assertLessThanOrEqual(160, mb_strlen(EmergencyAlertService::stepMessage($alert, $step)));
        }
    }

    public function test_a_failed_sms_tells_the_admins_who_to_call(): void
    {
        $admin = $this->user();

        $sms = $this->createMock(SmsService::class);
        $sms->method('send')->willReturnCallback(fn (string $number) => $number === '09170000001');
        $this->app->instance(SmsService::class, $sms);

        $alert = $this->alert();
        $alert->user->setRelation('emergencyContacts', collect([
            new EmergencyContact(['name' => 'Maria Dela Cruz', 'contact_number' => '09170000001']),
            new EmergencyContact(['name' => 'Jose Dela Cruz', 'contact_number' => '09170000002']),
        ]));

        $result = app(EmergencyAlertService::class)->textContactsAboutStep($alert, EmergencyAlertService::STEP_DISPATCHED);

        $this->assertSame(['contacts' => 2, 'sent' => 1, 'failed' => 1], $result);
        $failed = $admin->notifications()->first();
        $this->assertSame('sos:12', $failed->context_key);
        $this->assertSame('sos.sms_failed', $failed->data['event']);
        $this->assertStringContainsString('Jose Dela Cruz (09170000002)', $failed->data['body']);
    }

    public function test_a_rider_without_contacts_gets_no_sms(): void
    {
        $sms = $this->createMock(SmsService::class);
        $sms->expects($this->never())->method('send');
        $this->app->instance(SmsService::class, $sms);

        $alert = $this->alert();
        $alert->user->setRelation('emergencyContacts', collect());

        $this->assertSame(
            ['contacts' => 0, 'sent' => 0, 'failed' => 0],
            app(EmergencyAlertService::class)->textContactsAboutStep($alert, EmergencyAlertService::STEP_RESOLVED),
        );
    }
}
