<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Live;

use AiSdk\Bedrock\Auth\StreamingSigV4Session;
use AiSdk\Bedrock\Aws\EventStream;
use AiSdk\Bedrock\Aws\EventStreamDecoder;
use AiSdk\Bedrock\Aws\EventStreamMessage;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\UnsupportedLiveActionException;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveClosed;
use AiSdk\Live\LiveError;
use AiSdk\Live\LiveEvent;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\ProviderEvent;
use AiSdk\Live\ResponseCompleted;
use AiSdk\Live\SpeechStarted;
use AiSdk\Live\SpeechStopped;
use AiSdk\Live\TextDelta;
use AiSdk\Live\ToolCallEvent;
use AiSdk\Live\TranscriptCompleted;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Live\TranscriptSource;
use AiSdk\Live\TranscriptUpdate;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\UsageEvent;
use JsonException;

final class BedrockLiveSession implements LiveSessionDriverInterface
{
    private readonly EventStreamDecoder $decoder;

    private readonly string $promptName;

    private readonly string $audioContentName;

    /** @var array<string, array{role: string, type: string, stage: string}> */
    private array $content = [];

    /** @var array<string, string> */
    private array $transcripts = [];

    /** @var array<string, array<string, mixed>> */
    private array $pendingTools = [];

    private bool $closed = false;

    /** @param array<string, mixed> $providerOptions */
    private function __construct(
        private readonly TransportConnectionInterface $connection,
        private readonly StreamingSigV4Session $signing,
        private readonly LiveRequest $request,
        private readonly array $providerOptions,
        private readonly string $modelId,
    ) {
        $this->decoder = new EventStreamDecoder();
        $this->promptName = $this->stringOption('promptName') ?? self::uuid();
        $this->audioContentName = $this->stringOption('audioContentName') ?? self::uuid();
    }

    public static function start(
        TransportConnectionInterface $connection,
        StreamingSigV4Session $signing,
        LiveRequest $request,
        string $modelId,
    ): self {
        $options = $request->providerOptions[BedrockOptions::PROVIDER_NAME] ?? [];
        $session = new self($connection, $signing, $request, $options, $modelId);
        $session->initialize();

        return $session;
    }

    public function sendAudio(string $bytes): void
    {
        $this->ensureOpen();
        $this->sendEvent('audioInput', [
            'promptName' => $this->promptName,
            'contentName' => $this->audioContentName,
            'content' => base64_encode($bytes),
        ]);
    }

    public function sendText(string $text): void
    {
        $this->ensureOpen();

        if (! $this->isNova2()) {
            throw UnsupportedLiveActionException::for(
                BedrockOptions::PROVIDER_NAME,
                LiveOperation::Voice,
                'sendText',
            );
        }

        $contentName = self::uuid();
        $this->sendEvent('contentStart', [
            'promptName' => $this->promptName,
            'contentName' => $contentName,
            'type' => 'TEXT',
            'interactive' => true,
            'role' => 'USER',
            'textInputConfiguration' => ['mediaType' => 'text/plain'],
        ]);
        $this->sendEvent('textInput', [
            'promptName' => $this->promptName,
            'contentName' => $contentName,
            'content' => $text,
        ]);
        $this->sendEvent('contentEnd', [
            'promptName' => $this->promptName,
            'contentName' => $contentName,
        ]);
    }

    public function commitAudio(): void
    {
        throw UnsupportedLiveActionException::for(BedrockOptions::PROVIDER_NAME, LiveOperation::Voice, 'commitAudio');
    }

    public function clearAudio(): void
    {
        throw UnsupportedLiveActionException::for(BedrockOptions::PROVIDER_NAME, LiveOperation::Voice, 'clearAudio');
    }

    public function requestResponse(): void
    {
        throw UnsupportedLiveActionException::for(BedrockOptions::PROVIDER_NAME, LiveOperation::Voice, 'requestResponse');
    }

    public function cancelResponse(): void
    {
        throw UnsupportedLiveActionException::for(BedrockOptions::PROVIDER_NAME, LiveOperation::Voice, 'cancelResponse');
    }

    public function sendToolResult(string $callId, mixed $result): void
    {
        $this->ensureOpen();
        $contentName = self::uuid();
        $this->sendEvent('contentStart', [
            'promptName' => $this->promptName,
            'contentName' => $contentName,
            'interactive' => false,
            'type' => 'TOOL',
            'role' => 'TOOL',
            'toolResultInputConfiguration' => [
                'toolUseId' => $callId,
                'type' => 'TEXT',
                'textInputConfiguration' => ['mediaType' => 'text/plain'],
            ],
        ]);
        $this->sendEvent('toolResult', [
            'promptName' => $this->promptName,
            'contentName' => $contentName,
            'content' => $this->json($result),
        ]);
        $this->sendEvent('contentEnd', [
            'promptName' => $this->promptName,
            'contentName' => $contentName,
        ]);
    }

