<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Converters;

use AiSdk\FinishReason;

final class MapsFinishReasons
{
    public static function fromBedrock(?string $reason, bool $jsonResponseFromTool = false): FinishReason
    {
        return match ($reason) {
            'stop_sequence', 'end_turn', 'stop' => FinishReason::Stop,
            'max_tokens', 'length' => FinishReason::Length,
            'tool_use' => $jsonResponseFromTool ? FinishReason::Stop : FinishReason::ToolCalls,
            'content_filtered', 'guardrail_intervened', 'content-filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    public static function fromAnthropic(?string $reason): FinishReason
    {
        return match ($reason) {
            'end_turn', 'stop_sequence', 'stop' => FinishReason::Stop,
            'max_tokens' => FinishReason::Length,
            'tool_use' => FinishReason::ToolCalls,
            'content_filtered', 'guardrail_intervened' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    public static function fromOpenAi(?string $reason): FinishReason
    {
        return match ($reason) {
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'tool_calls' => FinishReason::ToolCalls,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
