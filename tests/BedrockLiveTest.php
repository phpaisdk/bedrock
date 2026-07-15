<?php

declare(strict_types=1);

use AiSdk\Bedrock as BedrockFacade;
use AiSdk\Bedrock\Auth\StreamingSigV4Signer;
use AiSdk\Bedrock\Aws\EventStream;
use AiSdk\Bedrock\Aws\EventStreamDecoder;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\Models\BedrockLiveModel;
use AiSdk\Contracts\LiveProviderInterface;
use AiSdk\Exceptions\UnsupportedLiveActionException;
use AiSdk\Live as LiveFacade;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\Contracts\TransportConnectionInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\Interrupted;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Live\LiveSession;
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
use AiSdk\Live\TransportEndpoint;
use AiSdk\Live\TransportFrame;
use AiSdk\Live\UsageEvent;

final class FakeBedrockLiveConnection implements TransportConnectionInterface
{
    /** @var list<TransportFrame> */
    public array $sent = [];

    /** @var list<TransportFrame> */
    public array $incoming = [];

    public bool $finished = false;

    public bool $closed = false;

    public function send(TransportFrame $frame): void
    {
        $this->sent[] = $frame;
    }

    public function receive(): ?TransportFrame
    {
        return array_shift($this->incoming);
    }

    public function finishSending(): void
    {
        $this->finished = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}

final class FakeBedrockLiveTransport implements TransportInterface
{
    public ?TransportEndpoint $endpoint = null;

    public function __construct(public readonly FakeBedrockLiveConnection $connection) {}

    public function supports(TransportEndpoint $endpoint): bool
    {
        return $endpoint instanceof Http2Endpoint;
    }

