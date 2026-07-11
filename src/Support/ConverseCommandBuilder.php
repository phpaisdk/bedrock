<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Support;

use AiSdk\Bedrock\Converters\ConvertsMessages;
use AiSdk\Requests\TextModelRequest;

final class ConverseCommandBuilder
{
    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, mixed>
     */
    public static function build(string $modelId, TextModelRequest $request, array $providerOptions): array
    {
        $converted = ConvertsMessages::toConverseMessages($request->messages, $request->system);

        $temperature = max(0.0, min(1.0, $request->temperature));

        $inference = ['maxTokens' => $request->maxTokens];
        if ($request->reasoning === null) {
            $inference['temperature'] = $temperature;
        }
        if ($request->topP !== null && $request->reasoning === null) {
            $inference['topP'] = $request->topP;
        }

        $command = [
            'system' => $converted['system'],
            'messages' => $converted['messages'],
            'inferenceConfig' => $inference,
        ];

        if ($request->reasoning !== null) {
            $thinking = $request->reasoning->budgetTokens !== null
                ? ['type' => 'enabled', 'budget_tokens' => $request->reasoning->budgetTokens]
                : ['type' => 'adaptive'];
            $additional = ['thinking' => $thinking];
            if ($request->reasoning->effort !== null) {
                $additional['output_config'] = ['effort' => $request->reasoning->effort];
            }
            $command['additionalModelRequestFields'] = $additional;
        }

        unset($providerOptions['raw']);
        $command = array_replace($command, $providerOptions);

        return $command;
    }
}
