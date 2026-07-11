<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\RequestInterface;

/**
 * AWS Signature Version 4 for Bedrock Runtime (service "bedrock").
 */
final class SigV4Signer
{
    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly ?string $sessionToken,
        private readonly string $region,
        private readonly string $service = 'bedrock',
    ) {}

    public function sign(RequestInterface $request): RequestInterface
    {
        $body = (string) $request->getBody();
        $payloadHash = hash('sha256', $body);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        $uri = $request->getUri();
        $host = $uri->getHost();
        $canonicalUri = self::canonicalUri($uri->getPath() ?: '/');
        $canonicalQueryString = self::canonicalQuery($uri->getQuery());

        /** @var array<string, string> $headers */
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
            $signedHeadersList[] = $name;
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $canonicalRequest = strtoupper($request->getMethod()) . "\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $stringToSign = $algorithm . "\n"
            . $amzDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->deriveSigningKey($dateStamp);
        $signature = strtolower(bin2hex(hash_hmac('sha256', $stringToSign, $signingKey, true)));

        $authorization = $algorithm
            . ' Credential=' . $this->accessKeyId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $signed = $request
            ->withHeader('Authorization', $authorization)
            ->withHeader('X-Amz-Date', $amzDate)
            ->withHeader('X-Amz-Content-Sha256', $payloadHash);

        if (isset($headers['x-amz-security-token'])) {
            $signed = $signed->withHeader('X-Amz-Security-Token', $headers['x-amz-security-token']);
        }

        return $signed;
    }

    private function deriveSigningKey(string $dateStamp): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private static function canonicalUri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        $parts = explode('/', trim($path, '/'));
        $encoded = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $encoded[] = rawurlencode(rawurldecode($part));
        }

        return '/' . implode('/', $encoded);
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $pairs);
        $parts = [];
        foreach ($pairs as $key => $value) {
            if (is_array($value)) {
                sort($value);
                foreach ($value as $v) {
                    $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $v);
                }
            } else {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            }
        }
        sort($parts);

        return implode('&', $parts);
    }
}
