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

it('generates images with Amazon Nova Canvas through InvokeModel', function () {
    $client = new FakeHttpClient(200, json_encode(['images' => [base64_encode('image-bytes')]]));
    $factory = new Psr17Factory();
    Generate::configure(new Sdk(httpClient: $client, requestFactory: $factory, streamFactory: $factory));
    Bedrock::create(['apiKey' => 'bedrock-token']);

    $result = Generate::image('A product photograph')
        ->model(Bedrock::image('amazon.nova-canvas-v1:0'))
        ->aspectRatio('16:9')
        ->seed(42)
        ->run();

    expect($result->output->bytes())->toBe('image-bytes')
        ->and($client->lastRequest?->getUri()->getPath())->toBe('/model/amazon.nova-canvas-v1%3A0/invoke')
        ->and($client->sentBody()['taskType'])->toBe('TEXT_IMAGE')
        ->and($client->sentBody()['imageGenerationConfig'])->toMatchArray([
            'width' => 1280,
            'height' => 768,
            'seed' => 42,
        ]);
});
