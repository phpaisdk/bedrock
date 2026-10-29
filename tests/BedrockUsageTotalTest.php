<?php

declare(strict_types=1);

use AiSdk\Bedrock\Converters\ConvertsUsage;

it('never reports a total below the sum of input, cache and output tokens', function () {
    $usage = ConvertsUsage::fromBedrock(['inputTokens' => 10, 'outputTokens' => 5, 'totalTokens' => 15, 'cacheReadInputTokens' => 100, 'cacheWriteInputTokens' => 20]);

    expect($usage->inputTokens)->toBe(130)->and($usage->cachedInputTokens)->toBe(100)->and($usage->cacheWriteInputTokens)->toBe(20)->and($usage->totalTokens)->toBe(135);
});

it('keeps the provider total when it already covers every part', function () {
    expect(ConvertsUsage::fromBedrock(['inputTokens' => 10, 'outputTokens' => 5, 'totalTokens' => 200])->totalTokens)->toBe(200);
});
