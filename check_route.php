<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$request = Illuminate\Http\Request::create('/api/v1/admin/dashboard', 'GET');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$router = $app->make('router');
try {
    $route = $router->getRoutes()->match($request);
    var_dump($route->getAction());
} catch (Exception $e) {
    echo 'ERR '.get_class($e).': '.$e->getMessage()."\n";
}
