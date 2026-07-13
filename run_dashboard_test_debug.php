<?php
require __DIR__ . '/vendor/autoload.php';
use Tests\Feature\Admin\DashboardTest;

$test = new DashboardTest('test_admin_can_view_dashboard');
try {
    $test->setUp();
    $test->test_admin_can_view_dashboard();
    echo "PASS\n";
} catch (Throwable $e) {
    echo get_class($e).": ".$e->getMessage()."\n";
    if (method_exists($test, 'response') && $test->response) {
        echo "RESPONSE: ";
        var_dump($test->response->status());
        echo $test->response->getContent();
    }
}
$test->tearDown();
