<?php
declare(strict_types=1);
/**
 * @astrake/lumora-webserver — Canonical Front Controller
 */

define('LUMORA_BOOTSTRAPPED', true);

require_once __DIR__ . '/core/bootstrap.php';

\Lumora\Core\Bootstrap::handleRequest(__DIR__);