    public function connect(TransportEndpoint $endpoint): TransportConnectionInterface
    {
        $this->endpoint = $endpoint;

        return $this->connection;
    }
}

/** @return array<string, mixed> */
function decodeBedrockInput(TransportFrame $frame): array
{
    $outer = (new EventStreamDecoder())->push($frame->payload)[0];
    $inner = (new EventStreamDecoder())->push($outer->payload)[0];
    $chunk = json_decode($inner->payload, true, 512, JSON_THROW_ON_ERROR);
    $bytes = base64_decode($chunk['bytes'], true);

    return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
}

function bedrockOutput(array $event): string
{
    return EventStream::encodeChunk(json_encode(['event' => $event], JSON_THROW_ON_ERROR));
}

it('connects through the public Live and Bedrock model APIs', function () {
    BedrockFacade::reset();
    $provider = BedrockFacade::create([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
        'region' => 'us-east-1',
    ]);
    $connection = new FakeBedrockLiveConnection();

    try {
        $session = LiveFacade::voice()
            ->model(BedrockFacade::model('amazon.nova-sonic-v1:0'))
            ->instructions('Be concise.')
            ->voice('matthew')
            ->connect(new FakeBedrockLiveTransport($connection));

        expect($provider)->toBeInstanceOf(LiveProviderInterface::class)
            ->and($session)->toBeInstanceOf(LiveSession::class)
            ->and(decodeBedrockInput($connection->sent[0])['event'])->toHaveKey('sessionStart');

        $session->close();
    } finally {
        BedrockFacade::reset();
    }
});

// Fixture generated with the official @aws-sdk/client-bedrock-runtime and
// @smithy/signature-v4 serializers at a fixed signing time.
it('matches official AWS SDK SigV4 and EventStream bytes', function () {
    $signing = (new StreamingSigV4Signer(
        accessKeyId: 'AKIDEXAMPLE',
        secretAccessKey: 'SECRET',
        sessionToken: 'TOKEN',
        region: 'us-east-1',
    ))->authorize(
        'POST',
        'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-sonic-v1%3A0/invoke-with-bidirectional-stream',
        date: new DateTimeImmutable('2025-01-02T03:04:05Z'),
    );

    expect($signing->headers['Authorization'])->toBe(
        'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20250102/us-east-1/bedrock/aws4_request, '
        . 'SignedHeaders=:authority;content-type;x-amz-content-sha256;x-amz-date;x-amz-security-token, '
        . 'Signature=f8bc2fe45a51f6c7bebafdf7becbfc1a47900ff20ef2ac10dfaa4ae9dcaf4012',
    );

    $inner = EventStream::encodeChunk('{"event":{"sessionStart":{}}}');
    expect(bin2hex($inner))->toBe(
        '0000008f0000004bb79a60320b3a6576656e742d747970650700056368756e6b0d3a6d6573736167652d747970650700056576656e740d3a636f6e74656e742d747970650700106170706c69636174696f6e2f6a736f6e7b226279746573223a2265794a6c646d56756443493665794a7a5a584e7a6157397555335268636e51694f6e74396658303d227d2e4b83b9',
    );

    $outer = $signing->sign($inner, new DateTimeImmutable('2025-01-02T03:04:05Z'));
    expect(bin2hex($outer))->toBe(
        '000000e200000043d8e35bfc053a64617465080000019424f86088103a6368756e6b2d7369676e617475726506002008b474e6f667cd89ca6012ea35a890850a3b372aa1c8bfc9ccc55102601e54210000008f0000004bb79a60320b3a6576656e742d747970650700056368756e6b0d3a6d6573736167652d747970650700056576656e740d3a636f6e74656e742d747970650700106170706c69636174696f6e2f6a736f6e7b226279746573223a2265794a6c646d56756443493665794a7a5a584e7a6157397555335268636e51694f6e74396658303d227d2e4b83b915a3ac90',
    );
});

it('starts a Nova Sonic session and maps output events', function () {
    $connection = new FakeBedrockLiveConnection();
    $transport = new FakeBedrockLiveTransport($connection);
    $request = new LiveRequest(
        operation: LiveOperation::Voice,
        options: ['instructions' => 'Be concise.', 'voice' => 'matthew'],
        providerOptions: [BedrockOptions::PROVIDER_NAME => [
            'promptName' => 'prompt-1',
            'audioContentName' => 'audio-1',
        ]],
        tools: [],
    );
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
        'region' => 'us-east-1',
    ]));

    $session = $model->createLiveSession($request, $transport);

    expect($transport->endpoint)->toBeInstanceOf(Http2Endpoint::class)
        ->and($transport->endpoint->headers['X-Amz-Content-Sha256'])->toBe('STREAMING-AWS4-HMAC-SHA256-EVENTS')
        ->and(decodeBedrockInput($connection->sent[0])['event'])->toHaveKey('sessionStart')
        ->and(decodeBedrockInput($connection->sent[1])['event']['promptStart']['promptName'])->toBe('prompt-1');

    $session->sendAudio("\x01\x02");
    expect(decodeBedrockInput($connection->sent[array_key_last($connection->sent)]))
        ->toMatchArray(['event' => ['audioInput' => [
            'promptName' => 'prompt-1',
            'contentName' => 'audio-1',
            'content' => 'AQI=',
        ]]]);

    $connection->incoming[] = TransportFrame::binary(
        bedrockOutput(['contentStart' => [
            'contentId' => 'user-text',
            'type' => 'TEXT',
            'role' => 'USER',
            'additionalModelFields' => '{"generationStage":"FINAL"}',
        ]])
        . bedrockOutput(['textOutput' => ['contentId' => 'user-text', 'content' => 'hello']])
        . bedrockOutput(['contentEnd' => [
            'contentId' => 'user-text',
            'type' => 'TEXT',
            'stopReason' => 'END_TURN',
        ]])
        . bedrockOutput(['contentStart' => [
            'contentId' => 'assistant-text',
            'type' => 'TEXT',
            'role' => 'ASSISTANT',
            'additionalModelFields' => '{"generationStage":"SPECULATIVE"}',
        ]])
        . bedrockOutput(['textOutput' => ['contentId' => 'assistant-text', 'content' => 'Hi']])
        . bedrockOutput(['contentStart' => [
            'contentId' => 'assistant-final',
            'type' => 'TEXT',
            'role' => 'ASSISTANT',
            'additionalModelFields' => '{"generationStage":"FINAL"}',
        ]])
        . bedrockOutput(['textOutput' => ['contentId' => 'assistant-final', 'content' => 'Hello']])
        . bedrockOutput(['textOutput' => ['contentId' => 'assistant-final', 'content' => ' there']])
        . bedrockOutput(['contentEnd' => [
            'contentId' => 'assistant-final',
            'type' => 'TEXT',
            'stopReason' => 'END_TURN',
        ]])
        . bedrockOutput(['contentStart' => [
            'contentId' => 'audio-out',
            'type' => 'AUDIO',
            'role' => 'ASSISTANT',
        ]])
        . bedrockOutput(['audioOutput' => ['contentId' => 'audio-out', 'content' => 'AQI=']])
        . bedrockOutput(['contentEnd' => [
            'contentId' => 'audio-out',
            'type' => 'AUDIO',
            'stopReason' => 'INTERRUPTED',
            'completionId' => 'completion-1',
        ]])
        . bedrockOutput(['contentStart' => [
            'contentId' => 'tool-content',
            'type' => 'TOOL',
            'role' => 'TOOL',
        ]])
        . bedrockOutput(['toolUse' => [
            'contentId' => 'tool-content',
            'toolUseId' => 'tool-1',
            'toolName' => 'weather',
            'content' => '{"city":"Lahore"}',
        ]])
        . bedrockOutput(['contentEnd' => [
            'contentId' => 'tool-content',
            'type' => 'TOOL',
        ]])
        . bedrockOutput(['usageEvent' => ['details' => ['total' => [
            'input' => ['speechTokens' => 10, 'textTokens' => 2],
            'output' => ['speechTokens' => 4, 'textTokens' => 3],
        ]]]])
        . bedrockOutput(['futureOutputEvent' => ['value' => true]])
        . bedrockOutput(['completionEnd' => ['completionId' => 'completion-1', 'stopReason' => 'END_TURN']]),
    );

    $events = iterator_to_array($session->events());
    expect($events)->toContainOnlyInstancesOf(\AiSdk\Live\LiveEvent::class)
        ->and(array_values(array_filter($events, fn($event) => $event instanceof TranscriptDelta))[0])->toMatchObject([
            'delta' => 'hello',
            'itemId' => 'user-text',
            'source' => TranscriptSource::Input,
        ])
        ->and(array_values(array_filter($events, fn($event) => $event instanceof TextDelta))[0]->delta)->toBe('Hi')
        ->and(array_values(array_filter($events, fn($event) => $event instanceof TranscriptUpdate))[1])->toMatchObject([
            'text' => 'Hello there',
            'itemId' => 'assistant-final',
            'source' => TranscriptSource::Output,
        ])
        ->and(array_values(array_filter($events, fn($event) => $event instanceof TranscriptCompleted))[0])->toMatchObject([
            'text' => 'hello',
            'itemId' => 'user-text',
            'source' => TranscriptSource::Input,
        ])
        ->and(array_values(array_filter($events, fn($event) => $event instanceof TranscriptCompleted))[1])->toMatchObject([
            'text' => 'Hello there',
            'itemId' => 'assistant-final',
            'source' => TranscriptSource::Output,
        ])
        ->and(array_values(array_filter($events, fn($event) => $event instanceof AudioDelta))[0]->bytes)->toBe("\x01\x02")
        ->and(array_values(array_filter($events, fn($event) => $event instanceof SpeechStarted)))->toHaveCount(1)
        ->and(array_values(array_filter($events, fn($event) => $event instanceof SpeechStopped)))->toHaveCount(1)
        ->and(array_values(array_filter($events, fn($event) => $event instanceof Interrupted))[0]->responseId)->toBe('completion-1')
        ->and(array_values(array_filter($events, fn($event) => $event instanceof ToolCallEvent))[0]->arguments)->toBe(['city' => 'Lahore'])
        ->and(array_values(array_filter($events, fn($event) => $event instanceof UsageEvent))[0]->usage['input_speech_tokens'])->toBe(10)
        ->and(array_values(array_filter($events, fn($event) => $event instanceof ProviderEvent && $event->providerType === 'futureOutputEvent')))->toHaveCount(1)
        ->and(array_values(array_filter($events, fn($event) => $event instanceof ResponseCompleted))[0]->responseId)->toBe('completion-1');
});

