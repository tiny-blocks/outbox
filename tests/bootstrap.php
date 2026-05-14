<?php

declare(strict_types=1);

use Test\TinyBlocks\Outbox\Database;

require __DIR__ . '/../vendor/autoload.php';

Database::instance()->start();
