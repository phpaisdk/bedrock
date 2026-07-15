<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Auth;

use AiSdk\Bedrock\Aws\EventStream;
use DateTimeImmutable;
use DateTimeZone;

final class StreamingSigV4Session
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly array $headers,
        private string $priorSignature,
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $service,
    ) {}

    /** Wrap an already-encoded Smithy event message in its signed AWS frame. */
    public function sign(string $message, ?DateTimeImmutable $date = null): string
    {
        $date = ($date ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $longDate = $date->format('Ymd\THis\Z');
        $shortDate = $date->format('Ymd');
        $scope = "{$shortDate}/{$this->region}/{$this->service}/aws4_request";
        $milliseconds = ((int) $date->format('U')) * 1000 + intdiv((int) $date->format('u'), 1000);
        $dateHeader = chr(5) . ':date' . chr(EventStream::TYPE_TIMESTAMP) . EventStream::uint64Be($milliseconds);

        $stringToSign = "AWS4-HMAC-SHA256-PAYLOAD\n{$longDate}\n{$scope}\n"
            . $this->priorSignature . "\n"
            . hash('sha256', $dateHeader) . "\n"
            . hash('sha256', $message);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($shortDate));
        $this->priorSignature = $signature;

        return EventStream::encodeMessage([
            ':date' => ['type' => EventStream::TYPE_TIMESTAMP, 'value' => $milliseconds],
            ':chunk-signature' => ['type' => EventStream::TYPE_BINARY, 'value' => hex2bin($signature)],
        ], $message);
    }

    private function signingKey(string $shortDate): string
    {
        $dateKey = hash_hmac('sha256', $shortDate, 'AWS4' . $this->secretAccessKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $this->service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }
}
