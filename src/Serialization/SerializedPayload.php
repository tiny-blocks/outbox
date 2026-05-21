<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;

final readonly class SerializedPayload
{
    private function __construct(private string $payload)
    {
    }

    /**
     * Creates a SerializedPayload from a raw JSON string.
     *
     * @param string $payload The JSON-encoded payload string.
     * @return SerializedPayload The serialized payload wrapping the validated JSON string.
     * @throws InvalidPayloadJson If the string is not valid JSON.
     */
    public static function from(string $payload): SerializedPayload
    {
        if (!json_validate($payload)) {
            throw InvalidPayloadJson::forPayload(payload: $payload);
        }

        return new SerializedPayload(payload: $payload);
    }

    /**
     * Creates a SerializedPayload from an associative array, encoding it as JSON.
     *
     * @param array<int|string, mixed> $payload The associative array to encode as the serialized payload.
     * @return SerializedPayload The serialized payload with the JSON-encoded representation.
     */
    public static function fromArray(array $payload): SerializedPayload
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return new SerializedPayload(payload: $json);
    }

    /**
     * Returns the SerializedPayload as its JSON string representation.
     *
     * @return string The JSON-encoded payload.
     */
    public function toJson(): string
    {
        return $this->payload;
    }
}
