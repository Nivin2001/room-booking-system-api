<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\RefundOperation;
use App\Models\Space;
use App\Models\User;
use App\Services\stripe\StripeWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use RuntimeException;

class DashboardWebMySqlTest extends LaravelTestCase
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

        foreach (['users', 'spaces', 'bookings', 'payments', 'payment_transactions', 'refund_operations', 'webhook_logs'] as $table) {
            if (! Schema::connection('mysql')->hasTable($table)) {
                throw new RuntimeException("Required test table is missing: {$table}.");
            }
        }
        if (! Schema::connection('mysql')->hasColumn('spaces', 'staff_id')) {
            throw new RuntimeException('The isolated test database must have the spaces.staff_id migration applied.');
        }

        DB::connection('mysql')->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if ($this->transactionStarted && DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }

        DB::disconnect('mysql');
        parent::tearDown();
    }

    public function test_admin_can_render_dashboard_and_management_pages(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'web')->get(route('admin.dashboard'))->assertOk()->assertSee('نظرة عامة');
        $this->get(route('admin.spaces.index'))->assertOk()->assertSee('المساحات');
        $this->get(route('admin.spaces.create'))->assertOk()->assertSee('إضافة مساحة')->assertSee('الموظف المسؤول');
        $this->get(route('admin.categories.index'))->assertOk()->assertSee('التصنيفات');
        $this->get(route('admin.bookings.index'))->assertOk()->assertSee('كل الحجوزات');
        $this->get(route('admin.payments.index'))->assertOk()->assertSee('المدفوعات والاستردادات');
    }

    public function test_admin_space_and_category_actions_use_the_existing_web_routes(): void
    {
        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff');
        $replacementStaff = $this->makeUser('staff');
        $this->actingAs($admin, 'web');
        $spaceTitle = 'Admin CRUD '.bin2hex(random_bytes(6));

        $this->post(route('admin.spaces.store'), [
            'title' => $spaceTitle,
            'description' => 'Test-only description',
            'location' => 'Test location',
            'price_per_hour' => '35.00',
            'capacity' => 6,
            'status' => 'available',
            'staff_id' => $staff->id,
        ])->assertRedirect(route('admin.spaces.index'));

        $space = Space::query()->where('title', $spaceTitle)->firstOrFail();
        $this->assertSame($admin->id, $space->created_by);
        $this->assertSame($staff->id, $space->staff_id);
        Log::spy();
        $this->put(route('admin.spaces.update', $space), [
            'title' => $spaceTitle.' updated',
            'description' => 'Updated description',
            'location' => 'Updated location',
            'price_per_hour' => '40.00',
            'capacity' => 8,
            'status' => 'available',
            'staff_id' => $replacementStaff->id,
        ])->assertRedirect(route('admin.spaces.index'));

        $space->refresh();
        $this->assertSame($spaceTitle.' updated', $space->title);
        $this->assertSame($replacementStaff->id, $space->staff_id);
        Log::shouldHaveReceived('info')->once()->with(
            'Space staff assignment changed.',
            \Mockery::on(fn (array $context) => $context['space_id'] === $space->id
                && (int) $context['previous_staff_id'] === (int) $staff->id
                && (int) $context['new_staff_id'] === (int) $replacementStaff->id
                && (int) $context['changed_by'] === (int) $admin->id),
        );
        $this->patch(route('admin.spaces.toggle', $space))->assertRedirect();
        $this->assertSame('unavailable', $space->fresh()->status);
        $this->delete(route('admin.spaces.delete', $space))->assertRedirect(route('admin.spaces.index'));
        $this->assertDatabaseMissing('spaces', ['id' => $space->id]);

        $categoryName = 'Category '.bin2hex(random_bytes(6));
        $this->from(route('admin.categories.index'))->post(route('admin.categories.store'), [
            'name' => $categoryName,
            'description' => 'Category description',
        ])->assertRedirect(route('admin.categories.index'));
        $category = Category::query()->where('name', $categoryName)->firstOrFail();
        $this->put(route('admin.categories.update', $category), [
            'name' => $categoryName.' updated',
            'description' => 'Updated category description',
        ])->assertRedirect();
        $this->delete(route('admin.categories.delete', $category))->assertRedirect();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_admin_rejects_non_staff_as_space_responsible(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser('customer');
        $title = 'Invalid assignee '.bin2hex(random_bytes(6));

        $this->actingAs($admin, 'web')
            ->from(route('admin.spaces.create'))
            ->post(route('admin.spaces.store'), [
                'title' => $title,
                'location' => 'Test location',
                'price_per_hour' => 30,
                'capacity' => 4,
                'status' => 'available',
                'staff_id' => $customer->id,
            ])->assertRedirect(route('admin.spaces.create'))
            ->assertSessionHasErrors('staff_id');

        $this->assertDatabaseMissing('spaces', ['title' => $title]);
    }

    public function test_staff_pages_are_scoped_to_their_own_spaces_and_other_roles_are_denied(): void
    {
        $staff = $this->makeUser('staff');
        $otherStaff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($staff);
        $otherSpace = $this->makeSpace($otherStaff);
        $booking = $this->makeBooking($customer, $space);
        $otherBooking = $this->makeBooking($customer, $otherSpace);

        $this->actingAs($staff, 'web')->get(route('staff.dashboard'))->assertOk()->assertSee('لوحة الموظف');
        $this->get(route('staff.bookings.index'))->assertOk()->assertSee('#'.$booking->id)->assertSee('بانتظار الدفع')->assertDontSee('#'.$otherBooking->id);
        $this->get(route('staff.spaces.index'))->assertOk()->assertSee($space->title)->assertDontSee($otherSpace->title);
        $this->get(route('staff.bookings.show', $booking))->assertOk()->assertSee('تفاصيل الحجز');
        $this->get(route('staff.bookings.show', $otherBooking))->assertNotFound();

        \Laravel\Sanctum\Sanctum::actingAs($staff);
        $this->getJson('/api/v1/staff/bookings')
            ->assertOk()->assertJsonFragment(['id' => $booking->id])->assertJsonMissing(['id' => $otherBooking->id]);
        $this->getJson('/api/v1/staff/spaces')
            ->assertOk()->assertJsonFragment(['id' => $space->id])->assertJsonMissing(['id' => $otherSpace->id]);

        $this->actingAs($customer, 'web')->get(route('staff.dashboard'))->assertForbidden();
        $this->actingAs($staff, 'web')->get(route('admin.dashboard'))
            ->assertForbidden()->assertSee('غير مصرح لك بالوصول إلى هذه الصفحة')->assertDontSee('Forbidden - insufficient role');
        \Laravel\Sanctum\Sanctum::actingAs($staff);
        $this->getJson('/api/v1/admin/bookings')
            ->assertForbidden()->assertJsonPath('message', 'Forbidden - insufficient role');
    }

    public function test_staff_dashboard_shows_assigned_spaces_and_counts_only_their_booking_states(): void
    {
        $staff = $this->makeUser('staff');
        $otherStaff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($staff);
        $otherSpace = $this->makeSpace($otherStaff);

        $pending = $this->makeBooking($customer, $space, BookingStatus::PendingStaffApproval, [
            'start_time' => now()->addDays(2)->setTime(10, 0), 'end_time' => now()->addDays(2)->setTime(11, 0),
        ]);
        $confirmed = $this->makeBooking($customer, $space, BookingStatus::Confirmed, [
            'start_time' => now()->addDays(3)->setTime(10, 0), 'end_time' => now()->addDays(3)->setTime(11, 0),
        ]);
        $this->makeBooking($customer, $space, BookingStatus::Rejected, [
            'start_time' => now()->addDays(4)->setTime(10, 0), 'end_time' => now()->addDays(4)->setTime(11, 0),
        ]);
        $this->makeBooking($customer, $space, BookingStatus::PendingPayment, [
            'start_time' => now()->addDays(5)->setTime(10, 0), 'end_time' => now()->addDays(5)->setTime(11, 0),
        ]);
        $otherBooking = $this->makeBooking($customer, $otherSpace, BookingStatus::PendingStaffApproval, [
            'start_time' => now()->addDays(6)->setTime(10, 0), 'end_time' => now()->addDays(6)->setTime(11, 0),
        ]);

        $this->actingAs($staff, 'web');
        $this->get(route('staff.dashboard'))
            ->assertOk()
            ->assertSee($space->title)
            ->assertSee('data-stat="pending_payment" data-count="1"', false)
            ->assertSee('data-stat="pending" data-count="1"', false)
            ->assertSee('data-stat="confirmed" data-count="1"', false)
            ->assertSee('data-stat="rejected" data-count="1"', false)
            ->assertSee('data-stat="upcoming" data-count="1"', false)
            ->assertDontSee($otherSpace->title)
            ->assertDontSee('#'.$otherBooking->id);

        $this->get(route('staff.bookings.index'))
            ->assertOk()->assertSee('#'.$pending->id)->assertSee('#'.$confirmed->id)->assertDontSee('#'.$otherBooking->id);
    }

    public function test_staff_spaces_are_read_only_and_cannot_open_admin_edit_routes(): void
    {
        $staff = $this->makeUser('staff');
        $space = $this->makeSpace($staff);
        $original = $space->only(['title', 'staff_id', 'status', 'price_per_hour']);

        $this->actingAs($staff, 'web')
            ->get(route('staff.spaces.index'))
            ->assertOk()->assertSee($space->title)->assertSee('هذه الصفحة للعرض فقط');
        $this->get('/staff/spaces/'.$space->id.'/edit')->assertNotFound();
        $this->get(route('admin.spaces.edit', $space))->assertForbidden()->assertSee('غير مصرح لك بالوصول');

        $this->assertSame($original, $space->fresh()->only(['title', 'staff_id', 'status', 'price_per_hour']));
    }

    public function test_staff_views_show_identifiers_when_customer_or_space_names_are_empty(): void
    {
        $staff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($staff);
        DB::table('users')->where('id', $customer->id)->update(['name' => '']);
        DB::table('spaces')->where('id', $space->id)->update(['title' => '']);
        $booking = $this->makeBooking($customer, $space);

        $this->actingAs($staff, 'web')
            ->get(route('staff.bookings.index'))
            ->assertOk()->assertSee('عميل بدون اسم #'.$customer->id)->assertSee('مساحة بدون اسم #'.$space->id);
        $this->get(route('staff.bookings.show', $booking))
            ->assertOk()->assertSee('عميل بدون اسم #'.$customer->id)->assertSee('مساحة بدون اسم #'.$space->id);
    }

    public function test_staff_assignment_not_creator_controls_web_visibility_and_pending_payment_actions(): void
    {
        $creator = $this->makeUser('staff');
        $assignedStaff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($creator);
        DB::table('spaces')->where('id', $space->id)->update(['staff_id' => $assignedStaff->id]);
        $booking = $this->makeBooking($customer, $space, BookingStatus::PendingPayment);

        $this->actingAs($creator, 'web')
            ->get(route('staff.bookings.index'))
            ->assertOk()->assertDontSee('#'.$booking->id);
        $this->get(route('staff.bookings.show', $booking))->assertNotFound();
        $this->post(route('staff.bookings.approve', $booking))->assertNotFound();
        $this->post(route('staff.bookings.reject', $booking), [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'reason' => 'Not assigned',
        ])->assertNotFound();

        $this->actingAs($assignedStaff, 'web')
            ->get(route('staff.bookings.index'))
            ->assertOk()->assertSee('#'.$booking->id);
        $this->from(route('staff.bookings.show', $booking))
            ->post(route('staff.bookings.approve', $booking))
            ->assertRedirect(route('staff.bookings.show', $booking))
            ->assertSessionHasErrors('booking');
        $this->from(route('staff.bookings.show', $booking))
            ->post(route('staff.bookings.reject', $booking), [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'reason' => 'Payment has not completed',
        ])->assertRedirect(route('staff.bookings.show', $booking))
            ->assertSessionHasErrors('booking');

        $this->assertSame(BookingStatus::PendingPayment, $booking->fresh()->status);
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
    }

    public function test_staff_approve_and_reject_actions_use_existing_services_with_a_stripe_fake(): void
    {
        $staff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($staff);
        $rejectBooking = $this->makeBooking($customer, $space, BookingStatus::PendingStaffApproval);
        $payment = Payment::query()->create([
            'booking_id' => $rejectBooking->id,
            'provider' => 'stripe',
            'payment_intent_id' => 'pi_dashboard_fake',
            'status' => PaymentStatus::Paid,
            'amount' => 25,
            'currency' => 'USD',
        ]);
        $approveBooking = $this->makeBooking($customer, $space, BookingStatus::PendingStaffApproval);

        $this->mock(\App\Services\stripe\StripeRefundService::class)
            ->shouldReceive('refund')->once()
            ->withArgs(fn ($receivedPayment, $operation) => $receivedPayment->id === $payment->id && $operation instanceof RefundOperation)
            ->andReturnUsing(fn ($receivedPayment, $operation) => (object) [
                'id' => 're_dashboard_fake',
                'status' => 'succeeded',
                'payment_intent' => $receivedPayment->payment_intent_id,
                'amount' => $operation->amount_minor,
                'currency' => strtolower($operation->currency),
                'metadata' => (object) ['refund_operation_id' => (string) $operation->id],
            ]);
        Event::fake();

        $this->actingAs($staff, 'web')
            ->post(route('staff.bookings.reject', $rejectBooking), [
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'reason' => 'تعارض في الجدول',
            ])->assertRedirect(route('staff.bookings.show', $rejectBooking));
        $this->assertSame(BookingStatus::Rejected, $rejectBooking->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_id', $payment->id)->where('type', 'refund')->count());

        $this->post(route('staff.bookings.approve', $approveBooking))
            ->assertRedirect(route('staff.bookings.show', $approveBooking));
        $this->assertSame(BookingStatus::Confirmed, $approveBooking->fresh()->status);
    }

    public function test_checkout_webhook_then_staff_approval_and_rejection_follow_the_real_transition_path(): void
    {
        $staff = $this->makeUser('staff');
        $otherStaff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($staff);
        $webhooks = app(StripeWebhookService::class);

        $approveBooking = $this->makeBooking($customer, $space);
        $approvePayment = $this->makeStripePendingPayment($approveBooking);
        $approveEvent = $this->paidCheckoutEvent($approveBooking, $approvePayment, 'evt_test_approve_'.$approveBooking->id);
        $this->assertSame(200, $webhooks->processEvent($approveEvent, [])->getStatusCode());
        $this->assertSame(200, $webhooks->processEvent($approveEvent, [])->getStatusCode());
        $this->assertSame(BookingStatus::PendingStaffApproval, $approveBooking->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $approvePayment->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('stripe_event_id', $approveEvent->id)->count());

        $this->actingAs($customer, 'web')->post(route('staff.bookings.approve', $approveBooking))->assertForbidden();
        $this->actingAs($otherStaff, 'web')->post(route('staff.bookings.approve', $approveBooking))->assertNotFound();
        $this->actingAs($staff, 'web')
            ->post(route('staff.bookings.approve', $approveBooking))
            ->assertRedirect(route('staff.bookings.show', $approveBooking));
        $this->assertSame(BookingStatus::Confirmed, $approveBooking->fresh()->status);

        $rejectBooking = $this->makeBooking($customer, $space);
        $rejectPayment = $this->makeStripePendingPayment($rejectBooking);
        $rejectEvent = $this->paidCheckoutEvent($rejectBooking, $rejectPayment, 'evt_test_reject_'.$rejectBooking->id);
        $this->assertSame(200, $webhooks->processEvent($rejectEvent, [])->getStatusCode());
        $this->assertSame(200, $webhooks->processEvent($rejectEvent, [])->getStatusCode());
        $this->assertSame(BookingStatus::PendingStaffApproval, $rejectBooking->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $rejectPayment->fresh()->status);

        $this->mock(\App\Services\stripe\StripeRefundService::class)
            ->shouldReceive('refund')->once()
            ->withArgs(fn ($receivedPayment, $operation) => $receivedPayment->id === $rejectPayment->id && $operation instanceof RefundOperation)
            ->andReturnUsing(fn ($receivedPayment, $operation) => (object) [
                'id' => 're_test_reject_'.$rejectBooking->id,
                'status' => 'succeeded',
                'payment_intent' => $receivedPayment->payment_intent_id,
                'amount' => $operation->amount_minor,
                'currency' => strtolower($operation->currency),
                'metadata' => (object) ['refund_operation_id' => (string) $operation->id],
            ]);

        $rejectKey = (string) \Illuminate\Support\Str::uuid();
        $this->actingAs($customer, 'web')->post(route('staff.bookings.reject', $rejectBooking), [
            'idempotency_key' => $rejectKey,
            'reason' => 'Synthetic test rejection',
        ])->assertForbidden();
        $this->actingAs($otherStaff, 'web')->post(route('staff.bookings.reject', $rejectBooking), [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'reason' => 'Synthetic test rejection',
        ])->assertNotFound();
        $this->actingAs($staff, 'web')->post(route('staff.bookings.reject', $rejectBooking), [
            'idempotency_key' => $rejectKey,
            'reason' => 'Synthetic test rejection',
        ])->assertRedirect(route('staff.bookings.show', $rejectBooking));
        $this->assertSame(BookingStatus::Rejected, $rejectBooking->fresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $rejectPayment->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('payment_id', $rejectPayment->id)->where('type', 'refund')->count());

        $this->post(route('staff.bookings.reject', $rejectBooking), [
            'idempotency_key' => $rejectKey,
            'reason' => 'Synthetic test rejection',
        ])->assertRedirect(route('staff.bookings.show', $rejectBooking));
        $this->assertSame(1, PaymentTransaction::query()->where('payment_id', $rejectPayment->id)->where('type', 'refund')->count());
    }

    public function test_customer_booking_details_are_private_and_admin_can_review_booking(): void
    {
        $customer = $this->makeUser('customer');
        $otherCustomer = $this->makeUser('customer');
        $staff = $this->makeUser('staff');
        $space = $this->makeSpace($staff);
        $booking = $this->makeBooking($customer, $space);

        $this->actingAs($customer, 'web')->get(route('customer.bookings.show', $booking))
            ->assertOk()->assertSee('تفاصيل حجزك')->assertSee('#'.$booking->id);
        $this->get(route('customer.bookings.show', $this->makeBooking($otherCustomer, $space)))->assertNotFound();
        $this->actingAs($this->makeUser('admin'), 'web')->get(route('admin.bookings.show', $booking))
            ->assertOk()->assertSee('مراجعة إدارية');
    }

    public function test_arabic_space_and_category_names_render_on_customer_staff_and_admin_pages(): void
    {
        $charset = DB::connection('mysql')->selectOne('SELECT @@character_set_connection AS charset')->charset;
        $this->assertSame('utf8mb4', $charset);

        $admin = $this->makeUser('admin');
        $staff = $this->makeUser('staff');
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($admin);
        $spaceName = 'قاعة اختبار عربية '.bin2hex(random_bytes(3));
        $location = 'رام الله، مركز المدينة';
        $categoryName = 'اجتماعات وورش عمل';

        $space->update([
            'title' => $spaceName,
            'location' => $location,
            'staff_id' => $staff->id,
        ]);
        $category = Category::query()->create(['name' => $categoryName, 'description' => 'تصنيف عربي للاختبار']);
        $space->categories()->attach($category->id);

        $booking = $this->makeBooking($customer, $space, BookingStatus::PendingStaffApproval);
        Payment::query()->create([
            'booking_id' => $booking->id,
            'provider' => 'demo',
            'status' => PaymentStatus::Paid,
            'amount' => $booking->total_amount,
            'currency' => 'USD',
        ]);

        $this->get(route('spaces.index'))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee($location)
            ->assertSee($categoryName);
        $this->get(route('spaces.show', $space))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee($categoryName)
            ->assertSee('لا توجد صورة مضافة لهذه المساحة');

        $this->actingAs($customer, 'web')->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee('مدفوع');

        $this->actingAs($staff, 'web')->get(route('staff.spaces.index'))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee($location)
            ->assertSee($categoryName);
        $this->get(route('staff.bookings.index'))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee('مدفوع');

        $this->actingAs($admin, 'web')->get(route('admin.spaces.index'))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee($location)
            ->assertSee($categoryName);
        $this->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee($categoryName);
        $this->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertSee($spaceName)
            ->assertSee('مدفوع');
    }

    public function test_blank_legacy_space_and_category_names_have_clear_display_fallbacks(): void
    {
        $admin = $this->makeUser('admin');
        $space = $this->makeSpace($admin);
        $space->update(['title' => '', 'location' => '']);
        $category = Category::query()->create(['name' => '', 'description' => null]);
        $space->categories()->attach($category->id);

        $this->get(route('spaces.index'))
            ->assertOk()
            ->assertSee('مساحة بدون اسم #'.$space->id)
            ->assertSee('الموقع غير محدد')
            ->assertSee('تصنيف غير مسمى #'.$category->id);

        $this->actingAs($admin, 'web')->get(route('admin.spaces.edit', $space))
            ->assertOk()
            ->assertSee('توجد بيانات اسم أو موقع فارغة')
            ->assertSee('الاسم غير محفوظ حاليًا')
            ->assertSee('تصنيف غير مسمى #'.$category->id);
    }

    public function test_booking_form_shows_backend_calculated_price_for_available_slots(): void
    {
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($this->makeUser('admin'));
        $date = now()->addDays(2)->format('Y-m-d');

        $response = $this->actingAs($customer, 'web')->get(route('spaces.bookings.create', [
            'space' => $space->id,
            'date' => $date,
        ]));

        $response->assertOk()->assertSee('الإجمالي المتوقع')->assertSee('25.00 USD')->assertSee('dir="ltr"', false);
        $slots = $response->viewData('slots');
        $this->assertNotEmpty($slots);
        $this->assertSame(25.0, (float) $slots[0]['estimated_total']);
    }

    public function test_customer_sees_why_checkout_is_unavailable_for_a_past_booking(): void
    {
        $customer = $this->makeUser('customer');
        $space = $this->makeSpace($this->makeUser('admin'));
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', config('app.timezone')));
        $booking = $this->makeBooking($customer, $space, BookingStatus::PendingPayment, [
            'start_time' => '2026-11-01 10:00:00',
            'end_time' => '2026-11-01 11:00:00',
        ]);

        $this->actingAs($customer, 'web')->get(route('customer.bookings.index'))
            ->assertOk()
            ->assertSee('انتهى موعد الحجز أو بدأ بالفعل')
            ->assertDontSee('المتابعة إلى الدفع');
    }

    private function makeUser(string $role): User
    {
        $token = bin2hex(random_bytes(8));

        return User::query()->create([
            'name' => 'Dashboard '.$role.' '.$token,
            'email' => 'dashboard-'.$role.'-'.$token.'@example.test',
            'password' => Hash::make('VerySecure123!'),
            'role' => $role,
        ]);
    }

    private function makeSpace(User $creator): Space
    {
        $token = bin2hex(random_bytes(6));
        $id = DB::table('spaces')->insertGetId([
            'title' => 'Dashboard space '.$token,
            'location' => 'Local test',
            'price_per_hour' => 25,
            'capacity' => 8,
            'status' => 'available',
            'created_by' => $creator->id,
            'staff_id' => $creator->role === 'staff' ? $creator->id : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Space::query()->findOrFail($id);
    }

    private function makeBooking(User $customer, Space $space, BookingStatus $status = BookingStatus::PendingPayment, array $attributes = []): Booking
    {
        return Booking::query()->create(array_merge([
            'user_id' => $customer->id,
            'space_id' => $space->id,
            'start_time' => now()->addDays(3),
            'end_time' => now()->addDays(3)->addHour(),
            'total_amount' => 25,
            'currency' => 'USD',
            'status' => $status,
        ], $attributes));
    }

    private function makeStripePendingPayment(Booking $booking): Payment
    {
        return Payment::query()->create([
            'booking_id' => $booking->id,
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_'.$booking->id.'_'.bin2hex(random_bytes(4)),
            'status' => PaymentStatus::Pending,
            'amount' => $booking->total_amount,
            'currency' => $booking->currency,
        ]);
    }

    private function paidCheckoutEvent(Booking $booking, Payment $payment, string $eventId): object
    {
        return (object) [
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => (object) ['object' => (object) [
                'id' => $payment->provider_reference,
                'status' => 'complete',
                'mode' => 'payment',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_'.$payment->id,
                'amount_total' => (int) round((float) $payment->amount * 100),
                'currency' => strtolower($payment->currency),
                'metadata' => (object) [
                    'payment_id' => (string) $payment->id,
                    'booking_id' => (string) $booking->id,
                ],
            ]],
        ];
    }
}
