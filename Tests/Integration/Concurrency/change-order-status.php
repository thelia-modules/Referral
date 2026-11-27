<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * One process of SponsorRewardConcurrencyTest: boots the test kernel, says it is ready, waits for the
 * start signal, then moves one order to a status through ORDER_UPDATE_STATUS, as a payment module does.
 *
 * Usage: php change-order-status.php <order id> <status code> <ready file> <start file>
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

[, $orderId, $statusCode, $readyFile, $startFile] = $argv + [null, null, null, null, null];
if (!is_numeric($orderId) || !is_string($statusCode) || !is_string($readyFile) || !is_string($startFile)) {
    fwrite(\STDERR, "Usage: php change-order-status.php <order id> <status code> <ready file> <start file>\n");
    exit(2);
}

$projectDir = dirname(__DIR__, 6);
require $projectDir.'/tests/bootstrap.php';

$kernel = new App\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

// What IntegrationTestCase sets up before any listener runs.
$container->get('thelia.translator');
$container->get('thelia.url.manager');
$request = Request::create('http://localhost');
$request->setSession(new Session(new MockArraySessionStorage()));
$container->get('request_stack')->push($request);

$order = OrderQuery::create()->findPk((int) $orderId);
$status = OrderStatusQuery::create()->findOneByCode($statusCode);
if (!$order instanceof Order || !$status instanceof OrderStatus) {
    fwrite(\STDERR, "Unknown order or status.\n");
    exit(3);
}

touch($readyFile);
$deadline = microtime(true) + 60;
while (!file_exists($startFile)) {
    if (microtime(true) > $deadline) {
        fwrite(\STDERR, "No start signal.\n");
        exit(4);
    }
    usleep(5_000);
}

$container->get('event_dispatcher')->dispatch((new OrderEvent($order))->setStatus($status->getId()), TheliaEvents::ORDER_UPDATE_STATUS);

$kernel->shutdown();
exit(0);
