<?php

declare(strict_types=1);

use AiSdk\Bedrock;
use AiSdk\Bedrock\Tests\Fakes\FakeHttpClient;
use AiSdk\Generate;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

afterEach(function () {
    Generate::reset();
    Bedrock::reset();
});

function configureBedrockEmbeddingsWith(FakeHttpClient $client): void
{
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(
        httpClient: $client,
        requestFactory: $factory,
        streamFactory: $factory,
    ));
}

it('generates Cohere Embed v4 embeddings through signed InvokeModel requests', function () {
    $client = new FakeHttpClient(200, json_encode([
        'id' => 'cohere-1',
        'response_type' => 'embeddings_floats',
        'embeddings' => [[0.1, 0.2], [0.3, 0.4]],
        'texts' => ['Document one', 'Document two'],
    ]));
    configureBedrockEmbeddingsWith($client);
    Bedrock::create([
        'accessKeyId' => 'AKIA-test',
        'secretAccessKey' => 'secret',
        'region' => 'us-east-1',
    ]);

    $result = Generate::embedding(['Document one', 'Document two'])
        ->model(Bedrock::embedding('cohere.embed-v4:0'))
        ->dimensions(512)
        ->providerOptions('amazon-bedrock', [
            'input_type' => 'search_document',
            'truncate' => 'RIGHT',
        ])
        ->run();

    expect($result->embeddings)->toHaveCount(2)
        ->and($result->embeddings[0]->vector)->toBe([0.1, 0.2])
        ->and($result->embeddings[1]->index)->toBe(1)
        ->and($result->providerMetadata['amazon-bedrock'])->toMatchArray([
            'id' => 'cohere-1',
            'model' => 'cohere.embed-v4:0',
            'response_type' => 'embeddings_floats',
        ])
        ->and($client->lastRequest?->getUri()->getPath())->toBe('/model/cohere.embed-v4%3A0/invoke')
        ->and($client->lastRequest?->getHeaderLine('Authorization'))->toContain('AWS4-HMAC-SHA256')
        ->and($client->sentBody())->toMatchArray([
            'texts' => ['Document one', 'Document two'],
            'input_type' => 'search_document',
            'embedding_types' => ['float'],
            'output_dimension' => 512,
            'truncate' => 'RIGHT',
        ]);
});

it('uses the Cohere Embed v3 wire format without v4 dimensions', function () {
    $client = new FakeHttpClient(200, json_encode([
        'embeddings' => [[0.1, -0.2]],
        'response_type' => 'embeddings_floats',
    ]));
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    $result = Generate::embedding('A query')
        ->model(Bedrock::embedding('cohere.embed-english-v3'))
        ->providerOptions('amazon-bedrock', ['input_type' => 'search_query'])
        ->run();

    expect($result->output->vector)->toBe([0.1, -0.2])
        ->and($client->sentBody())->toMatchArray([
            'texts' => ['A query'],
            'input_type' => 'search_query',
            'embedding_types' => ['float'],
        ])->not->toHaveKey('output_dimension');
});

it('requires the documented Cohere input type before sending a request', function () {
    $client = new FakeHttpClient(200, '{}');
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::embedding('A document')
        ->model(Bedrock::embedding('cohere.embed-v4:0'))
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'requires input_type');

it('rejects configurable dimensions for Cohere Embed v3', function () {
    $client = new FakeHttpClient(200, '{}');
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::embedding('A document')
        ->model(Bedrock::embedding('cohere.embed-multilingual-v3'))
        ->dimensions(512)
        ->providerOptions('amazon-bedrock', ['input_type' => 'search_document'])
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'does not support configurable dimensions');

it('rejects incomplete Cohere embedding batches', function () {
    $client = new FakeHttpClient(200, json_encode([
        'embeddings' => [[0.1, -0.2]],
        'response_type' => 'embeddings_floats',
    ]));
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::embedding(['First document', 'Second document'])
        ->model(Bedrock::embedding('cohere.embed-v4:0'))
        ->providerOptions('amazon-bedrock', ['input_type' => 'search_document'])
        ->run();
})->throws(\AiSdk\Exceptions\InvalidResponseException::class, 'unexpected number');

it('fans out Titan V2 inputs and aggregates token usage', function () {
    $client = new FakeHttpClient(200, json_encode([
        'embedding' => [0.25, 0.75],
        'inputTextTokenCount' => 3,
        'embeddingsByType' => ['float' => [0.25, 0.75]],
    ]));
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token', 'api' => 'mantle_responses', 'region' => 'eu-west-1']);

    $result = Generate::embedding(['First document', 'Second document'])
        ->model(Bedrock::embedding('amazon.titan-embed-text-v2:0'))
        ->dimensions(256)
        ->providerOptions('amazon-bedrock', ['normalize' => false])
        ->run();

    expect($result->embeddings)->toHaveCount(2)
        ->and($result->embeddings[1]->index)->toBe(1)
        ->and($result->usage->inputTokens)->toBe(6)
        ->and($client->requests)->toHaveCount(2)
        ->and($client->lastRequest?->getUri()->getHost())->toBe('bedrock-runtime.eu-west-1.amazonaws.com')
        ->and($client->lastRequest?->getUri()->getPath())->toBe('/model/amazon.titan-embed-text-v2%3A0/invoke')
        ->and($client->sentBody())->toBe([
            'inputText' => 'Second document',
            'dimensions' => 256,
            'embeddingTypes' => ['float'],
            'normalize' => false,
        ]);
});

it('uses the Titan V1 inputText-only request format', function () {
    $client = new FakeHttpClient(200, json_encode([
        'embedding' => [0.5, -0.5],
        'inputTextTokenCount' => 2,
    ]));
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    $result = Generate::embedding('Legacy document')
        ->model(Bedrock::embedding('amazon.titan-embed-text-v1'))
        ->run();

    expect($result->output->vector)->toBe([0.5, -0.5])
        ->and($result->usage->inputTokens)->toBe(2)
        ->and($client->sentBody())->toBe(['inputText' => 'Legacy document']);
});

it('rejects unknown Bedrock embedding wire families before sending a request', function () {
    $client = new FakeHttpClient(200, '{}');
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::embedding('A document')
        ->model(Bedrock::embedding('vendor.future-embedding-v1:0'))
        ->run();
})->throws(\AiSdk\Exceptions\InvalidArgumentException::class, 'unsupported wire format');

it('rejects Titan responses without a float embedding', function () {
    $client = new FakeHttpClient(200, json_encode([
        'embeddingsByType' => ['binary' => [1, 0]],
        'inputTextTokenCount' => 2,
    ]));
    configureBedrockEmbeddingsWith($client);
    Bedrock::create(['apiKey' => 'bedrock-token']);

    Generate::embedding('A document')
        ->model(Bedrock::embedding('amazon.titan-embed-text-v2:0'))
        ->run();
})->throws(\AiSdk\Exceptions\InvalidResponseException::class, 'no valid float embedding');
