<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Mocks;

use Doctrine\DBAL\Driver\AbstractException;

final class DriverExceptionStub extends AbstractException
{
}
