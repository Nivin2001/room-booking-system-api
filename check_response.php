<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$request = Illuminate\Http\Request::create('/api/v1/admin/dashboard', 'GET');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$response = $app->handle($request);
echo $response->getStatusCode() . "\n";
echo $response->getContent() . "\n";
