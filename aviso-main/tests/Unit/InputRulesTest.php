<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\CrashLogFilterRequest;
use App\Http\Requests\Admin\HazardLogFilterRequest;
use App\Http\Requests\Admin\ResetPasswordRequest as AdminResetPasswordRequest;
use App\Http\Requests\Admin\SosAlertFilterRequest;
use App\Http\Requests\Admin\StoreEmergencyContactRequest as AdminStoreContactRequest;
use App\Http\Requests\Admin\UserFilterRequest;
use App\Http\Requests\Admin\VerifyResetOtpRequest;
use App\Http\Requests\Rider\EmailRequest;
use App\Http\Requests\Rider\EndTripRequest;
use App\Http\Requests\Rider\LoginRequest;
use App\Http\Requests\Rider\ResetPasswordRequest;
use App\Http\Requests\Rider\RiderEventFilterRequest;
use App\Http\Requests\Rider\SosRequest;
use App\Http\Requests\Rider\StoreEmergencyContactRequest;
use App\Http\Requests\Rider\StoreHazardLogRequest;
use App\Http\Requests\Rider\StoreRiderEventRequest;
use App\Http\Requests\Rider\VerifyOtpRequest;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RiderAuthService;
use App\Support\InputRules;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Input sanitation and limits: every kind of typed input (names, usernames,
 * emails, phone numbers, passwords, phone times, filters) is cleaned the same
 * way and limited the same way on the admin website and the rider app.
 */
class InputRulesTest extends TestCase
{
    /**
     * Runs a Form Request the way Laravel does: tidy the input first, then
     * validate it. Returns the cleaned data and the error messages by field.
     *
     * @param  class-string<FormRequest>  $class
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function check(string $class, array $data): array
    {
        $request = $class::create('/', 'POST', $data);
        $request->setContainer($this->app);

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());

        return [$request->all(), $validator->errors()->toArray()];
    }

    /** Validates one field against a list of rules. */
    private function passes(array $rules, mixed $value): bool
    {
        return Validator::make(['v' => $value], ['v' => $rules])->passes();
    }

    // ── Names ────────────────────────────────────────────────────────────────

    public function test_person_names_allow_letters_accents_and_name_punctuation(): void
    {
        foreach (['Juan', 'Dela Cruz', "O'Brien", 'Ma. Teresa', 'Jean-Luc', 'Peña', 'Ñino'] as $name) {
            $this->assertTrue($this->passes(InputRules::personName(), $name), $name);
        }
    }

    public function test_person_names_reject_numbers_symbols_html_and_long_values(): void
    {
        foreach (['Juan2', 'J@n', '<script>', 'Juan;DROP', 'Ana_Marie', str_repeat('a', 51)] as $name) {
            $this->assertFalse($this->passes(InputRules::personName(), $name), $name);
        }
        $this->assertTrue($this->passes(InputRules::personName(), str_repeat('a', 50)));
    }

    public function test_contact_names_allow_numbers_and_brackets_from_the_phone_book(): void
    {
        $this->assertTrue($this->passes(InputRules::contactName(), 'Kuya Ben (Work)'));
        $this->assertTrue($this->passes(InputRules::contactName(), 'Mama 2'));
        $this->assertFalse($this->passes(InputRules::contactName(), 'A'), 'one letter is too short');
        $this->assertFalse($this->passes(InputRules::contactName(), 'Ben <b>'));
        $this->assertFalse($this->passes(InputRules::contactName(), 'Ben 😀'));
    }

    // ── Usernames, emails, street, search ────────────────────────────────────

    public function test_usernames_are_3_to_30_letters_numbers_or_underscores(): void
    {
        $this->assertTrue($this->passes(InputRules::username(), 'rider_01'));
        $this->assertFalse($this->passes(InputRules::username(), 'ab'));
        $this->assertFalse($this->passes(InputRules::username(), str_repeat('a', 31)));
        $this->assertFalse($this->passes(InputRules::username(), 'rider 01'));
        $this->assertFalse($this->passes(InputRules::username(), 'rider.01'));
        $this->assertFalse($this->passes(InputRules::username(), 'ri<der'));
    }

    public function test_emails_must_be_valid_and_at_most_100_characters(): void
    {
        $this->assertTrue($this->passes(InputRules::email(), 'juan@example.com'));
        $this->assertFalse($this->passes(InputRules::email(), 'juan@'));
        $this->assertFalse($this->passes(InputRules::email(), str_repeat('a', 95) . '@x.com'));
    }

