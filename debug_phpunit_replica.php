<?php
require __DIR__ . '/vendor/autoload.php';

putenv('APP_ENV=testing');
putenv('APP_DEBUG=1');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . __DIR__ . '/testing.sqlite');
putenv('CACHE_DRIVER=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');

use Tests\Feature\Admin\DashboardTest;
use ReflectionClass;
use ReflectionMethod;

if (!file_exists('testing.sqlite')) {
    file_put_contents('testing.sqlite', '');
}

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('migrate', ['--force' => true]);

$test = new DashboardTest('test_admin_can_view_dashboard');
$ref = new ReflectionClass($test);
$setup = $ref->getMethod('setUp');
$setup->setAccessible(true);
$tear = $ref->getMethod('tearDown');
$tear->setAccessible(true);

try {
    $setup->invoke($test);
    $test->test_admin_can_view_dashboard();
    echo "TEST PASSED\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    if (property_exists($test, 'response')) {
        $response = $test->response;
        if ($response) {
            echo "RESPONSE STATUS: " . $response->status() . "\n";
            echo "RESPONSE BODY: " . $response->getContent() . "\n";
        }
    }
}
$tear->invoke($test);
