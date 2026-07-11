<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Converters;

use AiSdk\Support\Usage;

final class ConvertsUsage
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromBedrock(?array $usage): Usage
    {
        if ($usage === null || $usage === []) {
            return Usage::empty();
        }

        $input = (int) ($usage['inputTokens'] ?? 0);
        $output = (int) ($usage['outputTokens'] ?? 0);
        $cacheRead = (int) ($usage['cacheReadInputTokens'] ?? 0);
        $cacheWrite = (int) ($usage['cacheWriteInputTokens'] ?? 0);

        return new Usage(
            inputTokens: $input + $cacheRead + $cacheWrite,
            outputTokens: $output,
            totalTokens: null,
            reasoningTokens: null,
            cachedInputTokens: $cacheRead > 0 ? $cacheRead : null,
        );
    }
}