    public function test_street_and_search_have_length_limits(): void
    {
        $this->assertTrue($this->passes(InputRules::street(), str_repeat('a', 150)));
        $this->assertFalse($this->passes(InputRules::street(), str_repeat('a', 151)));
        $this->assertTrue($this->passes(InputRules::search(), str_repeat('a', 100)));
        $this->assertFalse($this->passes(InputRules::search(), str_repeat('a', 101)));
    }

    // ── Passwords ────────────────────────────────────────────────────────────

    public function test_new_passwords_need_8_to_64_characters_with_upper_and_lower_case(): void
    {
        $ok = fn (string $p) => Validator::make(['password' => $p, 'password_confirmation' => $p], ['password' => InputRules::password()])->passes();

        $this->assertTrue($ok('Secret123'));
        $this->assertFalse($ok('Short1A'), 'under 8');
        $this->assertFalse($ok('alllowercase'), 'no uppercase');
        $this->assertFalse($ok('ALLUPPERCASE'), 'no lowercase');
        $this->assertFalse($ok('Aa' . str_repeat('x', 63)), 'over 64');
    }

    public function test_password_confirmation_must_match(): void
    {
        $v = Validator::make(
            ['password' => 'Secret123', 'password_confirmation' => 'Secret124'],
            ['password' => InputRules::password()],
        );
        $this->assertTrue($v->fails());
    }

    public function test_rider_and_admin_reset_password_use_the_same_password_rule(): void
    {
        [, $rider] = $this->check(ResetPasswordRequest::class, [
            'reset_token' => 'abc', 'password' => 'weakpass', 'password_confirmation' => 'weakpass',
        ]);
        [, $admin] = $this->check(AdminResetPasswordRequest::class, [
            'password' => 'weakpass', 'password_confirmation' => 'weakpass',
        ]);
        $this->assertArrayHasKey('password', $rider);
        $this->assertArrayHasKey('password', $admin);

        [, $none] = $this->check(ResetPasswordRequest::class, [
            'reset_token' => 'abc', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ]);
        $this->assertSame([], $none);
    }

    // ── Phone numbers and contacts (tidy, then check) ───────────────────────

    public function test_contact_numbers_are_turned_into_plus_63_format(): void
    {
        foreach (['09171234567', '9171234567', '+639171234567', '0917 123 4567', '0917-123-4567'] as $typed) {
            [$data, $errors] = $this->check(StoreEmergencyContactRequest::class, [
                'name' => 'Maria', 'contact_number' => $typed,
            ]);
            $this->assertSame('+639171234567', $data['contact_number'], $typed);
            $this->assertArrayNotHasKey('contact_number', $errors, $typed);
        }
    }

    public function test_landlines_short_numbers_and_letters_are_rejected_with_a_clear_message(): void
    {
        foreach (['028123456', '0917123', 'abcdefghijk', '+1 555 123 4567'] as $typed) {
            [, $errors] = $this->check(StoreEmergencyContactRequest::class, [
                'name' => 'Maria', 'contact_number' => $typed,
            ]);
            $this->assertArrayHasKey('contact_number', $errors, $typed);
            $this->assertStringContainsString('Philippine mobile', $errors['contact_number'][0]);
        }
    }

    public function test_contact_name_and_relationship_are_tidied_and_limited(): void
    {
        [$data, $errors] = $this->check(AdminStoreContactRequest::class, [
            'name' => '  Maria    Santos ', 'relationship' => ' Mother ', 'contact_number' => '09171234567',
        ]);
        $this->assertSame('Maria Santos', $data['name']);
        $this->assertSame('Mother', $data['relationship']);
        $this->assertSame([], $errors);

        [, $errors] = $this->check(StoreEmergencyContactRequest::class, [
            'name' => 'Maria', 'relationship' => 'Mother1', 'contact_number' => '09171234567',
        ]);
        $this->assertArrayHasKey('relationship', $errors);

        [, $errors] = $this->check(StoreEmergencyContactRequest::class, [
            'name' => 'Maria', 'relationship' => str_repeat('a', 31), 'contact_number' => '09171234567',
        ]);
        $this->assertArrayHasKey('relationship', $errors);
    }

    // ── Login and codes ──────────────────────────────────────────────────────

    public function test_login_and_code_requests_trim_and_lowercase_emails(): void
    {
        [$data] = $this->check(LoginRequest::class, ['email' => '  Juan@Example.COM ', 'password' => 'x']);
        $this->assertSame('juan@example.com', $data['email']);

        [$data] = $this->check(LoginRequest::class, ['email' => ' Rider_01 ', 'password' => 'x']);
        $this->assertSame('Rider_01', $data['email'], 'usernames keep their case');

        foreach ([EmailRequest::class, VerifyOtpRequest::class, VerifyResetOtpRequest::class] as $class) {
            [$data] = $this->check($class, ['email' => ' Juan@Example.com ', 'otp' => '123456']);
            $this->assertSame('juan@example.com', $data['email'], $class);
        }
    }