it('normalizes Bedrock EventStream exceptions', function () {
    $connection = new FakeBedrockLiveConnection();
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
    ]));
    $session = $model->createLiveSession(
        new LiveRequest(LiveOperation::Voice, [], [], []),
        new FakeBedrockLiveTransport($connection),
    );
    $connection->incoming[] = TransportFrame::binary(EventStream::encodeMessage([
        ':message-type' => ['type' => EventStream::TYPE_STRING, 'value' => 'exception'],
        ':exception-type' => ['type' => EventStream::TYPE_STRING, 'value' => 'validationException'],
        ':content-type' => ['type' => EventStream::TYPE_STRING, 'value' => 'application/json'],
    ], '{"message":"Invalid audio configuration."}'));

    $events = iterator_to_array($session->events());
    $error = array_values(array_filter($events, fn($event) => $event instanceof \AiSdk\Live\LiveError))[0];

    expect($error->code)->toBe('validationException')
        ->and($error->message)->toBe('Invalid audio configuration.');
});

it('sends complete Nova tool result and close lifecycles', function () {
    $connection = new FakeBedrockLiveConnection();
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
    ]));
    $session = $model->createLiveSession(
        new LiveRequest(
            operation: LiveOperation::Voice,
            options: [],
            providerOptions: [BedrockOptions::PROVIDER_NAME => [
                'promptName' => 'prompt-1',
                'audioContentName' => 'audio-1',
            ]],
            tools: [],
        ),
        new FakeBedrockLiveTransport($connection),
    );

    $session->sendToolResult('tool-1', ['temperature' => 32]);
    $toolFrames = array_slice($connection->sent, -3);
    expect(decodeBedrockInput($toolFrames[0])['event']['contentStart']['toolResultInputConfiguration']['toolUseId'])->toBe('tool-1')
        ->and(decodeBedrockInput($toolFrames[1])['event']['toolResult']['content'])->toBe('{"temperature":32}')
        ->and(decodeBedrockInput($toolFrames[2])['event'])->toHaveKey('contentEnd');

    $session->close();
    $closingFrames = array_slice($connection->sent, -3);
    $lastRequestEvent = decodeBedrockInput($connection->sent[array_key_last($connection->sent)])['event'];

    expect(decodeBedrockInput($closingFrames[0])['event']['contentEnd']['contentName'])->toBe('audio-1')
        ->and(decodeBedrockInput($closingFrames[1])['event']['promptEnd']['promptName'])->toBe('prompt-1')
        ->and(decodeBedrockInput($closingFrames[2])['event'])->toHaveKey('sessionEnd')
        ->and($lastRequestEvent)->toHaveKey('sessionEnd')
        ->and($connection->finished)->toBeTrue();
});

