<?php
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . __DIR__ . '/testing.sqlite');
putenv('CACHE_DRIVER=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->call('migrate', ['--force' => true]);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

$user = User::factory()->create(['role' => 'admin']);
Sanctum::actingAs($user);

$request = Illuminate\Http\Request::create('/api/v1/admin/dashboard', 'GET');
$request->headers->set('Accept', 'application/json');
$response = $app->handle($request);
echo $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";
