<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    /**
     * A basic feature test example.
     */

public function test_customer_can_get_notifications()
{

$user = User::factory()->create([
    'role'=>'customer'
]);


Sanctum::actingAs($user);


$response=$this->getJson(
'/api/v1/customer/notifications'
);


$response->assertStatus(200);

}



public function test_customer_can_mark_notification_read()
{

$user=User::factory()->create([
'role'=>'customer'
]);


$notification=$user->notifications()->create([

'id'=>fake()->uuid(),

'type'=>'test',

'data'=>[
'message'=>'hello'
]

]);


Sanctum::actingAs($user);


$response=$this->postJson(

"/api/v1/customer/notifications/{$notification->id}/read"

);


$response->assertStatus(200);


}
}