it('uses each Nova generation tool schema and cross-modal format', function () {
    $tool = \AiSdk\Tool::make('weather', 'Get weather.')
        ->input(\AiSdk\Schema::string('city')->required())
        ->run(fn(string $city): array => ['city' => $city]);

    foreach ([
        'amazon.nova-sonic-v1:0' => true,
        'amazon.nova-2-sonic-v1:0' => false,
    ] as $modelId => $stringSchema) {
        $connection = new FakeBedrockLiveConnection();
        $model = new BedrockLiveModel($modelId, BedrockOptions::fromArray([
            'accessKeyId' => 'AKIDEXAMPLE',
            'secretAccessKey' => 'secret',
        ]));
        $session = $model->createLiveSession(
            new LiveRequest(LiveOperation::Voice, [], [], [$tool]),
            new FakeBedrockLiveTransport($connection),
        );
        $promptStart = decodeBedrockInput($connection->sent[1])['event']['promptStart'];
        $schema = $promptStart['toolConfiguration']['tools'][0]['toolSpec']['inputSchema']['json'];

        expect(is_string($schema))->toBe($stringSchema);

        if ($stringSchema) {
            expect(fn() => $session->sendText('Hello'))
                ->toThrow(UnsupportedLiveActionException::class, 'sendText');
        } else {
            $session->sendText('Hello');
            expect(decodeBedrockInput($connection->sent[array_key_last($connection->sent)])['event'])
                ->toHaveKey('contentEnd');
        }
    }
});

it('preserves unmodeled EventStream error headers', function () {
    $connection = new FakeBedrockLiveConnection();
    $model = new BedrockLiveModel('amazon.nova-2-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
    ]));
    $session = $model->createLiveSession(
        new LiveRequest(LiveOperation::Voice, [], [], []),
        new FakeBedrockLiveTransport($connection),
    );
    $connection->incoming[] = TransportFrame::binary(EventStream::encodeMessage([
        ':message-type' => ['type' => EventStream::TYPE_STRING, 'value' => 'error'],
        ':error-code' => ['type' => EventStream::TYPE_STRING, 'value' => 'InternalError'],
        ':error-message' => ['type' => EventStream::TYPE_STRING, 'value' => 'Stream failed.'],
    ]));

    $error = array_values(array_filter(
        iterator_to_array($session->events()),
        fn($event) => $event instanceof \AiSdk\Live\LiveError,
    ))[0];

    expect($error->code)->toBe('InternalError')
        ->and($error->message)->toBe('Stream failed.');
});

it('rejects actions that Nova Sonic does not expose', function () {
    $connection = new FakeBedrockLiveConnection();
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
    ]));
    $session = $model->createLiveSession(
        new LiveRequest(LiveOperation::Voice, [], [], []),
        new FakeBedrockLiveTransport($connection),
    );

    $session->cancelResponse();
})->throws(UnsupportedLiveActionException::class, 'cancelResponse');

