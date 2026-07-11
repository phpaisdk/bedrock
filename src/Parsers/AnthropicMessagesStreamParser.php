<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Parsers;

use AiSdk\Bedrock\Converters\ConvertsUsage;
use AiSdk\Bedrock\Converters\MapsFinishReasons;
use AiSdk\FinishReason;
use AiSdk\Streaming\FinishPart;
use AiSdk\Streaming\ReasoningDeltaPart;
use AiSdk\Streaming\StreamPart;
use AiSdk\Streaming\TextDeltaPart;
use AiSdk\Streaming\ToolCallDeltaPart;
use AiSdk\Streaming\ToolCallStartPart;
use AiSdk\Support\Usage;
use Generator;

final class AnthropicMessagesStreamParser
{
    /**
     * @param  iterable<int, array<string, mixed>>  $events
     * @return Generator<int, StreamPart>
     */
    public static function parse(iterable $events): Generator
    {
        $usage = Usage::empty();
        $finishReason = FinishReason::Unknown;

        foreach ($events as $payload) {
            $type = $payload['type'] ?? null;

            if ($type === 'message_start' && isset($payload['message']['usage']) && is_array($payload['message']['usage'])) {
                $usage = ConvertsUsage::fromAnthropic($payload['message']['usage']);
            }

            if ($type === 'content_block_start' && ($payload['content_block']['type'] ?? null) === 'tool_use') {
                yield new ToolCallStartPart(
                    index: (int) ($payload['index'] ?? 0),
                    id: (string) ($payload['content_block']['id'] ?? ''),
                    name: (string) ($payload['content_block']['name'] ?? ''),
                );
            }

            if ($type === 'content_block_delta') {
                $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
                if (($delta['type'] ?? null) === 'text_delta' && is_string($delta['text'] ?? null)) {
                    yield new TextDeltaPart($delta['text']);
                }
                if (($delta['type'] ?? null) === 'thinking_delta' && is_string($delta['thinking'] ?? null)) {
                    yield new ReasoningDeltaPart($delta['thinking']);
                }
                if (($delta['type'] ?? null) === 'input_json_delta' && is_string($delta['partial_json'] ?? null)) {
                    yield new ToolCallDeltaPart((int) ($payload['index'] ?? 0), $delta['partial_json']);
                }
            }

            if ($type === 'message_delta') {
                if (isset($payload['usage']) && is_array($payload['usage'])) {
                    $usage = ConvertsUsage::fromAnthropic($payload['usage']);
                }
                if (isset($payload['delta']['stop_reason'])) {
                    $finishReason = MapsFinishReasons::fromAnthropic((string) $payload['delta']['stop_reason']);
                }
            }
        }

        yield new FinishPart($finishReason, $usage);
    }
}
