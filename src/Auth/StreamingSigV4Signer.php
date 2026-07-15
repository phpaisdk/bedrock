<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Auth;

use AiSdk\Bedrock\Aws\EventStream;
use DateTimeImmutable;
use DateTimeZone;

/** SigV4 request and chained EventStream message signing for AWS HTTP/2. */
final class StreamingSigV4Signer
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    private const string PAYLOAD_HASH = 'STREAMING-AWS4-HMAC-SHA256-EVENTS';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly ?string $sessionToken,
        private readonly string $region,
        private readonly string $service = 'bedrock',
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function authorize(
        string $method,
        string $url,
        array $headers = [],
        ?DateTimeImmutable $date = null,
    ): StreamingSigV4Session {
        $date = ($date ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $longDate = $date->format('Ymd\THis\Z');
        $shortDate = $date->format('Ymd');
        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            throw new \InvalidArgumentException('Cannot sign an invalid Bedrock Live URL.');
        }

        $authority = $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        $canonicalHeaders = [];
        foreach ($headers as $name => $value) {
            $normalized = strtolower(trim($name));
            if ($normalized === '' || in_array($normalized, [
                'authorization',
                'connection',
                'content-length',
                'expect',
                'host',
                'transfer-encoding',
                'user-agent',
            ], true)) {
                continue;
            }
            $canonicalHeaders[$normalized] = $value;
        }
        $canonicalHeaders = array_replace($canonicalHeaders, [
            ':authority' => $authority,
            'content-type' => 'application/vnd.amazon.eventstream',
            'x-amz-content-sha256' => self::PAYLOAD_HASH,
            'x-amz-date' => $longDate,
        ]);
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $canonicalHeaders['x-amz-security-token'] = $this->sessionToken;
        }
        ksort($canonicalHeaders);

        $signedHeaderNames = implode(';', array_keys($canonicalHeaders));
        $canonicalHeaderText = '';
        foreach ($canonicalHeaders as $name => $value) {
            $canonicalHeaderText .= $name . ':' . preg_replace('/\s+/', ' ', trim($value)) . "\n";
        }

        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $query = is_string($parts['query'] ?? null) ? $parts['query'] : '';
        $canonicalRequest = strtoupper($method) . "\n"
            . self::canonicalPath($path) . "\n"
            . self::canonicalQuery($query) . "\n"
            . $canonicalHeaderText . "\n"
            . $signedHeaderNames . "\n"
            . self::PAYLOAD_HASH;

        $scope = "{$shortDate}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = self::ALGORITHM . "\n{$longDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->signingKey($shortDate);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $signedHeaders = $headers;
        $signedHeaders['Content-Type'] = 'application/vnd.amazon.eventstream';
        $signedHeaders['X-Amz-Content-Sha256'] = self::PAYLOAD_HASH;
        $signedHeaders['X-Amz-Date'] = $longDate;
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $signedHeaders['X-Amz-Security-Token'] = $this->sessionToken;
        }
        $signedHeaders['Authorization'] = self::ALGORITHM
            . " Credential={$this->accessKeyId}/{$scope}"
            . ", SignedHeaders={$signedHeaderNames}"
            . ", Signature={$signature}";

        return new StreamingSigV4Session(
            headers: $signedHeaders,
            priorSignature: $signature,
            secretAccessKey: $this->secretAccessKey,
            region: $this->region,
            service: $this->service,
        );
    }

    private function signingKey(string $shortDate): string
    {
        $dateKey = hash_hmac('sha256', $shortDate, 'AWS4' . $this->secretAccessKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $this->service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private static function canonicalPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . implode('/', array_map(
            // SigV4 normalizes the already URI-encoded request path, so a
            // literal percent sign is encoded again (for example %3A -> %253A).
            static fn(string $part): string => rawurlencode($part),
            explode('/', ltrim($path, '/')),
        ));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $parts = [];
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[] = rawurlencode(rawurldecode($name)) . '=' . rawurlencode(rawurldecode($value));
        }
        sort($parts, SORT_STRING);

        return implode('&', $parts);
    }
}
