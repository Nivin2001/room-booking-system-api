<?php
require __DIR__ . '/vendor/autoload.php';
use App\Models\User;
use Laravel\Sanctum\Sanctum;

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = User::factory()->create(['role'=>'admin']);
Sanctum::actingAs($user);

$request = Illuminate\Http\Request::create('/api/v1/admin/dashboard', 'GET');
$request->headers->set('Accept', 'application/json');

$response = $app->handle($request);
echo $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";
