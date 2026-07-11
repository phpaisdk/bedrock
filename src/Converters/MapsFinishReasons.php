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
}