    public function test_login_fields_have_max_lengths(): void
    {
        [, $errors] = $this->check(LoginRequest::class, [
            'email' => str_repeat('a', 101), 'password' => str_repeat('a', 65),
        ]);
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_codes_must_be_exactly_6_digits(): void
    {
        foreach (['12345', '1234567', '12a456', ' 12345'] as $otp) {
            [, $errors] = $this->check(VerifyOtpRequest::class, ['email' => 'a@b.com', 'otp' => $otp]);
            $this->assertArrayHasKey('otp', $errors, $otp);
        }
        [, $errors] = $this->check(VerifyResetOtpRequest::class, ['email' => 'a@b.com', 'otp' => '12345a']);
        $this->assertArrayHasKey('otp', $errors);

        [, $errors] = $this->check(VerifyOtpRequest::class, ['email' => 'a@b.com', 'otp' => '123456']);
        $this->assertSame([], $errors);
    }

    // ── Times sent by the phone ──────────────────────────────────────────────

    public function test_hazard_and_ride_event_times_cannot_be_far_in_the_future_or_too_old(): void
    {
        $hazard = ['type' => 'pothole', 'latitude' => 6.9, 'longitude' => 122.07, 'confidence' => 80];
        $event  = ['event_uid' => 'abc_1', 'event_type' => 'crash', 'latitude' => 6.9, 'longitude' => 122.07, 'acceleration_peak' => 4];

        foreach ([[StoreHazardLogRequest::class, $hazard], [StoreRiderEventRequest::class, $event]] as [$class, $base]) {
            [, $errors] = $this->check($class, $base + ['detected_at' => now()->addMinutes(2)->toIso8601String()]);
            $this->assertArrayNotHasKey('detected_at', $errors, "$class small clock drift is fine");

            [, $errors] = $this->check($class, $base + ['detected_at' => now()->subDays(29)->toIso8601String()]);
            $this->assertArrayNotHasKey('detected_at', $errors, "$class offline save 29 days old is fine");

            [, $errors] = $this->check($class, $base + ['detected_at' => now()->addHour()->toIso8601String()]);
            $this->assertArrayHasKey('detected_at', $errors, "$class 1 hour ahead");

            [, $errors] = $this->check($class, $base + ['detected_at' => now()->subDays(31)->toIso8601String()]);
            $this->assertArrayHasKey('detected_at', $errors, "$class 31 days old");
        }
    }

    public function test_hazard_distance_is_limited_to_the_camera_range(): void
    {
        $hazard = ['type' => 'pothole', 'latitude' => 6.9, 'longitude' => 122.07, 'confidence' => 80];

        [, $errors] = $this->check(StoreHazardLogRequest::class, $hazard + ['distance' => 25]);
        $this->assertArrayNotHasKey('distance', $errors);

        [, $errors] = $this->check(StoreHazardLogRequest::class, $hazard + ['distance' => 5000]);
        $this->assertArrayHasKey('distance', $errors);
    }

    public function test_an_sos_is_never_refused_because_of_a_wrong_phone_clock(): void
    {
        $base = ['latitude' => 6.9, 'longitude' => 122.07];

        foreach ([now()->addDay()->toIso8601String(), now()->subYear()->toIso8601String(), 'not a date'] as $bad) {
            [$data, $errors] = $this->check(SosRequest::class, $base + ['triggered_at' => $bad]);
            $this->assertSame([], $errors, "SOS with triggered_at=$bad must still be accepted");
            $this->assertNull($data['triggered_at'], 'the server uses its own time instead');
        }

        $good = now()->subMinute()->toIso8601String();
        [$data, $errors] = $this->check(SosRequest::class, $base + ['triggered_at' => $good]);
        $this->assertSame([], $errors);
        $this->assertSame($good, $data['triggered_at'], 'a normal time is kept (it is the duplicate key)');
    }

    public function test_trip_route_point_times_must_be_dates(): void
    {
        $base = ['latitude' => 6.9, 'longitude' => 122.07];

        $request = EndTripRequest::create('/', 'PUT', $base + ['route_points' => [['lat' => 6.9, 'lng' => 122.07, 'ts' => 'garbage']]]);
        $errors  = Validator::make($request->all(), $request->rules())->errors()->toArray();
        $this->assertArrayHasKey('route_points.0.ts', $errors);
    }

    // ── Filters and search boxes (admin pages, rider lists) ─────────────────

    public function test_admin_filters_only_accept_known_values(): void
    {
        foreach ([UserFilterRequest::class, SosAlertFilterRequest::class, CrashLogFilterRequest::class, HazardLogFilterRequest::class] as $class) {
            [, $errors] = $this->check($class, ['search' => str_repeat('a', 101)]);
            $this->assertArrayHasKey('search', $errors, "$class long search");

            if ($class !== UserFilterRequest::class) { // the Users page always shows 15
                [, $errors] = $this->check($class, ['per_page' => 1000]);
                $this->assertArrayHasKey('per_page', $errors, "$class per_page 1000");
            }

            [, $errors] = $this->check($class, ['search' => 'juan', 'per_page' => 25]);
            $this->assertSame([], $errors, "$class normal filter");
        }

        [, $errors] = $this->check(HazardLogFilterRequest::class, ['sort' => 'password;--']);
        $this->assertArrayHasKey('sort', $errors);
        [, $errors] = $this->check(HazardLogFilterRequest::class, ['barangay' => "1 OR 1=1"]);
        $this->assertArrayHasKey('barangay', $errors);
    }

    public function test_rider_event_list_checks_dates_and_page_size(): void
    {
        [, $errors] = $this->check(RiderEventFilterRequest::class, ['from' => '2026-11-07', 'to' => '2026-11-01']);
        $this->assertArrayHasKey('to', $errors, '"to" before "from"');

        [, $errors] = $this->check(RiderEventFilterRequest::class, ['to' => '2026-11-07']);
        $this->assertSame([], $errors, '"to" alone is fine');

        [, $errors] = $this->check(RiderEventFilterRequest::class, ['per_page' => 100000]);
        $this->assertArrayHasKey('per_page', $errors);

        [, $errors] = $this->check(RiderEventFilterRequest::class, ['per_page' => 25, 'from' => '2026-11-01', 'to' => '2026-11-07']);
        $this->assertSame([], $errors, 'what the app sends (per_page=25)');
    }

    // ── Too many tries ──────────────────────────────────────────────────────

    public function test_rider_auth_routes_are_rate_limited(): void
    {
        $expected = [
            'api/rider/auth/login'                  => 'throttle:6,1',
            'api/rider/auth/register'               => 'throttle:3,1',
            'api/rider/auth/verify-otp'             => 'throttle:10,1',
            'api/rider/auth/resend-otp'             => 'throttle:3,1',
            'api/rider/auth/forgot-password'        => 'throttle:3,1',
            'api/rider/auth/forgot-password/verify' => 'throttle:10,1',
            'api/rider/auth/forgot-password/reset'  => 'throttle:10,1',
            'api/rider/tts'                         => 'throttle:20,1',
        ];

        foreach ($expected as $uri => $throttle) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($route, "route $uri exists");
            $this->assertContains($throttle, $route->gatherMiddleware(), $uri);
        }
    }

