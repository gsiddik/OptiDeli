<?php

use App\Domain\Dispatch\DispatchService;
use App\Domain\Shared\TenantStore;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$deadline = microtime(true) + 10;
while (! file_exists($argv[4]) && microtime(true) < $deadline) {
    usleep(1000);
}
if (! file_exists($argv[4])) {
    fwrite(STDERR, 'Barrier timeout');
    exit(2);
}
try {
    $trip = $app->make(DispatchService::class)->confirm(new TenantStore($argv[1], (int) $argv[2]), $argv[3]);
    echo json_encode(['status' => 200, 'trip_id' => $trip->id], JSON_THROW_ON_ERROR);
} catch (HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
