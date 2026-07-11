<?php

declare(strict_types=1);

use AiSdk\Bedrock;
use AiSdk\Bedrock\Aws\EventStream;
use AiSdk\Bedrock\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Reasoning;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

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

    Bedrock::create(['apiKey' => 'bedrock-token', 'region' => 'us-east-1']);

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

    Bedrock::create(['apiKey' => 'bedrock-token']);

    $text = '';
    foreach (Generate::text('Hi')->model(Bedrock::model('anthropic.claude-3-haiku-20240307-v1:0'))->stream()->chunks() as $chunk) {
        $text .= $chunk;
    }

    expect($text)->toBe('Hello')
        ->and($client->lastRequest->getUri()->getPath())
        ->toBe('/model/anthropic.claude-3-haiku-20240307-v1%3A0/converse-stream');
});

it('accepts opaque Bedrock model ids', function () {
    Bedrock::create(['apiKey' => 'bedrock-token']);

    expect(Bedrock::model('vendor.future-model-v1:0')->modelId())->toBe('vendor.future-model-v1:0');
});

it('maps Bedrock reasoning without incompatible sampling fields', function () {
    $client = new FakeHttpClient(200, json_encode([
        'output' => ['message' => ['content' => [['text' => 'ok']]]],
        'stopReason' => 'end_turn',
    ]));
    configureBedrockWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

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
