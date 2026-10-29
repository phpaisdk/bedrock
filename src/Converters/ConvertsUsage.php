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
        $cacheRead = isset($usage['cacheReadInputTokens']) ? (int) $usage['cacheReadInputTokens'] : null;
        $cacheWrite = isset($usage['cacheWriteInputTokens']) ? (int) $usage['cacheWriteInputTokens'] : null;

        return new Usage(
            inputTokens: $input + ($cacheRead ?? 0) + ($cacheWrite ?? 0),
            outputTokens: $output,
            totalTokens: isset($usage['totalTokens']) ? (int) $usage['totalTokens'] : null,
            reasoningTokens: null,
            cachedInputTokens: $cacheRead,
            cacheWriteInputTokens: $cacheWrite,
        );
    }

    /**
     * OpenAI-compatible usage (chat completions / responses).
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromOpenAi(?array $usage): Usage
    {
        if ($usage === null || $usage === []) {
            return Usage::empty();
        }

        return \AiSdk\OpenAICompatible\Support\ChatUsage::fromArray($usage);
    }

    /**
     * Anthropic Messages usage.
     *
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromAnthropic(?array $usage): Usage
    {
        if ($usage === null || $usage === []) {
            return Usage::empty();
        }

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheRead = isset($usage['cache_read_input_tokens']) ? (int) $usage['cache_read_input_tokens'] : null;
        $cacheWrite = isset($usage['cache_creation_input_tokens']) ? (int) $usage['cache_creation_input_tokens'] : null;

        return new Usage(
            inputTokens: $input + ($cacheRead ?? 0) + ($cacheWrite ?? 0),
            outputTokens: $output,
            totalTokens: null,
            reasoningTokens: null,
            cachedInputTokens: $cacheRead,
            cacheWriteInputTokens: $cacheWrite,
        );
    }
}
