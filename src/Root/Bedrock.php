<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\BedrockProvider;
use AiSdk\Contracts\Model;

final class Bedrock
{
    private static ?BedrockProvider $default = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public static function create(array $config = []): BedrockProvider
    {
        return self::$default = new BedrockProvider(BedrockOptions::fromArray($config));
    }

    public static function default(): BedrockProvider
    {
        return self::$default ??= self::create();
    }

    public static function reset(): void
    {
        self::$default = null;
    }

    public static function model(string $modelId): Model
    {
        return self::default()->model($modelId);
    }
}
