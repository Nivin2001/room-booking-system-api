<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$request = Illuminate\Http\Request::create('/api/v1/admin/dashboard', 'GET');
$router = $app->make(Illuminate\Routing\Router::class);
$routes = $router->getRoutes();
foreach ($routes as $route) {
    if ($route->uri() === 'api/v1/admin/dashboard') {
        echo "FOUND route: ";
        print_r($route->methods());
        print_r($route->action);
        break;
    }
}
try {
    $matched = $routes->match($request);
    echo "Matched: " . $matched->uri() . "\n";
    print_r($matched->action);
} catch (Exception $e) {
    echo 'EX: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
