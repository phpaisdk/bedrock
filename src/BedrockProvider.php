<?php

declare(strict_types=1);

namespace AiSdk\Bedrock;

use AiSdk\Bedrock\Models\BedrockImageModel;
use AiSdk\Bedrock\Models\BedrockTextModel;
use AiSdk\Contracts\BaseProvider;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Contracts\ImageProviderInterface;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Contracts\TextProviderInterface;

final class BedrockProvider extends BaseProvider implements ImageProviderInterface, TextProviderInterface
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
}
