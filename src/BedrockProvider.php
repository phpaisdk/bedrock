<?php

declare(strict_types=1);

namespace AiSdk\Bedrock;

use AiSdk\Bedrock\Models\BedrockEmbeddingModel;
use AiSdk\Bedrock\Models\BedrockImageModel;
use AiSdk\Bedrock\Models\BedrockLiveModel;
use AiSdk\Bedrock\Models\BedrockTextModel;
use AiSdk\Bedrock\Models\BedrockVideoModel;
use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Contracts\EmbeddingProviderInterface;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Contracts\VideoProviderInterface;
use AiSdk\Live\Contracts\LiveModelInterface;

final class BedrockProvider extends BaseProvider implements EmbeddingProviderInterface, ImageProviderInterface, LiveProviderInterface, TextProviderInterface, VideoProviderInterface
{
    public function __construct(public readonly BedrockOptions $options) {}

    public function name(): string
    {
        return BedrockOptions::PROVIDER_NAME;
    }

    protected function textModel(string $modelId): TextModelInterface
    {
        return new BedrockTextModel($modelId, $this->options);
    }

    protected function imageModel(string $modelId): ImageModelInterface
    {
        return new BedrockImageModel($modelId, $this->options);
    }

    protected function embeddingModel(string $modelId): EmbeddingModelInterface
    {
        return new BedrockEmbeddingModel($modelId, $this->options);
    }

    protected function videoModel(string $modelId): VideoModelInterface
    {
        return new BedrockVideoModel($modelId, $this->options);
    }

    protected function liveModel(string $modelId): LiveModelInterface
    {
        return new BedrockLiveModel($modelId, $this->options);
    }
}
