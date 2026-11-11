<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use App\Enums\BookingStatus;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WebAuthenticationMySqlTest extends LaravelTestCase
{
    private bool $transactionStarted = false;

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->app->configurationIsCached()) {
            throw new RuntimeException('Config cache prevents verifying the isolated test database.');
        }

        $mysql = config('database.connections.mysql');
        if (! is_array($mysql) || empty($mysql['port'])) {
            throw new RuntimeException('Configured MySQL port is unavailable.');
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.database' => 'room_booking_refund_test',
            'database.connections.mysql.url' => null,
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        DB::purge('mysql');

        if (DB::connection('mysql')->getDriverName() !== 'mysql'
            || DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db !== 'room_booking_refund_test') {
            throw new RuntimeException('The active test connection is not room_booking_refund_test.');
        }

        foreach (['users', 'spaces', 'bookings'] as $table) {
            if (! Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException("Required test table is missing: {$table}.");
            }
        }

        DB::connection('mysql')->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted && DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }

        DB::disconnect('mysql');
        parent::tearDown();
    }

    public function test_customer_can_register_with_customer_role_and_login(): void
    {
        $email = 'web-auth-'.bin2hex(random_bytes(8)).'@example.test';

        $this->get(route('register'))->assertOk()->assertSee('إنشاء حساب عميل');
        $response = $this->post(route('register.store'), [
            'name' => 'Web customer',
            'email' => $email,
            'password' => 'VerySecure123!',
            'password_confirmation' => 'VerySecure123!',
            'role' => 'admin',
        ]);

        $user = User::query()->where('email', $email)->firstOrFail();
        $response->assertRedirect(route('spaces.index'));
        $this->assertSame('customer', $user->role);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue(Hash::check('VerySecure123!', $user->password));
    }

    public function test_valid_login_uses_web_guard_and_returns_to_safe_intended_path(): void
    {
        $user = $this->makeUser('customer');
        $intended = route('spaces.bookings.create', ['space' => 12], false);

        $this->get($intended)->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'VerySecure123!',
        ])->assertRedirect($intended);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_invalid_login_uses_generic_error_and_does_not_authenticate(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('تسجيل الدخول');

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'unknown-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => 'WrongPassword123!',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'تعذر تسجيل الدخول بهذه البيانات. تحقق منها وحاول مجددًا.']);
        $this->assertGuest('web');
    }

    public function test_staff_can_sign_in_to_staff_dashboard_but_not_customer_or_admin_pages(): void
    {
        $staff = $this->makeUser('staff');
        $space = $this->makeSpace($staff);
        $booking = Booking::query()->create([
            'user_id' => $staff->id,
            'space_id' => $space->id,
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(2)->addHour(),
            'total_amount' => 15,
            'currency' => 'USD',
            'status' => BookingStatus::PendingPayment,
        ]);

        $this->post(route('login.store'), [
            'email' => $staff->email,
            'password' => 'VerySecure123!',
        ])->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($staff, 'web');

        $this->get(route('staff.dashboard'))->assertOk();
        $this->get(route('admin.dashboard'))->assertForbidden();
        $this->get(route('customer.bookings.index'))->assertForbidden();

        $this->assertDatabaseHas('spaces', ['id' => $space->id, 'staff_id' => $staff->id]);
        $this->assertTrue($staff->fresh()->can('view', $booking->fresh()));
        $this->get(route('staff.bookings.show', $booking->id))->assertOk();

        $this->post(route('logout'))->assertRedirect(route('spaces.index'));
        $this->assertGuest('web');
    }

    public function test_admin_can_sign_in_to_admin_dashboard_but_not_staff_or_customer_pages(): void
    {
        $admin = $this->makeUser('admin');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'VerySecure123!',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'web');

        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('staff.dashboard'))->assertForbidden();
        $this->get(route('customer.bookings.index'))->assertForbidden();

        $this->post(route('logout'))->assertRedirect(route('spaces.index'));
        $this->assertGuest('web');
    }

    public function test_customer_login_defaults_to_customer_bookings_dashboard(): void
    {
        $customer = $this->makeUser('customer');

        $this->post(route('login.store'), [
            'email' => $customer->email,
            'password' => 'VerySecure123!',
        ])->assertRedirect(route('customer.bookings.index'));

        $this->assertAuthenticatedAs($customer, 'web');
    }

    public function test_logout_invalidates_web_session_and_protected_booking_route_requires_login(): void
    {
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($customer);

        $this->actingAs($customer, 'web')
            ->post(route('logout'))
            ->assertRedirect(route('spaces.index'));

        $this->assertGuest('web');
        $this->get(route('spaces.bookings.create', $space->id))
            ->assertRedirect(route('login'));
    }

    public function test_duplicate_registration_uses_a_generic_error_without_disclosing_account_existence(): void
    {
        $user = $this->makeUser('customer');

        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Another user',
            'email' => $user->email,
            'password' => 'VerySecure123!',
            'password_confirmation' => 'VerySecure123!',
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors(['email' => 'تعذر إنشاء الحساب بهذه البيانات. تحقق منها وحاول مجددًا.']);
    }

    public function test_external_intended_redirect_is_rejected(): void
    {
        $user = $this->makeUser('customer');

        $this->withSession(['url.intended' => 'https://attacker.example/path'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'VerySecure123!',
            ])->assertRedirect(route('customer.bookings.index'));
    }

    public function test_login_is_rate_limited(): void
    {
        $email = 'rate-limit-'.bin2hex(random_bytes(6)).'@example.test';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('login.store'), [
                'email' => $email,
                'password' => 'WrongPassword123!',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), [
            'email' => $email,
            'password' => 'WrongPassword123!',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'تم تجاوز عدد المحاولات المسموح. انتظر قليلًا ثم حاول مجددًا.']);
    }

    private function makeUser(string $role): User
    {
        $token = bin2hex(random_bytes(8));

        return User::query()->create([
            'name' => 'Web auth '.$role.' '.$token,
            'email' => 'web-auth-'.$token.'@example.test',
            'password' => Hash::make('VerySecure123!'),
            'role' => $role,
        ]);
    }

    private function makeSpace(User $creator): Space
    {
        return Space::query()->create([
            'title' => 'Auth test space '.bin2hex(random_bytes(6)),
            'price_per_hour' => 15,
            'status' => 'available',
            'created_by' => $creator->id,
            'staff_id' => $creator->role === 'staff' ? $creator->id : null,
        ]);
    }
}
