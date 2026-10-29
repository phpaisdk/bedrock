<?php

declare(strict_types=1);

use AiSdk\Bedrock\Converters\ConvertsUsage;
use AiSdk\Bedrock\Parsers\AnthropicMessagesStreamParser;

it('includes cache reads and writes for Converse and native Anthropic', function (): void {
    $converse = ConvertsUsage::fromBedrock(['inputTokens' => 30, 'outputTokens' => 20, 'cacheReadInputTokens' => 60, 'cacheWriteInputTokens' => 10, 'totalTokens' => 999]);
    $anthropic = ConvertsUsage::fromAnthropic(['input_tokens' => 30, 'output_tokens' => 20, 'cache_read_input_tokens' => 60, 'cache_creation_input_tokens' => 10]);
    foreach ([$converse, $anthropic] as $usage) {
        expect($usage->inputTokens)->toBe(100)->and($usage->outputTokens)->toBe(20)
            ->and($usage->cachedInputTokens)->toBe(60)->and($usage->cacheWriteInputTokens)->toBe(10)->and($usage->reasoningTokens)->toBeNull();
    }
    expect($converse->totalTokens)->toBe(999)->and($anthropic->totalTokens)->toBe(120);
});

it('retains Anthropic initial cache usage when an output-only cumulative delta arrives', function (): void {
    $parts = iterator_to_array(AnthropicMessagesStreamParser::parse([
        ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 30, 'cache_read_input_tokens' => 60, 'cache_creation_input_tokens' => 10, 'output_tokens' => 1]]],
        ['type' => 'message_delta', 'usage' => ['output_tokens' => 15]],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 20]],
    ]));
    $usage = $parts[array_key_last($parts)]->usage;
    expect($usage->inputTokens)->toBe(100)->and($usage->cachedInputTokens)->toBe(60)->and($usage->cacheWriteInputTokens)->toBe(10)->and($usage->outputTokens)->toBe(20)->and($usage->totalTokens)->toBe(120);
});

it('distinguishes absent Bedrock subsets from reported zero', function (): void {
    foreach ([
        [ConvertsUsage::fromBedrock(['inputTokens' => 100]), ConvertsUsage::fromBedrock(['inputTokens' => 100, 'cacheReadInputTokens' => 0, 'cacheWriteInputTokens' => 0])],
        [ConvertsUsage::fromAnthropic(['input_tokens' => 100]), ConvertsUsage::fromAnthropic(['input_tokens' => 100, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0])],
    ] as [$missing, $zero]) {
        expect($missing->cachedInputTokens)->toBeNull()->and($missing->cacheWriteInputTokens)->toBeNull();
        expect($zero->inputTokens)->toBe(100)->and($zero->cachedInputTokens)->toBe(0)->and($zero->cacheWriteInputTokens)->toBe(0);
    }
});

it('uses inclusive OpenAI usage and reasoning subsets for the compatible converter', function (): void {
    $usage = ConvertsUsage::fromOpenAi(['prompt_tokens' => 100, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 60, 'cache_write_tokens' => 10], 'completion_tokens_details' => ['reasoning_tokens' => 5]]);
    expect($usage->inputTokens)->toBe(100)->and($usage->cachedInputTokens)->toBe(60)->and($usage->cacheWriteInputTokens)->toBe(10)->and($usage->reasoningTokens)->toBe(5)->and($usage->totalTokens)->toBe(120);
});
