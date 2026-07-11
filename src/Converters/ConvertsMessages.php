<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Converters;

use AiSdk\Content;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Message;

final class ConvertsMessages
{
    /** @var array<string, string> */
    private const IMAGE_FORMATS = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * @param  array<int, Message>  $messages
     * @return array{system: array<int, array<string, mixed>>, messages: array<int, array<string, mixed>>}
     */
    public static function toConverseMessages(array $messages, ?string $systemPrompt): array
    {
        $system = [];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $system[] = ['text' => $systemPrompt];
        }

        $nonSystem = [];
        foreach ($messages as $message) {
            if ($message->role === Message::ROLE_SYSTEM) {
                $system[] = ['text' => $message->text()];
            } else {
                $nonSystem[] = $message;
            }
        }

        $blocks = self::groupIntoBlocks($nonSystem);

        $outMessages = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'user') {
                $content = [];
                foreach ($block['messages'] as $msg) {
                    if ($msg->role === Message::ROLE_TOOL) {
                        $content[] = [
                            'toolResult' => [
                                'toolUseId' => (string) ($msg->toolCallId ?? ''),
                                'content' => [['text' => $msg->text()]],
                            ],
                        ];

                        continue;
                    }

                    foreach ($msg->content as $part) {
                        foreach (self::convertUserContent($part) as $blockPart) {
                            $content[] = $blockPart;
                        }
                    }
                }
                if ($content !== []) {
                    $outMessages[] = ['role' => 'user', 'content' => $content];
                }

                continue;
            }

            $content = [];
            foreach ($block['messages'] as $msg) {
                foreach ($msg->content as $part) {
                    $text = $part->textValue();
                    if ($text !== null && $text !== '') {
                        $content[] = ['text' => $text];
                    }
                }
            }
            if ($content !== []) {
                $outMessages[] = ['role' => 'assistant', 'content' => $content];
            }
        }

        return ['system' => $system, 'messages' => $outMessages];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function convertUserContent(Content $content): array
    {
        return match ($content->type) {
            Content::TYPE_TEXT => [['text' => (string) $content->textValue()]],
            Content::TYPE_IMAGE => [self::imageBlock((string) ($content->base64Data() ?? $content->data()), (string) $content->mimeType())],
            default => [['text' => (string) ($content->textValue() ?? '')]],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageBlock(string $base64, string $mime): array
    {
        if ($base64 === '') {
            throw new InvalidArgumentException('Bedrock image input must be local binary, base64, or a data URI. Remote image URLs are not fetched by the SDK.');
        }

        $format = self::IMAGE_FORMATS[$mime] ?? null;
        if ($format === null) {
            throw new InvalidArgumentException("Unsupported image mime type for Bedrock: {$mime}");
        }

        return [
            'image' => [
                'format' => $format,
                'source' => ['bytes' => $base64],
            ],
        ];
    }

    /**
     * @param  array<int, Message>  $messages
     * @return array<int, array{type: string, messages: array<int, Message>}>
     */
    private static function groupIntoBlocks(array $messages): array
    {
        /** @var array<int, array{type: string, messages: array<int, Message>}> $blocks */
        $blocks = [];
        foreach ($messages as $message) {
            $type = $message->role === Message::ROLE_ASSISTANT ? 'assistant' : 'user';

            $lastIndex = count($blocks) - 1;
            if ($lastIndex >= 0 && $blocks[$lastIndex]['type'] === $type) {
                $blocks[$lastIndex]['messages'][] = $message;
            } else {
                $blocks[] = ['type' => $type, 'messages' => [$message]];
            }
        }

        return $blocks;
    }
}
