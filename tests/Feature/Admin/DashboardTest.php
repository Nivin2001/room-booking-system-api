<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    /**
     * A basic feature test example.
     */

use RefreshDatabase;

    public function test_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin'
        ]);


        Sanctum::actingAs($admin);


        $response = $this->getJson(
            '/api/v1/admin/dashboard'
        );


        $response->assertStatus(200);
    }
}
