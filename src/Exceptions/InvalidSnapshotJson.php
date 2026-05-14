<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Exceptions;

use RuntimeException;

final class InvalidSnapshotJson extends RuntimeException
{
    public static function for(string $snapshot): InvalidSnapshotJson
    {
        return new InvalidSnapshotJson(sprintf('Snapshot is not valid JSON: %s', $snapshot));
    }
}
