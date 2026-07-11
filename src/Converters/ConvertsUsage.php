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

        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? 0);
        $cacheRead = (int) ($usage['prompt_tokens_details']['cached_tokens']
            ?? $usage['cache_read_input_tokens'] ?? 0);

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: (int) ($usage['total_tokens'] ?? 0) ?: null,
            reasoningTokens: null,
            cachedInputTokens: $cacheRead > 0 ? $cacheRead : null,
        );
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
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: null,
            reasoningTokens: (int) ($usage['output_tokens_details']['reasoning_tokens']
                ?? $usage['reasoning_tokens'] ?? 0) ?: null,
            cachedInputTokens: $cacheRead > 0 ? $cacheRead : null,
        );
    }
}