    /** @return iterable<LiveEvent> */
    public function events(): iterable
    {
        while (($frame = $this->connection->receive()) !== null) {
            foreach ($this->decoder->push($frame->payload) as $message) {
                foreach ($this->mapMessage($message) as $event) {
                    yield $event;
                }
            }
        }

        $this->decoder->finish();
        $this->closed = true;

        yield new LiveClosed(reason: 'Bedrock closed the bidirectional stream.');
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->sendEvent('contentEnd', [
            'promptName' => $this->promptName,
            'contentName' => $this->audioContentName,
        ]);
        $this->sendEvent('promptEnd', ['promptName' => $this->promptName]);
        $this->sendEvent('sessionEnd', []);

        $this->connection->finishSending();
        $this->closed = true;
    }

    private function initialize(): void
    {
        $inference = $this->arrayOption('inferenceConfiguration') ?? [
            'maxTokens' => 1024,
            'topP' => 0.9,
            'temperature' => 0.7,
        ];
        $sessionStart = ['inferenceConfiguration' => $inference];
        $turnDetection = $this->turnDetection();
        if ($turnDetection !== null) {
            $sessionStart['turnDetectionConfiguration'] = $turnDetection;
        }
        $sessionStart = array_replace_recursive(
            $sessionStart,
            $this->arrayOption('sessionStart') ?? [],
        );
        $this->sendEvent('sessionStart', $sessionStart);

        $promptStart = [
            'promptName' => $this->promptName,
            'textOutputConfiguration' => ['mediaType' => 'text/plain'],
            'audioOutputConfiguration' => $this->audioConfiguration('output', [
                'mediaType' => 'audio/lpcm',
                'sampleRateHertz' => 24_000,
                'sampleSizeBits' => 16,
                'channelCount' => 1,
                'voiceId' => (string) ($this->request->options['voice'] ?? 'matthew'),
                'encoding' => 'base64',
                'audioType' => 'SPEECH',
            ]),
            'toolUseOutputConfiguration' => ['mediaType' => 'application/json'],
        ];
        if ($this->request->tools !== []) {
            $tools = [];
            foreach ($this->request->tools as $tool) {
                $schema = $tool->inputSchemaForProvider();
                $tools[] = ['toolSpec' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    // Nova Sonic v1's raw bidirectional protocol expects a
                    // stringified schema. Nova 2 Sonic accepts the structured
                    // schema used by the current protocol.
                    'inputSchema' => ['json' => $this->isNova2() ? $schema : $this->json($schema)],
                ]];
            }
            $promptStart['toolConfiguration'] = [
                'tools' => $tools,
                'toolChoice' => $this->arrayOption('toolChoice') ?? ['auto' => new \stdClass()],
            ];
        }
        $promptStart = array_replace_recursive(
            $promptStart,
            $this->arrayOption('promptStart') ?? [],
        );
        $this->sendEvent('promptStart', $promptStart);

        $instructions = $this->request->options['instructions'] ?? null;
        if (is_string($instructions) && $instructions !== '') {
            $contentName = self::uuid();
            $this->sendEvent('contentStart', [
                'promptName' => $this->promptName,
                'contentName' => $contentName,
                'type' => 'TEXT',
                'interactive' => false,
                'role' => 'SYSTEM',
                'textInputConfiguration' => ['mediaType' => 'text/plain'],
            ]);
            $this->sendEvent('textInput', [
                'promptName' => $this->promptName,
                'contentName' => $contentName,
                'content' => $instructions,
            ]);
            $this->sendEvent('contentEnd', [
                'promptName' => $this->promptName,
                'contentName' => $contentName,
            ]);
        }

