<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;
use Throwable;

final class DuplicateAggregateSequence extends RuntimeException
{
    private const int CODE = 0;

    public function __construct(
        public readonly string $aggregateId,
        public readonly string $aggregateType,
        public readonly int $sequenceNumber,
        ?Throwable $previous = null
    ) {
        $template = 'Duplicate aggregate sequence for <%s/%s> at sequence number <%d>.';

        parent::__construct(sprintf($template, $aggregateType, $aggregateId, $sequenceNumber), self::CODE, $previous);
    }
}
