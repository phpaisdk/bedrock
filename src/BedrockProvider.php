<?php

declare(strict_types=1);

namespace AiSdk\Bedrock;

use AiSdk\Bedrock\Models\BedrockEmbeddingModel;
use AiSdk\Bedrock\Models\BedrockImageModel;
use AiSdk\Bedrock\Models\BedrockTextModel;
use AiSdk\Bedrock\Models\BedrockVideoModel;
use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Contracts\VideoProviderInterface;

final class BedrockProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, TextProviderInterface, VideoProviderInterface
{
    public function __construct(public readonly BedrockOptions $options) {}

    public function name(): string
    {
        return BedrockOptions::PROVIDER_NAME;
    }

    public function textModel(string $modelId): TextModelInterface
    {
        return new BedrockTextModel($modelId, $this->options);
    }

    public function imageModel(string $modelId): ImageModelInterface
    {
        return new BedrockImageModel($modelId, $this->options);
    }

    public function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new BedrockEmbeddingModel($modelId, $this->options);
    }
    public function videoModel(string $modelId): VideoModelInterface
    {
        return new BedrockVideoModel($modelId, $this->options);
    }
}
