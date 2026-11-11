<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RoomBookingDemoSeeder extends Seeder
{
    /**
     * Credentials are intentionally local-only demo accounts.
     */
    private const USERS = [
        ['name' => 'مدير العرض', 'email' => 'admin@room-booking.test', 'role' => 'admin', 'password' => 'DemoAdmin!2026'],
        ['name' => 'موظف العرض', 'email' => 'staff@room-booking.test', 'role' => 'staff', 'password' => 'DemoStaff!2026'],
        ['name' => 'عميل العرض', 'email' => 'customer@room-booking.test', 'role' => 'customer', 'password' => 'DemoCustomer!2026'],
        ['name' => 'عميل إضافي', 'email' => 'guest@room-booking.test', 'role' => 'customer', 'password' => 'DemoGuest!2026'],
    ];

    public function run(): void
    {
        $users = [];

        foreach (self::USERS as $attributes) {
            $user = User::query()->firstOrNew(['email' => $attributes['email']]);
            $isNew = ! $user->exists;
            $user->name = $attributes['name'];
            $user->role = $attributes['role'];
            $user->email_verified_at ??= now();

            if ($isNew) {
                $user->password = Hash::make($attributes['password']);
            }

            $user->save();
            $users[$attributes['email']] = $user;
        }

        $staff = $users['staff@room-booking.test'];
        $categories = [
            'اجتماعات' => 'مساحات مناسبة للاجتماعات المهنية.',
            'تدريب' => 'قاعات مجهزة للدورات وورش العمل.',
            'عمل مشترك' => 'مساحات مرنة للعمل الفردي والجماعي.',
        ];

        $categoryModels = [];
        foreach ($categories as $name => $description) {
            $categoryModels[$name] = Category::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            );
        }

        $spaces = [];
        foreach ([
            [
                'title' => 'قاعة اجتماعات النخيل',
                'description' => 'قاعة هادئة للاجتماعات ومقابلات العمل، مع ترتيب مرن للطاولات.',
                'location' => 'رام الله، مركز المدينة',
                'price_per_hour' => 45.00,
                'capacity' => 8,
                'categories' => ['اجتماعات'],
            ],
            [
                'title' => 'قاعة التدريب الرئيسية',
                'description' => 'مساحة واسعة للدورات وورش العمل والاجتماعات الجماعية.',
                'location' => 'البيرة، شارع الإرسال',
                'price_per_hour' => 80.00,
                'capacity' => 24,
                'categories' => ['تدريب', 'اجتماعات'],
            ],
            [
                'title' => 'مساحة العمل المشتركة',
                'description' => 'مساحة مرنة للعمل والتركيز أو لقاء فريق صغير.',
                'location' => 'رام الله، حي المصايف',
                'price_per_hour' => 25.00,
                'capacity' => 6,
                'categories' => ['عمل مشترك'],
            ],
            [
                'title' => 'استوديو ورش العمل',
                'description' => 'استوديو متعدد الاستخدامات للورش الإبداعية واللقاءات الصغيرة.',
                'location' => 'رام الله، الطيرة',
                'price_per_hour' => 60.00,
                'capacity' => 12,
                'categories' => ['تدريب', 'عمل مشترك'],
            ],
        ] as $attributes) {
            $space = Space::query()->updateOrCreate(
                ['title' => $attributes['title']],
                [
                    'description' => $attributes['description'],
                    'location' => $attributes['location'],
                    'price_per_hour' => $attributes['price_per_hour'],
                    'capacity' => $attributes['capacity'],
                    'status' => 'available',
                    'opening_hour' => '08:00',
                    'closing_hour' => '23:00',
                    'created_by' => $staff->id,
                ],
            );

            $space->categories()->syncWithoutDetaching(
                collect($attributes['categories'])->map(fn (string $name) => $categoryModels[$name]->id)->all(),
            );
            $spaces[$attributes['title']] = $space;
        }

        $bookings = [
            [
                'email' => 'customer@room-booking.test',
                'space' => 'قاعة اجتماعات النخيل',
                'start' => '2026-11-03 10:00:00',
                'end' => '2026-11-03 12:00:00',
                'amount' => 90.00,
                'status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::Paid,
                'paid' => true,
            ],
            [
                'email' => 'customer@room-booking.test',
                'space' => 'قاعة التدريب الرئيسية',
                'start' => '2026-11-05 13:00:00',
                'end' => '2026-11-05 14:00:00',
                'amount' => 80.00,
                'status' => BookingStatus::PendingPayment,
                'payment_status' => PaymentStatus::Pending,
                'paid' => false,
            ],
            [
                'email' => 'guest@room-booking.test',
                'space' => 'استوديو ورش العمل',
                'start' => '2026-11-07 11:00:00',
                'end' => '2026-11-07 13:00:00',
                'amount' => 120.00,
                'status' => BookingStatus::PendingStaffApproval,
                'payment_status' => PaymentStatus::Paid,
                'paid' => true,
            ],
        ];

        foreach ($bookings as $attributes) {
            $space = $spaces[$attributes['space']];
            $user = $users[$attributes['email']];
            $booking = Booking::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'space_id' => $space->id,
                    'start_time' => $attributes['start'],
                ],
                [
                    'end_time' => $attributes['end'],
                    'total_amount' => $attributes['amount'],
                    'currency' => 'USD',
                    'status' => $attributes['status'],
                    'expires_at' => $attributes['status'] === BookingStatus::PendingPayment
                        ? now()->addMinutes(10)
                        : null,
                ],
            );

            // Demo snapshots only: no Stripe identifiers, payment requests, or webhook records.
            DB::table('payments')->updateOrInsert(
                ['booking_id' => $booking->id],
                [
                    'provider' => 'demo',
                    'provider_reference' => null,
                    'payment_intent_id' => null,
                    'status' => $attributes['payment_status']->value,
                    'amount' => $attributes['amount'],
                    'currency' => 'USD',
                    'paid_at' => $attributes['paid'] ? '2026-10-20 10:00:00' : null,
                    'failed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
