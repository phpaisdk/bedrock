<?php

declare(strict_types=1);

use AiSdk\Bedrock;
use AiSdk\Bedrock\Aws\EventStream;
use AiSdk\Bedrock\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Reasoning;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

afterEach(function () {
    Generate::reset();
    Bedrock::reset();
});

function configureBedrockWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates text through the Bedrock Converse API with a bearer token', function () {
    $client = new FakeHttpClient(200, json_encode([
        'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Hello from Bedrock']]]],
        'stopReason' => 'end_turn',
        'usage' => ['inputTokens' => 9, 'outputTokens' => 4],
    ]));
    configureBedrockWith($client);

    Bedrock::create(['apiKey' => 'bedrock-token', 'region' => 'us-east-1', 'api' => 'converse']);

    $result = Generate::text('Hi')->model(Bedrock::model('anthropic.claude-3-5-sonnet-20240620-v1:0'))->run();

    expect($result->text)->toBe('Hello from Bedrock')
        ->and($result->usage->inputTokens)->toBe(9)
        ->and($result->usage->outputTokens)->toBe(4);

    $body = $client->sentBody();
    expect($body['messages'][0]['role'])->toBe('user')
        ->and($body['inferenceConfig']['maxTokens'])->toBeInt();

    expect($client->lastRequest->getUri()->getPath())
        ->toBe('/model/anthropic.claude-3-5-sonnet-20240620-v1%3A0/converse')
        ->and($client->lastRequest->getHeaderLine('Authorization'))->toBe('Bearer bedrock-token');
});

it('signs the request with SigV4 when using access keys', function () {
    $client = new FakeHttpClient(200, json_encode([
        'output' => ['message' => ['content' => [['text' => 'ok']]]],
        'stopReason' => 'end_turn',
        'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
    ]));
    configureBedrockWith($client);

    Bedrock::create([
        'accessKeyId' => 'AKIA-test',
        'secretAccessKey' => 'secret',
        'region' => 'us-east-1',
    ]);

    Generate::text('Hi')->model(Bedrock::model('anthropic.claude-3-haiku-20240307-v1:0'))->run();

    $auth = $client->lastRequest->getHeaderLine('Authorization');
    expect($auth)->toContain('AWS4-HMAC-SHA256')
        ->and($auth)->toContain('Credential=AKIA-test/')
        ->and($client->lastRequest->getHeaderLine('X-Amz-Date'))->not->toBe('');
});

it('streams text and finish through the Converse stream API', function () {
    $frames = EventStream::encodeEvent('contentBlockDelta', json_encode(['delta' => ['text' => 'Hel']]))
        . EventStream::encodeEvent('contentBlockDelta', json_encode(['delta' => ['text' => 'lo']]))
        . EventStream::encodeEvent('messageStop', json_encode(['stopReason' => 'end_turn']))
        . EventStream::encodeEvent('metadata', json_encode(['usage' => ['inputTokens' => 3, 'outputTokens' => 2]]));

    $client = new FakeHttpClient(200, $frames, 'application/vnd.amazon.eventstream');
    configureBedrockWith($client);

    Bedrock::create(['apiKey' => 'bedrock-token', 'api' => 'converse']);

    $text = '';
    foreach (Generate::text('Hi')->model(Bedrock::model('anthropic.claude-3-haiku-20240307-v1:0'))->stream()->chunks() as $chunk) {
        $text .= $chunk;
    }

    expect($text)->toBe('Hello')
        ->and($client->lastRequest->getUri()->getPath())
        ->toBe('/model/anthropic.claude-3-haiku-20240307-v1%3A0/converse-stream');
});

it('accepts opaque Bedrock model ids', function () {
    Bedrock::create(['apiKey' => 'bedrock-token', 'api' => 'converse']);

    expect(Bedrock::model('vendor.future-model-v1:0')->modelId())->toBe('vendor.future-model-v1:0');
});

it('maps Bedrock reasoning without incompatible sampling fields', function () {
    $client = new FakeHttpClient(200, json_encode([
        'output' => ['message' => ['content' => [['text' => 'ok']]]],
        'stopReason' => 'end_turn',
    ]));
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token', 'api' => 'converse']);

    Generate::text('Think')
        ->model(Bedrock::model('anthropic.claude-sonnet-4-20250514-v1:0'))
        ->reasoning(Reasoning::effort('high'))
        ->run();

    $body = $client->sentBody();
    expect($body['additionalModelRequestFields'])->toBe([
        'thinking' => ['type' => 'adaptive'],
        'output_config' => ['effort' => 'high'],
    ])->and($body['inferenceConfig'])->not->toHaveKeys(['temperature', 'topP']);
});

