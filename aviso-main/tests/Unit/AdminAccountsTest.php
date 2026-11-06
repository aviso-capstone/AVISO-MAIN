<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Users page: admins are added by another admin, every admin is equal, roles
 * never change, and admins are deactivated (never deleted).
 *
 * The production migrations use Postgres-only SQL, so this builds a minimal
 * `users` table in the in-memory SQLite test database.
 */
class AdminAccountsTest extends TestCase
{
    private const PASSWORD = 'Secret123';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('contact_number')->nullable();
            $table->text('address')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('password');
            $table->string('role')->default('rider');
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('street')->nullable();
            $table->string('barangay_id')->nullable();
            $table->string('city_id')->nullable();
            $table->string('province_id')->nullable();
            $table->string('region_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    private function user(string $role = 'admin', bool $active = true, ?string $username = null): User
    {
        $username ??= $role . '_' . uniqid();

        return User::create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'username'   => $username,
            'email'      => "{$username}@example.com",
            'password'   => self::PASSWORD,
            'role'       => $role,
            'is_active'  => $active,
        ]);
    }

    private function newAdminForm(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Ana',
            'last_name'             => 'Santos',
            'username'              => 'anasantos',
            'email'                 => 'ana@example.com',
            'password'              => 'NewAdmin123',
            'password_confirmation' => 'NewAdmin123',
        ], $overrides);
    }

    // ── Add admin ────────────────────────────────────────────────────────

    public function test_add_admin_always_creates_an_active_admin(): void
    {
        $this->actingAs($this->user())
            ->post('/users', $this->newAdminForm(['role' => 'rider']))
            ->assertRedirect(route('users.index', ['role' => 'admin']));

        $created = User::where('username', 'anasantos')->firstOrFail();
        $this->assertSame('admin', $created->role);
        $this->assertTrue($created->is_active);
        $this->assertNotNull($created->email_verified_at);
    }

    public function test_the_new_admin_can_log_in_with_the_password_that_was_set(): void
    {
        $this->actingAs($this->user())->post('/users', $this->newAdminForm());
        auth()->logout();

        $this->post('/login', ['username' => 'anasantos', 'password' => 'NewAdmin123'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_add_admin_needs_a_strong_confirmed_password(): void
    {
        $this->actingAs($this->user())
            ->post('/users', $this->newAdminForm(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors('password');

        $this->actingAs($this->user())
            ->post('/users', $this->newAdminForm(['password_confirmation' => 'Different123']))
            ->assertSessionHasErrors('password');
    }

    // ── Roles never change ───────────────────────────────────────────────

    public function test_editing_cannot_change_a_role(): void
    {
        $actor = $this->user();
        $admin = $this->user();
        $rider = $this->user('rider');

        $this->actingAs($actor)->put("/users/{$admin->id}", [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'username'   => $admin->username, 'email' => $admin->email,
            'role'       => 'rider',
        ]);
        $this->actingAs($actor)->put("/users/{$rider->id}", [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'role' => 'admin',
        ]);

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('rider', $rider->fresh()->role);
    }

    // ── Nobody is deleted ───────────────────────────────────────────────

    public function test_there_is_no_delete_for_admins_or_riders(): void
    {
        $actor = $this->user();
        $admin = $this->user();
        $rider = $this->user('rider');

        $this->actingAs($actor)->delete("/users/{$admin->id}")->assertStatus(405);
        $this->actingAs($actor)->delete("/users/{$rider->id}")->assertStatus(405);

        $this->assertNotNull($admin->fresh());
        $this->assertNotNull($rider->fresh());
    }

    // ── Deactivate / reactivate ──────────────────────────────────────────

    public function test_an_admin_can_be_deactivated_and_reactivated(): void
    {
        $actor = $this->user();
        $admin = $this->user();

        $this->actingAs($actor)->patch("/users/{$admin->id}/active", ['is_active' => false])
            ->assertSessionHas('success', 'Juan Dela Cruz was deactivated.');
        $this->assertFalse($admin->fresh()->is_active);

        $this->actingAs($actor)->patch("/users/{$admin->id}/active", ['is_active' => true])
            ->assertSessionHas('success', 'Juan Dela Cruz was reactivated.');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $actor = $this->user();
        $this->user(); // another active admin exists

        $this->actingAs($actor)->patch("/users/{$actor->id}/active", ['is_active' => false])
            ->assertSessionHas('error', 'You cannot deactivate your own account.');
        $this->assertTrue($actor->fresh()->is_active);
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $actor = $this->user(active: false);
        $last  = $this->user();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('You cannot deactivate the last active admin.');

        app(UserService::class)->setActive($last, false, $actor);
    }

    public function test_a_rider_can_be_deactivated_and_reactivated_and_keeps_their_account(): void
    {
        $actor = $this->user();
        $rider = $this->user('rider');

        $this->actingAs($actor)->patch("/users/{$rider->id}/active", ['is_active' => false])
            ->assertSessionHas('success', 'Juan Dela Cruz was deactivated.');
        $this->assertFalse($rider->fresh()->is_active);

        $this->actingAs($actor)->patch("/users/{$rider->id}/active", ['is_active' => true])
            ->assertSessionHas('success', 'Juan Dela Cruz was reactivated.');
        $this->assertTrue($rider->fresh()->is_active);
    }

    // ── Messages for a deactivated rider (app) ───────────────────────────

    private function riderTokensTable(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function verifiedRider(bool $active = true): User
    {
        $rider = $this->user('rider', $active);
        $rider->forceFill(['email_verified_at' => now()])->save();

        return $rider;
    }

    public function test_a_deactivated_rider_with_the_right_password_sees_the_deactivated_message(): void
    {
        $this->riderTokensTable();
        $rider = $this->verifiedRider(active: false);

        $this->postJson('/api/rider/auth/login', ['email' => $rider->username, 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertJson(['message' => User::DEACTIVATED_MESSAGE, 'code' => 'account_deactivated']);

        $this->assertSame(0, $rider->tokens()->count(), 'no app login was created');
    }

    public function test_a_wrong_rider_password_never_reveals_that_the_account_is_deactivated(): void
    {
        $this->riderTokensTable();
        $rider = $this->verifiedRider(active: false);

        $this->postJson('/api/rider/auth/login', ['email' => $rider->username, 'password' => 'Wrong12345'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_an_active_rider_can_still_log_in(): void
    {
        $this->riderTokensTable();
        $rider = $this->verifiedRider();

        $this->postJson('/api/rider/auth/login', ['email' => $rider->username, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_a_logged_in_rider_who_gets_deactivated_is_signed_out_of_the_app_with_a_message(): void
    {
        $this->riderTokensTable();
        $rider = $this->verifiedRider();
        $token = $rider->createToken('rider-app')->plainTextToken;

        $this->withToken($token)->getJson('/api/rider/notifications/unread-count')->assertOk();

        $rider->update(['is_active' => false]);
        $this->app['auth']->forgetGuards(); // a real app request loads the user fresh

        // Every rider API (SOS, hazards, trips…) goes through the same check.
        $this->withToken($token)->postJson('/api/rider/emergency/sos', ['latitude' => 6.9, 'longitude' => 122.07])
            ->assertStatus(403)
            ->assertJson(['message' => User::DEACTIVATED_SESSION_MESSAGE, 'code' => 'account_deactivated']);

        $this->assertSame(0, $rider->tokens()->count(), 'the app login was ended');
    }

    // ── Messages for a deactivated admin ─────────────────────────────────

    public function test_a_deactivated_admin_with_the_right_password_sees_the_deactivated_message(): void
    {
        $admin = $this->user(active: false);

        $this->post('/login', ['username' => $admin->username, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['username' => User::DEACTIVATED_MESSAGE]);
        $this->assertGuest();
    }

    public function test_a_wrong_password_never_reveals_that_the_account_is_deactivated(): void
    {
        $admin = $this->user(active: false);

        $this->post('/login', ['username' => $admin->username, 'password' => 'Wrong12345'])
            ->assertSessionHasErrors(['username' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_a_logged_in_admin_who_gets_deactivated_is_sent_to_login_with_a_message(): void
    {
        $admin = $this->user(active: false);

        $this->actingAs($admin)->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('deactivated', User::DEACTIVATED_SESSION_MESSAGE);
        $this->assertGuest();
    }

    public function test_an_account_from_before_the_migration_counts_as_active(): void
    {
        $user = new User(['role' => 'admin']);

        $this->assertFalse($user->isDeactivated());
    }

    // ── Tabs ─────────────────────────────────────────────────────────────

    public function test_each_tab_lists_only_its_own_accounts_and_stats_count_active_admins(): void
    {
        $this->user();
        $this->user(active: false);
        $this->user('rider');

        $service = app(UserService::class);

        $this->assertSame(['admin', 'admin'], $service->getPaginatedUsers(['role' => 'admin'])->pluck('role')->all());
        $this->assertSame(['rider'], $service->getPaginatedUsers([])->pluck('role')->all());
        $this->assertSame(['riders' => 1, 'admins' => 2, 'active_admins' => 1], $service->getUserStats());
    }
}