        $this->sendEvent('contentStart', [
            'promptName' => $this->promptName,
            'contentName' => $this->audioContentName,
            'type' => 'AUDIO',
            'interactive' => true,
            'role' => 'USER',
            'audioInputConfiguration' => $this->audioConfiguration('input', [
                'mediaType' => 'audio/lpcm',
                'sampleRateHertz' => 16_000,
                'sampleSizeBits' => 16,
                'channelCount' => 1,
                'audioType' => 'SPEECH',
                'encoding' => 'base64',
            ]),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function sendEvent(string $name, array $payload): void
    {
        $json = $this->json(['event' => [$name => $payload]]);
        if (strlen($json) > 1_000_000) {
            throw new InvalidArgumentException(
                'A Bedrock bidirectional input event cannot exceed 1,000,000 bytes.',
                ['provider' => BedrockOptions::PROVIDER_NAME, 'event' => $name],
            );
        }
        $message = EventStream::encodeChunk($json);
        $this->connection->send(TransportFrame::binary($this->signing->sign($message)));
    }

    /** @return list<LiveEvent> */
    private function mapMessage(EventStreamMessage $message): array
    {
        $messageType = $message->stringHeader(':message-type') ?? '';
        $eventType = $message->stringHeader(':event-type')
            ?? $message->stringHeader(':exception-type')
            ?? $message->stringHeader(':error-code')
            ?? '';

        if ($messageType === 'exception' || $messageType === 'error' || str_ends_with($eventType, 'Exception')) {
            $details = $this->decodeObject($message->payload);
            $headerMessage = $message->stringHeader(':error-message');

            return [new LiveError(
                message: $headerMessage
                    ?? (is_string($details['message'] ?? null)
                    ? $details['message']
                    : 'Bedrock returned a bidirectional stream error.'),
                code: $eventType !== '' ? $eventType : null,
                details: $details,
            )];
        }

        if ($eventType !== 'chunk') {
            return [new ProviderEvent($eventType !== '' ? $eventType : 'eventstream', [
                'headers' => $message->headers,
                'payload' => $message->payload,
            ])];
        }

        $chunk = $this->decodeObject($message->payload);
        $encodedBytes = $chunk['bytes'] ?? null;
        $bytes = is_string($encodedBytes) ? base64_decode($encodedBytes, true) : false;
        if ($bytes === false) {
            return [new LiveError('Bedrock returned a malformed bidirectional chunk.', 'invalid_chunk', $chunk)];
        }

        $payload = $this->decodeObject($bytes);
        $events = $payload['event'] ?? null;
        if (! is_array($events) || $events === []) {
            return [new ProviderEvent('bedrock.chunk', $payload)];
        }
        $name = (string) array_key_first($events);
        $data = $events[$name] ?? [];
        if (! is_array($data)) {
            return [new ProviderEvent($name, $payload)];
        }

        return $this->mapNovaEvent($name, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<LiveEvent>
     */
    private function mapNovaEvent(string $name, array $data): array
    {
        if ($name === 'contentStart') {
            $contentId = $this->contentId($data);
            $stage = '';
            if (is_string($data['additionalModelFields'] ?? null)) {
                $fields = $this->decodeObject($data['additionalModelFields']);
                $stage = is_string($fields['generationStage'] ?? null) ? $fields['generationStage'] : '';
            }
            $this->content[$contentId] = [
                'role' => is_string($data['role'] ?? null) ? $data['role'] : '',
                'type' => is_string($data['type'] ?? null) ? $data['type'] : '',
                'stage' => $stage,
            ];

            if (($data['type'] ?? null) === 'AUDIO' && ($data['role'] ?? null) === 'ASSISTANT') {
                return [new SpeechStarted()];
            }

            return [new ProviderEvent($name, $data)];
        }

        if ($name === 'audioOutput') {
            $audio = is_string($data['content'] ?? null) ? base64_decode($data['content'], true) : false;

            return $audio === false
                ? [new LiveError('Bedrock returned malformed base64 audio.', 'invalid_audio', $data)]
                : [new AudioDelta($audio)];
        }

        if ($name === 'textOutput') {
            $text = is_string($data['content'] ?? null) ? $data['content'] : '';
            $contentId = $this->contentId($data);
            $context = $this->content[$contentId] ?? ['role' => '', 'type' => '', 'stage' => ''];
            $this->transcripts[$contentId] = ($this->transcripts[$contentId] ?? '') . $text;

            if ($context['role'] === 'USER') {
                return [new TranscriptDelta($text, $contentId, TranscriptSource::Input)];
            }
            if ($context['role'] === 'ASSISTANT' && $context['stage'] === 'FINAL') {
                return [new TranscriptUpdate(
                    $this->transcripts[$contentId],
                    $contentId,
                    TranscriptSource::Output,
                )];
            }

            return [new TextDelta($text)];
        }

        if ($name === 'contentEnd') {
            $contentId = $this->contentId($data);
            $context = $this->content[$contentId] ?? ['role' => '', 'type' => '', 'stage' => ''];
            $mapped = [];
            if (isset($this->pendingTools[$contentId])) {
                $tool = $this->pendingTools[$contentId];
                unset($this->pendingTools[$contentId]);
                $arguments = is_string($tool['content'] ?? null) ? $this->decodeObject($tool['content']) : [];
                $mapped[] = new ToolCallEvent(
                    callId: is_string($tool['toolUseId'] ?? null) ? $tool['toolUseId'] : '',
                    name: is_string($tool['toolName'] ?? null) ? $tool['toolName'] : '',
                    arguments: $arguments,
                );
            }
            if ($context['type'] === 'AUDIO' && $context['role'] === 'ASSISTANT') {
                $mapped[] = new SpeechStopped();
            }
            if (isset($this->transcripts[$contentId]) && ($context['role'] === 'USER' || $context['stage'] === 'FINAL')) {
                $mapped[] = new TranscriptCompleted(
                    $this->transcripts[$contentId],
                    $contentId,
                    $context['role'] === 'USER' ? TranscriptSource::Input : TranscriptSource::Output,
                );
            }
            if (($data['stopReason'] ?? null) === 'INTERRUPTED') {
                $mapped[] = new Interrupted(is_string($data['completionId'] ?? null) ? $data['completionId'] : null);
            }

            return $mapped !== [] ? $mapped : [new ProviderEvent($name, $data)];
        }

        if ($name === 'toolUse') {
            $this->pendingTools[$this->contentId($data)] = $data;

            return [];
        }

        if ($name === 'usageEvent') {
            return [new UsageEvent($this->flattenUsage($data['details']['total'] ?? $data['details']['delta'] ?? []))];
        }

        if ($name === 'completionEnd') {
            $completionId = is_string($data['completionId'] ?? null) ? $data['completionId'] : null;
            $mapped = [];
            if (($data['stopReason'] ?? null) === 'INTERRUPTED') {
                $mapped[] = new Interrupted($completionId);
            }
            $mapped[] = new ResponseCompleted($completionId, $data);

            return $mapped;
        }

        return [new ProviderEvent($name, $data)];
    }

    /**
     * @return array<string, int|float>
     */
    private function flattenUsage(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [];
        }

        $flat = [];
        foreach (['input', 'output'] as $direction) {
            $values = $usage[$direction] ?? null;
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $name => $value) {
                if (is_int($value) || is_float($value)) {
                    $flat[$direction . '_' . self::snake((string) $name)] = $value;
                }
            }
        }

        return $flat;
    }

    /** @param array<string, mixed> $data */
    private function contentId(array $data): string
    {
        foreach (['contentId', 'contentName'] as $key) {
            if (is_string($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function turnDetection(): ?array
    {
        $provider = $this->arrayOption('turnDetectionConfiguration');
        $turn = $this->request->options['turn_detection'] ?? null;

        if (($provider !== null || $turn !== null) && str_contains($this->modelId, 'nova-sonic-v1')) {
            throw new InvalidArgumentException(
                'Bedrock Live turn detection configuration requires Amazon Nova 2 Sonic.',
                ['provider' => BedrockOptions::PROVIDER_NAME, 'modelId' => $this->modelId],
            );
        }

        if ($provider !== null) {
            return $provider;
        }

        if ($turn === null) {
            return null;
        }

        $sensitivity = match (true) {
            is_string($turn) => strtoupper($turn),
            is_array($turn) && is_string($turn['endpointingSensitivity'] ?? null) => strtoupper($turn['endpointingSensitivity']),
            default => '',
        };
        if (in_array($sensitivity, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            return ['endpointingSensitivity' => $sensitivity];
        }

        throw new InvalidArgumentException(
            'Bedrock Live turn detection must be HIGH, MEDIUM, or LOW.',
            ['provider' => BedrockOptions::PROVIDER_NAME, 'turnDetection' => $turn],
        );
    }

    /** @return array<string, mixed>|null */
    private function arrayOption(string $name): ?array
    {
        $value = $this->providerOptions[$name] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function audioConfiguration(string $direction, array $defaults): array
    {
        $format = $this->request->options[$direction . '_audio_format'] ?? null;
        if (is_string($format) && ! in_array(strtolower($format), ['pcm16', 'pcm', 'lpcm', 'audio/lpcm'], true)) {
            throw new InvalidArgumentException(
                'Bedrock Live supports signed 16-bit linear PCM audio only.',
                ['provider' => BedrockOptions::PROVIDER_NAME, 'format' => $format],
            );
        }

        $override = $this->arrayOption($direction . 'AudioConfiguration') ?? [];

        return array_replace($defaults, $override);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->providerOptions[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isNova2(): bool
    {
        return str_contains($this->modelId, 'nova-2-sonic');
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('The Bedrock Live session is closed.');
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