it('rejects incomplete Bedrock event-stream frames', function () {
    iterator_to_array(EventStream::decodeStreamChunks(substr(EventStream::encodeEvent('messageStop', '{}'), 0, -1)));
})->throws(\AiSdk\Exceptions\InvalidResponseException::class);

it('uses native Invoke by default for Anthropic models', function () {
    $client = new FakeHttpClient(200, json_encode([
        'content' => [['type' => 'text', 'text' => 'Native Claude']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 2, 'output_tokens' => 3],
    ]));
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    $result = Generate::text('Hi')
        ->model(Bedrock::model('us.anthropic.claude-sonnet-4-v1:0'))
        ->run();

    expect($result->text)->toBe('Native Claude')
        ->and($client->lastRequest->getUri()->getPath())
        ->toBe('/model/us.anthropic.claude-sonnet-4-v1%3A0/invoke')
        ->and($client->sentBody())->toHaveKeys(['anthropic_version', 'messages', 'max_tokens'])
        ->not->toHaveKey('model');
});

it('allows a per-request Converse override for Anthropic models', function () {
    $client = new FakeHttpClient(200, json_encode([
        'output' => ['message' => ['content' => [['text' => 'Converse']]]],
        'stopReason' => 'end_turn',
    ]));
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::text('Hi')
        ->model(Bedrock::model('anthropic.claude-sonnet-4-v1:0'))
        ->providerOptions('amazon-bedrock', ['api' => 'converse'])
        ->run();

    expect($client->lastRequest->getUri()->getPath())
        ->toBe('/model/anthropic.claude-sonnet-4-v1%3A0/converse');
});

it('streams native Anthropic events through InvokeModelWithResponseStream', function () {
    $event = static fn(array $payload): string => EventStream::encodeEvent('chunk', json_encode([
        'bytes' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
    ], JSON_THROW_ON_ERROR));
    $frames = $event(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']])
        . $event(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);

    $client = new FakeHttpClient(200, $frames, 'application/vnd.amazon.eventstream');
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    $text = '';
    foreach (Generate::text('Hi')->model(Bedrock::model('anthropic.claude-sonnet-4-v1:0'))->stream()->chunks() as $chunk) {
        $text .= $chunk;
    }

    expect($text)->toBe('Hello')
        ->and($client->lastRequest->getUri()->getPath())
        ->toBe('/model/anthropic.claude-sonnet-4-v1%3A0/invoke-with-response-stream');
});

it('uses the Responses wire format and automatically selects the Mantle host', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'resp_1',
        'status' => 'completed',
        'model' => 'openai.gpt-oss-120b-1:0',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Mantle']]]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
    ]));
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token', 'region' => 'eu-west-1']);

    $result = Generate::text('Hi')
        ->model(Bedrock::model('openai.gpt-oss-120b-1:0'))
        ->providerOptions('amazon-bedrock', ['api' => 'mantle_responses'])
        ->run();

    expect($result->text)->toBe('Mantle')
        ->and($client->lastRequest->getUri()->getHost())->toBe('bedrock-mantle.eu-west-1.api.aws')
        ->and($client->lastRequest->getUri()->getPath())->toBe('/v1/responses')
        ->and($client->sentBody())->toHaveKey('input')->not->toHaveKey('messages');
});

it('rejects invalid API surface overrides', function () {
    $client = new FakeHttpClient(200, '{}');
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::text('Hi')
        ->model(Bedrock::model('anthropic.claude-sonnet-4-v1:0'))
        ->providerOptions('amazon-bedrock', ['api' => 'invalid'])
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class);

it('normalizes PSR transport failures', function () {
    $factory = new Psr17Factory();
    $request = $factory->createRequest('POST', 'https://example.com');
    $exception = new class ('DNS failure', $request) extends RuntimeException implements NetworkExceptionInterface {
        public function __construct(string $message, private readonly RequestInterface $request)
        {
            parent::__construct($message);
        }

        public function getRequest(): RequestInterface
        {
            return $this->request;
        }
    };
    $client = new FakeHttpClient(0, '', 'application/json', $exception);
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::text('Hi')->model(Bedrock::model('anthropic.claude-sonnet-4-v1:0'))->run();
})->throws(\AiSdk\Exceptions\APIConnectionException::class, 'Bedrock transport error: DNS failure');
