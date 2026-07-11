<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Aws;

use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Exceptions\InvalidResponseException;
use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Minimal AWS Event Stream encoder/decoder for Bedrock Converse Stream responses.
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/RESTObjectPOST.html (event stream framing)
 */
final class EventStream
{
    /**
     * Encode a single Bedrock stream event (headers :message-type + :event-type + JSON payload).
     */
    public static function encodeEvent(string $eventType, string $jsonPayload): string
    {
        $headersBin = self::encodeStringHeader(':message-type', 'event')
            . self::encodeStringHeader(':event-type', $eventType);
        $headersLen = strlen($headersBin);
        $payloadLen = strlen($jsonPayload);
        $totalLen = 12 + $headersLen + $payloadLen + 4;

        $preludeFirst8 = self::uint32Be($totalLen) . self::uint32Be($headersLen);
        $preludeCrc = self::crc32Unsigned($preludeFirst8);

        $prelude = $preludeFirst8 . self::uint32Be($preludeCrc);
        $withoutTrailingCrc = $prelude . $headersBin . $jsonPayload;
        $messageCrc = self::crc32Unsigned($withoutTrailingCrc);

        return $withoutTrailingCrc . self::uint32Be($messageCrc);
    }

    /**
     * @return Generator<int, array{eventType: string, data: string}>
     */
    public static function decodeStreamChunks(string|StreamInterface $chunks): Generator
    {
        $buffer = '';

        foreach (self::chunks($chunks) as $chunk) {
            $buffer .= $chunk;

            while (strlen($buffer) >= 4) {
                $totalLen = self::readUint32Be($buffer, 0);
                if ($totalLen < 16) {
                    throw self::invalid('Bedrock returned an invalid event-stream frame length.');
                }
                if (strlen($buffer) < $totalLen) {
                    break;
                }

                $frame = substr($buffer, 0, $totalLen);
                $buffer = substr($buffer, $totalLen);
                $decoded = self::decodeMessage($frame);
                $messageType = $decoded[':message-type'] ?? '';
                $eventType = $decoded[':event-type'] ?? $decoded[':exception-type'] ?? '';

                if ($messageType === 'exception' || $messageType === 'error' || str_ends_with($eventType, 'Exception')) {
                    $payload = json_decode($decoded['__payload'], true);
                    $message = is_array($payload) && is_string($payload['message'] ?? null)
                        ? $payload['message']
                        : 'Bedrock returned an event-stream exception.';

                    throw self::invalid($message, ['eventType' => $eventType]);
                }

                if ($messageType === 'event' && $eventType !== '') {
                    yield ['eventType' => $eventType, 'data' => $decoded['__payload']];
                }
            }
        }

        if ($buffer !== '') {
            throw self::invalid('Bedrock ended an event stream with an incomplete frame.');
        }
    }

    /**
     * @return array<string, string>
     */
    private static function decodeMessage(string $message): array
    {
        $len = strlen($message);
        if ($len < 16) {
            throw self::invalid('Bedrock returned a truncated event-stream frame.');
        }

        $totalLen = self::readUint32Be($message, 0);
        $headersLen = self::readUint32Be($message, 4);
        $preludeCrcExpected = self::readUint32Be($message, 8);

        if ($len !== $totalLen || $totalLen < 16 + $headersLen) {
            throw self::invalid('Bedrock returned inconsistent event-stream frame lengths.');
        }

        $prelude8 = substr($message, 0, 8);
        if (self::crc32Unsigned($prelude8) !== $preludeCrcExpected) {
            throw self::invalid('Bedrock returned an event stream with an invalid prelude checksum.');
        }

        $headersBin = substr($message, 12, $headersLen);
        $payload = substr($message, 12 + $headersLen, $totalLen - 16 - $headersLen);

        $withoutCrc = substr($message, 0, $totalLen - 4);
        $msgCrcExpected = self::readUint32Be($message, $totalLen - 4);
        if (self::crc32Unsigned($withoutCrc) !== $msgCrcExpected) {
            throw self::invalid('Bedrock returned an event stream with an invalid message checksum.');
        }

        $headers = self::parseHeaders($headersBin);
        $headers['__payload'] = $payload;

        return $headers;
    }

    /** @return Generator<int, string> */
    private static function chunks(string|StreamInterface $source): Generator
    {
        if (is_string($source)) {
            if ($source !== '') {
                yield $source;
            }

            return;
        }

        while (! $source->eof()) {
            $chunk = $source->read(8192);
            if ($chunk === '') {
                break;
            }

            yield $chunk;
        }
    }

    /** @param array<string, mixed> $context */
    private static function invalid(string $message, array $context = []): InvalidResponseException
    {
        return InvalidResponseException::forProvider(BedrockOptions::PROVIDER_NAME, $message, $context);
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $headersBin): array
    {
        $out = [];
        $offset = 0;
        $max = strlen($headersBin);
        while ($offset < $max) {
            $nameLen = ord($headersBin[$offset]);
            $offset++;
            $name = substr($headersBin, $offset, $nameLen);
            $offset += $nameLen;
            if ($offset >= $max) {
                break;
            }
            $type = ord($headersBin[$offset]);
            $offset++;
            if ($type === 7) {
                if ($offset + 2 > $max) {
                    break;
                }
                $valLen = (ord($headersBin[$offset]) << 8) | ord($headersBin[$offset + 1]);
                $offset += 2;
                $value = substr($headersBin, $offset, $valLen);
                $offset += $valLen;
                $out[$name] = $value;
            } else {
                break;
            }
        }

        return $out;
    }

    private static function encodeStringHeader(string $name, string $value): string
    {
        $nb = strlen($name);

        return chr($nb) . $name . chr(7) . pack('n', strlen($value)) . $value;
    }

    private static function uint32Be(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    private static function readUint32Be(string $data, int $offset): int
    {
        $chunk = substr($data, $offset, 4);
        if (strlen($chunk) !== 4) {
            return 0;
        }
        $v = unpack('N', $chunk);
        if ($v === false) {
            return 0;
        }

        return (int) ($v[1] & 0xFFFFFFFF);
    }

    private static function crc32Unsigned(string $data): int
    {
        return crc32($data) & 0xFFFFFFFF;
    }
}
