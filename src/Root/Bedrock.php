<?php

declare(strict_types=1);

namespace AiSdk;

use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\BedrockProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\VideoModelInterface;

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

    public static function model(string $modelId): TextModelInterface
    {
        return self::default()->textModel($modelId);
    }

    public static function image(string $modelId): ImageModelInterface
    {
        return self::default()->imageModel($modelId);
    }

    public static function embedding(string $modelId): EmbeddingModelInterface
    {
        return self::default()->embeddingModel($modelId);
    }
    public static function video(string $modelId): VideoModelInterface
    {
        return self::default()->videoModel($modelId);
    }
}
