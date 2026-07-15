<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Aws;

final readonly class EventStreamMessage
{
    /** @param array<string, mixed> $headers */
    public function __construct(public array $headers, public string $payload) {}

    public function stringHeader(string $name): ?string
    {
        $value = $this->headers[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