it('validates Nova Sonic turn detection sensitivity', function () {
    $model = new BedrockLiveModel('amazon.nova-2-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
    ]));

    $model->createLiveSession(
        new LiveRequest(LiveOperation::Voice, ['turn_detection' => 'semantic_vad'], [], []),
        new FakeBedrockLiveTransport(new FakeBedrockLiveConnection()),
    );
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'HIGH, MEDIUM, or LOW');

it('does not send Nova 2 turn detection fields to Nova Sonic v1', function () {
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([
        'accessKeyId' => 'AKIDEXAMPLE',
        'secretAccessKey' => 'secret',
    ]));

    $model->createLiveSession(
        new LiveRequest(LiveOperation::Voice, ['turn_detection' => 'medium'], [], []),
        new FakeBedrockLiveTransport(new FakeBedrockLiveConnection()),
    );
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'requires Amazon Nova 2 Sonic');

it('rejects bearer API keys for bidirectional streaming', function () {
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([
        'apiKey' => 'bedrock-api-key',
    ]));

    $model->createLiveSession(
        new LiveRequest(LiveOperation::Voice, [], [], []),
        new FakeBedrockLiveTransport(new FakeBedrockLiveConnection()),
    );
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'requires standard AWS credentials');

it('does not advertise a dedicated Bedrock transcription session', function () {
    $model = new BedrockLiveModel('amazon.nova-sonic-v1:0', BedrockOptions::fromArray([]));

    $model->createLiveSession(
        new LiveRequest(LiveOperation::Transcribe, [], [], []),
        new FakeBedrockLiveTransport(new FakeBedrockLiveConnection()),
    );
})->throws(UnsupportedLiveActionException::class, 'transcribe');

it('incrementally decodes EventStream messages and validates CRCs', function () {
    $frame = EventStream::encodeEvent('chunk', '{"ok":true}');
    $decoder = new EventStreamDecoder();

    expect($decoder->push(substr($frame, 0, 7)))->toBe([]);
    $messages = $decoder->push(substr($frame, 7));
    $decoder->finish();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->stringHeader(':event-type'))->toBe('chunk')
        ->and($messages[0]->payload)->toBe('{"ok":true}');

    $broken = $frame;
    $broken[strlen($broken) - 1] = chr(ord($broken[strlen($broken) - 1]) ^ 0xFF);
    (new EventStreamDecoder())->push($broken);
})->throws(\AiSdk\Exceptions\InvalidResponseException::class, 'invalid message checksum');

it('round trips all EventStream header types used by Smithy', function () {
    $frame = EventStream::encodeMessage([
        'true' => ['type' => EventStream::TYPE_BOOLEAN_TRUE, 'value' => true],
        'false' => ['type' => EventStream::TYPE_BOOLEAN_FALSE, 'value' => false],
        'byte' => ['type' => EventStream::TYPE_BYTE, 'value' => -2],
        'short' => ['type' => EventStream::TYPE_SHORT, 'value' => -300],
        'integer' => ['type' => EventStream::TYPE_INTEGER, 'value' => -70000],
        'long' => ['type' => EventStream::TYPE_LONG, 'value' => -5_000_000_000],
        'binary' => ['type' => EventStream::TYPE_BINARY, 'value' => "\x00\xFF"],
        'string' => ['type' => EventStream::TYPE_STRING, 'value' => 'value'],
        'timestamp' => ['type' => EventStream::TYPE_TIMESTAMP, 'value' => 1_735_786_645_000],
        'negative-timestamp' => ['type' => EventStream::TYPE_TIMESTAMP, 'value' => -1_000],
        'uuid' => ['type' => EventStream::TYPE_UUID, 'value' => '12345678-1234-4321-9234-123456789abc'],
    ], 'payload');

    $message = (new EventStreamDecoder())->push($frame)[0];

    expect($message->headers)->toMatchArray([
        'true' => true,
        'false' => false,
        'byte' => -2,
        'short' => -300,
        'integer' => -70000,
        'long' => -5_000_000_000,
        'binary' => "\x00\xFF",
        'string' => 'value',
        'timestamp' => 1_735_786_645_000,
        'negative-timestamp' => -1_000,
        'uuid' => '12345678-1234-4321-9234-123456789abc',
    ])->and($message->payload)->toBe('payload');
});
