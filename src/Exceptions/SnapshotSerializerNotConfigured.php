<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class SnapshotSerializerNotConfigured extends RuntimeException
{
    public static function for(string $aggregateType): SnapshotSerializerNotConfigured
    {
        return new SnapshotSerializerNotConfigured(
            sprintf('No snapshot serializer configured for aggregate type "%s".', $aggregateType)
        );
    }
}
