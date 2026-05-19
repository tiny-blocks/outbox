<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;
use Throwable;

final class DuplicateAggregateVersion extends RuntimeException
{
    private function __construct(
        ?Throwable $previous,
        public readonly mixed $aggregateId,
        public readonly string $aggregateType,
        public readonly int $aggregateVersion
    ) {
        $template = 'Duplicate aggregate version for <%s/%s> at aggregate version <%d>.';

        parent::__construct(
            message: sprintf($template, $aggregateType, $aggregateId, $aggregateVersion),
            previous: $previous
        );
    }

    /**
     * Creates a DuplicateAggregateVersion from the conflicting aggregate identity and version.
     *
     * @param Throwable|null $previous The driver-level violation that triggered the failure, or null.
     * @param mixed $aggregateId The identifier of the aggregate whose version collided.
     * @param string $aggregateType The fully-qualified type name of the aggregate.
     * @param int $aggregateVersion The aggregate version that collided with an existing record.
     * @return DuplicateAggregateVersion The created instance.
     */
    public static function forRecord(
        ?Throwable $previous,
        mixed $aggregateId,
        string $aggregateType,
        int $aggregateVersion
    ): DuplicateAggregateVersion {
        return new DuplicateAggregateVersion(
            previous: $previous,
            aggregateId: $aggregateId,
            aggregateType: $aggregateType,
            aggregateVersion: $aggregateVersion
        );
    }
}
