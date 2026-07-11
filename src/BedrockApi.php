<?php

declare(strict_types=1);

namespace AiSdk\Bedrock;

use AiSdk\Exceptions\InvalidArgumentException;

/**
 * Inference surfaces exposed by Amazon Bedrock.
 *
 * Two endpoints host these:
 *  - bedrock-runtime.{region}.amazonaws.com  -> Converse, Invoke
 *  - bedrock-mantle.{region}.api.aws/v1      -> OpenAI-compatible Chat Completions / Responses
 *
 * Converse is the default because it is the only surface that works uniformly
 * across every Bedrock model. Invoke gives native Anthropic Messages control,
 * while the mantle surfaces reuse the OpenAI-compatible wire format.
 */
enum BedrockApi: string
{
    case Converse = 'converse';
    case Invoke = 'invoke';
    case MantleChat = 'mantle_chat';
    case MantleResponses = 'mantle_responses';

    public static function default(): self
    {
        return self::Converse;
    }

    public function isMantle(): bool
    {
        return $this === self::MantleChat || $this === self::MantleResponses;
    }

    /**
     * Resolve from a user-supplied value (providerOptions / config['api']).
     */
    public static function resolve(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return self::default();
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            foreach (self::cases() as $case) {
                if ($case->value === $normalized) {
                    return $case;
                }
            }
        }

        throw new InvalidArgumentException('Invalid Bedrock API surface. Expected converse, invoke, mantle_chat, or mantle_responses.', [
            'api' => $value,
        ]);
    }
}
