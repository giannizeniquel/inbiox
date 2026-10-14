#!/usr/bin/env php
<?php
/**
 * Outbox Publisher Runner
 * 
 * Run from api directory:
 * cd apps/api && php bin/outbox-publish.php
 * Or in loop: while true; do php bin/outbox-publish.php; sleep 1; done
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Kernel;

$kernel = new Kernel('dev', false);
$kernel->boot();

$container = $kernel->getContainer();

/** @var \App\Compartido\Outbox\OutboxPublisherService $publisher */
$publisher = $container->get(\App\Compartido\Outbox\OutboxPublisherService::class);

$count = $publisher->publishPending();

echo sprintf("Published %d events to Mercure\n", $count);