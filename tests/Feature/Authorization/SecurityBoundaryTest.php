<?php

namespace Tests\Feature\Authorization;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Space;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_only_their_own_booking(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $space = $this->spaceFor(User::factory()->create(['role' => 'staff']));
        $ownBooking = $this->bookingFor($customer, $space);
        $otherBooking = $this->bookingFor($otherCustomer, $space, [
            'start_time' => now()->addDays(2)->setTime(10, 0),
            'end_time' => now()->addDays(2)->setTime(11, 0),
        ]);

        Sanctum::actingAs($customer);

        $this->getJson("/api/v1/customer/bookings/{$ownBooking->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $ownBooking->id)
            ->assertJsonMissingPath('data.user');

        $this->getJson("/api/v1/customer/bookings/{$otherBooking->id}")
            ->assertForbidden()
            ->assertJsonMissing(['id' => $otherBooking->id]);
    }

    public function test_booking_update_is_authorized_before_any_mutation(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $space = $this->spaceFor(User::factory()->create(['role' => 'staff']));
        $ownBooking = $this->bookingFor($customer, $space);
        $otherBooking = $this->bookingFor($otherCustomer, $space, [
            'start_time' => now()->addDays(3)->setTime(10, 0),
            'end_time' => now()->addDays(3)->setTime(11, 0),
        ]);

        $this->assertTrue($customer->can('update', $ownBooking));

        Sanctum::actingAs($customer);

        $this->putJson("/api/v1/customer/bookings/{$ownBooking->id}", [
            'start_time' => now()->addDays(2)->setTime(12, 0)->toDateTimeString(),
            'end_time' => now()->addDays(2)->setTime(13, 0)->toDateTimeString(),
        ])->assertOk();

        $originalStart = $otherBooking->start_time->toDateTimeString();
        $originalEnd = $otherBooking->end_time->toDateTimeString();

        $this->putJson("/api/v1/customer/bookings/{$otherBooking->id}", [
            'start_time' => now()->addDays(4)->setTime(12, 0)->toDateTimeString(),
            'end_time' => now()->addDays(4)->setTime(13, 0)->toDateTimeString(),
        ])->assertForbidden();

        $this->assertDatabaseHas('bookings', [
            'id' => $otherBooking->id,
            'start_time' => $originalStart,
            'end_time' => $originalEnd,
        ]);
    }

    public function test_staff_booking_list_is_scoped_to_managed_spaces_while_admin_list_remains_global(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $otherStaff = User::factory()->create(['role' => 'staff']);
        $customer = User::factory()->create(['role' => 'customer']);
        $managedBooking = $this->bookingFor($customer, $this->spaceFor($staff));
        $otherBooking = $this->bookingFor($customer, $this->spaceFor($otherStaff), [
            'start_time' => now()->addDays(3)->setTime(10, 0),
            'end_time' => now()->addDays(3)->setTime(11, 0),
        ]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/staff/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $managedBooking->id)
            ->assertJsonMissing(['id' => $otherBooking->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/bookings')
            ->assertOk()
            ->assertJsonFragment(['id' => $managedBooking->id])
            ->assertJsonFragment(['id' => $otherBooking->id]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/staff/spaces')
            ->assertOk()
            ->assertJsonFragment(['id' => $managedBooking->space_id])
            ->assertJsonMissing(['id' => $otherBooking->space_id]);
    }

    public function test_staff_cannot_change_space_configuration_even_for_assigned_spaces(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $otherStaff = User::factory()->create(['role' => 'staff']);
        $category = Category::create(['name' => 'Meeting']);
        $managedSpace = $this->spaceFor($staff);
        $otherSpace = $this->spaceFor($otherStaff);

        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/staff/spaces/{$managedSpace->id}/categories", [
            'categories' => [$category->id],
        ])->assertForbidden();

        $this->postJson("/api/v1/staff/spaces/{$otherSpace->id}/categories", [
            'categories' => [$category->id],
        ])->assertForbidden();

        $this->putJson("/api/v1/staff/spaces/{$managedSpace->id}", [
            'title' => 'Unauthorized update',
            'location' => 'Office',
            'price_per_hour' => 50,
            'capacity' => 10,
            'status' => 'available',
        ])->assertForbidden();

        $this->assertDatabaseMissing('category_space', [
            'space_id' => $managedSpace->id,
            'category_id' => $category->id,
        ]);
        $this->assertDatabaseMissing('category_space', [
            'space_id' => $otherSpace->id,
            'category_id' => $category->id,
        ]);
    }

    public function test_assigned_staff_not_creator_controls_booking_access(): void
    {
        $creator = User::factory()->create(['role' => 'staff']);
        $assignedStaff = User::factory()->create(['role' => 'staff']);
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $space = Space::create([
            'title' => 'Assigned room '.fake()->unique()->word(),
            'location' => 'Office',
            'price_per_hour' => 50,
            'capacity' => 10,
            'status' => 'available',
            'created_by' => $creator->id,
            'staff_id' => $assignedStaff->id,
        ]);
        $booking = $this->bookingFor($customer, $space, ['status' => BookingStatus::PendingStaffApproval]);

        $this->assertTrue($assignedStaff->can('view', $booking));
        $this->assertTrue($assignedStaff->can('approve', $booking));
        $this->assertTrue($assignedStaff->can('reject', $booking));
        $this->assertFalse($creator->can('approve', $booking));
        $this->assertFalse($creator->can('reject', $booking));
        $this->assertFalse($creator->can('view', $space));
        $this->assertTrue($admin->can('view', $space));
    }

    public function test_staff_role_and_account_cannot_be_removed_while_spaces_remain_assigned(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $replacementStaff = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $space = $this->spaceFor($staff);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/users/{$staff->id}/role", ['role' => 'customer'])->assertStatus(409);
        $this->deleteJson("/api/v1/admin/users/{$staff->id}")->assertStatus(409);
        $this->assertSame('staff', $staff->fresh()->role);
        $this->assertSame($staff->id, $space->fresh()->staff_id);

        $space->update(['staff_id' => $replacementStaff->id]);
        $this->patchJson("/api/v1/admin/users/{$staff->id}/role", ['role' => 'customer'])->assertOk();
        $this->deleteJson("/api/v1/admin/users/{$staff->id}")->assertOk();
        $this->assertSame($replacementStaff->id, $space->fresh()->staff_id);
    }

    public function test_creator_account_deletion_is_restricted_to_preserve_space_audit_reference(): void
    {
        $creator = User::factory()->create(['role' => 'staff']);
        $assignedStaff = User::factory()->create(['role' => 'staff']);
        $admin = User::factory()->create(['role' => 'admin']);
        $space = Space::create([
            'title' => 'Creator reference room '.fake()->unique()->word(),
            'location' => 'Office',
            'price_per_hour' => 50,
            'capacity' => 10,
            'status' => 'available',
            'created_by' => $creator->id,
            'staff_id' => $assignedStaff->id,
        ]);

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/users/{$creator->id}")->assertStatus(409);

        $this->assertDatabaseHas('spaces', [
            'id' => $space->id,
            'created_by' => $creator->id,
            'staff_id' => $assignedStaff->id,
        ]);
    }

    private function spaceFor(User $staff): Space
    {
        $creator = User::factory()->create(['role' => 'admin']);

        return Space::create([
            'title' => 'Room '.fake()->unique()->word(),
            'location' => 'Office',
            'price_per_hour' => 50,
            'capacity' => 10,
            'status' => 'available',
            'created_by' => $creator->id,
            'staff_id' => $staff->id,
        ]);
    }

    private function bookingFor(User $customer, Space $space, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'user_id' => $customer->id,
            'space_id' => $space->id,
            'start_time' => now()->addDay()->setTime(10, 0),
            'end_time' => now()->addDay()->setTime(11, 0),
            'total_amount' => 50,
            'status' => BookingStatus::PendingPayment,
            'expires_at' => now()->addMinutes(10),
        ], $attributes));
    }
}
