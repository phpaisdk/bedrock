<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Support;

use AiSdk\Content;
use AiSdk\ContentSource;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Message;
use AiSdk\Outputs\Output;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Tool;

final class AnthropicMessagesCommandBuilder
{
    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, mixed>
     */
    public static function build(TextModelRequest $request, array $providerOptions): array
    {
        $command = [
            'anthropic_version' => $providerOptions['anthropic_version'] ?? 'bedrock-2023-05-31',
            'messages' => self::messages($request),
            'max_tokens' => $request->maxTokens,
        ];

        $system = self::system($request);
        if ($system !== null) {
            $command['system'] = $system;
        }

        if ($request->reasoning === null) {
            $command['temperature'] = $request->temperature;
            if ($request->topP !== null) {
                $command['top_p'] = $request->topP;
            }
        } else {
            $command['thinking'] = $request->reasoning->budgetTokens !== null
                ? ['type' => 'enabled', 'budget_tokens' => $request->reasoning->budgetTokens]
                : ['type' => 'adaptive'];
        }

        if ($request->tools !== []) {
            $command['tools'] = array_map(self::tool(...), $request->tools);
        }

        if ($request->output instanceof Output && $request->output->kind === Output::KIND_OBJECT && $request->output->schema !== null) {
            $command['output_config'] = ['format' => [
                'type' => 'json_schema',
                'schema' => $request->output->schema->jsonSchema(),
            ]];
        }

        $raw = $providerOptions['raw'] ?? null;
        unset($providerOptions['anthropic_version'], $providerOptions['raw']);
        $command = array_replace($command, $providerOptions);

        return is_array($raw) ? array_replace($command, $raw) : $command;
    }

    /** @return array<int, array<string, mixed>> */
    private static function messages(TextModelRequest $request): array
    {
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === Message::ROLE_SYSTEM) {
                continue;
            }

            if ($message->role === Message::ROLE_TOOL) {
                $messages[] = ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content' => $message->text(),
                ]]];

                continue;
            }

            $content = array_map(self::content(...), $message->content);
            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments,
                ];
            }

            $messages[] = [
                'role' => $message->role === Message::ROLE_ASSISTANT ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $messages;
    }

    private static function system(TextModelRequest $request): ?string
    {
        $system = [];
        if ($request->system !== null && trim($request->system) !== '') {
            $system[] = $request->system;
        }

        foreach ($request->messages as $message) {
            if ($message->role === Message::ROLE_SYSTEM && trim($message->text()) !== '') {
                $system[] = trim($message->text());
            }
        }

        return $system === [] ? null : implode("\n\n", $system);
    }

    /** @return array<string, mixed> */
    private static function content(Content $content): array
    {
        return match ($content->type) {
            Content::TYPE_TEXT => ['type' => 'text', 'text' => (string) $content->textValue()],
            Content::TYPE_IMAGE => self::media('image', $content),
            Content::TYPE_FILE => self::media('document', $content),
            default => throw new InvalidArgumentException("Unsupported Anthropic content type [{$content->type}]."),
        };
    }

    /** @return array<string, mixed> */
    private static function media(string $type, Content $content): array
    {
        if ($content->source() === ContentSource::Url) {
            return ['type' => $type, 'source' => ['type' => 'url', 'url' => (string) $content->url()]];
        }

        return ['type' => $type, 'source' => [
            'type' => 'base64',
            'media_type' => (string) $content->mimeType(),
            'data' => (string) $content->base64Data(),
        ]];
    }

    /** @return array<string, mixed> */
    private static function tool(Tool $tool): array
    {
        return [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->inputSchemaForProvider(),
        ];
    }
}