    public function test_a_rider_code_is_thrown_away_after_5_wrong_guesses(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('rider');
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email');
            $table->string('otp');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        $user = User::create([
            'first_name' => 'Juan', 'last_name' => 'Cruz', 'username' => 'juan',
            'email' => 'juan@example.com', 'password' => 'Secret123', 'role' => 'rider',
            'email_verified_at' => now(),
        ]);
        PasswordResetOtp::create([
            'user_id' => $user->id, 'email' => $user->email, 'otp' => '654321', 'expires_at' => now()->addMinutes(10),
        ]);

        $service = new RiderAuthService($this->createMock(NotificationService::class));

        for ($i = 1; $i <= 4; $i++) {
            $this->assertNull($service->verifyForgotPasswordOtp($user->email, '000000'), "wrong guess $i");
        }
        $this->assertSame(1, PasswordResetOtp::count(), 'still usable after 4 wrong guesses');

        try {
            $service->verifyForgotPasswordOtp($user->email, '000000');
            $this->fail('5th wrong guess should stop');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many incorrect attempts', $e->errors()['otp'][0]);
        }
        $this->assertSame(0, PasswordResetOtp::count(), 'the code is deleted');

        // Even the right code no longer works; a new one must be requested.
        $this->assertNull($service->verifyForgotPasswordOtp($user->email, '654321'));

        // A fresh code works and the right code is accepted.
        PasswordResetOtp::create([
            'user_id' => $user->id, 'email' => $user->email, 'otp' => '111222', 'expires_at' => now()->addMinutes(10),
        ]);
        $this->assertIsString($service->verifyForgotPasswordOtp($user->email, '111222'));
    }
}
