<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    /**
     * A basic feature test example.
     */
      public function test_customer_can_view_notifications(): void
    {
        $user = User::factory()->create([
            'role' => 'customer'
        ]);

        Sanctum::actingAs($user);


        $response = $this->getJson(
            '/api/v1/customer/notifications'
        );


        $response->assertStatus(200);
    }


    public function test_customer_can_mark_notification_as_read(): void
    {
        $user = User::factory()->create([
            'role' => 'customer'
        ]);


        $notification = $user->notifications()->create([
            'id' => fake()->uuid(),
            'type' => 'test',
            'data' => [
                'message' => 'Test notification'
            ],
        ]);


        Sanctum::actingAs($user);


        $response = $this->postJson(
            "/api/v1/customer/notifications/{$notification->id}/read"
        );


        $response->assertStatus(200);


        $this->assertNotNull(
            $notification->fresh()->read_at
        );
    }


    public function test_customer_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create([
            'role' => 'customer'
        ]);


        $user->notifications()->create([
            'id' => fake()->uuid(),
            'type' => 'test',
            'data' => [
                'message' => 'Notification 1'
            ],
        ]);


        Sanctum::actingAs($user);


        $response = $this->postJson(
            '/api/v1/customer/notifications/read-all'
        );


        $response->assertStatus(200);
    }

}
