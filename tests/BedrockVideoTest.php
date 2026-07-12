<?php

declare(strict_types=1);
use AiSdk\Bedrock\BedrockApi;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\Models\BedrockVideoModel;
use AiSdk\Bedrock\Tests\Fakes\FakeHttpClient;
use AiSdk\Requests\VideoRequest;
use AiSdk\Support\Sdk;
use Nyholm\Psr7\Factory\Psr17Factory;

it('starts Nova Reel async video jobs', function () {
    $c = new FakeHttpClient(200, json_encode(['invocationArn' => 'arn:aws:bedrock:job/1']));
    $f = new Psr17Factory();
    $o = new BedrockOptions('token', null, null, null, null, 'us-east-1', 'https://bedrock-runtime.us-east-1.amazonaws.com', BedrockApi::Converse, sdk: new Sdk($c, $f, $f));
    $m = new BedrockVideoModel('amazon.nova-reel-v1:1', $o);
    $j = $m->generate(new VideoRequest('Forest', providerOptions: ['amazon-bedrock' => ['outputS3Uri' => 's3://bucket/videos']]));
    expect($j->id)->toBe('arn:aws:bedrock:job/1')->and($c->lastRequest?->getUri()->getPath())->toBe('/async-invoke')->and($c->sentBody())->toMatchArray(['modelId' => 'amazon.nova-reel-v1:1', 'outputDataConfig' => ['s3OutputDataConfig' => ['s3Uri' => 's3://bucket/videos']]]);
});
