<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Aws;

use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Exceptions\InvalidResponseException;
use Generator;
use Psr\Http\Message\StreamInterface;

/** Amazon EventStream binary framing and CRC implementation. */
final class EventStream
{
    public const int TYPE_BOOLEAN_TRUE = 0;

    public const int TYPE_BOOLEAN_FALSE = 1;

    public const int TYPE_BYTE = 2;

    public const int TYPE_SHORT = 3;

    public const int TYPE_INTEGER = 4;

    public const int TYPE_LONG = 5;

    public const int TYPE_BINARY = 6;

    public const int TYPE_STRING = 7;

    public const int TYPE_TIMESTAMP = 8;

    public const int TYPE_UUID = 9;

    /**
     * @param array<string, array{type: int, value: mixed}> $headers
     */
    public static function encodeMessage(array $headers, string $payload = ''): string
    {
        $headersBinary = '';
        foreach ($headers as $name => $header) {
            $headersBinary .= self::encodeHeader($name, $header['type'], $header['value']);
        }

        $headersLength = strlen($headersBinary);
        $totalLength = 16 + $headersLength + strlen($payload);
        $preludeBytes = self::uint32Be($totalLength) . self::uint32Be($headersLength);
        $prelude = $preludeBytes . self::uint32Be(self::crc32Unsigned($preludeBytes));
        $message = $prelude . $headersBinary . $payload;

        return $message . self::uint32Be(self::crc32Unsigned($message));
    }

    /** Encodes an ordinary Smithy event message. */
    public static function encodeEvent(string $eventType, string $jsonPayload): string
    {
        return self::encodeMessage([
            ':message-type' => ['type' => self::TYPE_STRING, 'value' => 'event'],
            ':event-type' => ['type' => self::TYPE_STRING, 'value' => $eventType],
        ], $jsonPayload);
    }

    /** Encodes the Smithy `chunk` union used by Bedrock bidirectional streams. */
    public static function encodeChunk(string $bytes): string
    {
        $payload = json_encode(['bytes' => base64_encode($bytes)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::encodeMessage([
            ':event-type' => ['type' => self::TYPE_STRING, 'value' => 'chunk'],
            ':message-type' => ['type' => self::TYPE_STRING, 'value' => 'event'],
            ':content-type' => ['type' => self::TYPE_STRING, 'value' => 'application/json'],
        ], $payload);
    }

    /**
     * @return Generator<int, array{eventType: string, data: string}>
     */
    public static function decodeStreamChunks(string|StreamInterface $chunks): Generator
    {
        $decoder = new EventStreamDecoder();

        foreach (self::chunks($chunks) as $chunk) {
            foreach ($decoder->push($chunk) as $message) {
                $messageType = $message->stringHeader(':message-type') ?? '';
                $eventType = $message->stringHeader(':event-type')
                    ?? $message->stringHeader(':exception-type')
                    ?? '';

                if ($messageType === 'exception' || $messageType === 'error' || str_ends_with($eventType, 'Exception')) {
                    $payload = json_decode($message->payload, true);
                    $errorMessage = is_array($payload) && is_string($payload['message'] ?? null)
                        ? $payload['message']
                        : 'Bedrock returned an event-stream exception.';

                    throw self::invalid($errorMessage, ['eventType' => $eventType]);
                }

                if ($messageType === 'event' && $eventType !== '') {
                    yield ['eventType' => $eventType, 'data' => $message->payload];
                }
            }
        }

        $decoder->finish();
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
    public static function invalid(string $message, array $context = []): InvalidResponseException
    {
        return InvalidResponseException::forProvider(BedrockOptions::PROVIDER_NAME, $message, $context);
    }

    private static function encodeHeader(string $name, int $type, mixed $value): string
    {
        $nameLength = strlen($name);
        if ($nameLength < 1 || $nameLength > 255) {
            throw new \InvalidArgumentException('AWS EventStream header names must contain between 1 and 255 bytes.');
        }

        $prefix = chr($nameLength) . $name . chr($type);

        return $prefix . match ($type) {
            self::TYPE_BOOLEAN_TRUE, self::TYPE_BOOLEAN_FALSE => '',
            self::TYPE_BYTE => pack('c', (int) $value),
            self::TYPE_SHORT => pack('n', (int) $value & 0xFFFF),
            self::TYPE_INTEGER => pack('N', (int) $value & 0xFFFFFFFF),
            self::TYPE_LONG, self::TYPE_TIMESTAMP => self::uint64Be((int) $value),
            self::TYPE_BINARY, self::TYPE_STRING => self::lengthPrefixed((string) $value),
            self::TYPE_UUID => self::uuidBytes((string) $value),
            default => throw new \InvalidArgumentException("Unsupported AWS EventStream header type [{$type}]."),
        };
    }

    private static function lengthPrefixed(string $value): string
    {
        if (strlen($value) > 65_535) {
            throw new \InvalidArgumentException('AWS EventStream string and binary headers cannot exceed 65535 bytes.');
        }

        return pack('n', strlen($value)) . $value;
    }

    private static function uuidBytes(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);
        $bytes = hex2bin($hex);
        if ($bytes === false || strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('AWS EventStream UUID headers must contain a valid UUID.');
        }

        return $bytes;
    }

    public static function uint32Be(int $value): string
    {
        return pack('N', $value & 0xFFFFFFFF);
    }

    public static function uint64Be(int $value): string
    {
        $high = ($value >> 32) & 0xFFFFFFFF;
        $low = $value & 0xFFFFFFFF;

        return pack('NN', $high & 0xFFFFFFFF, $low & 0xFFFFFFFF);
    }

    public static function readUint32Be(string $data, int $offset): int
    {
        $value = unpack('Nvalue', substr($data, $offset, 4));

        return is_array($value) ? ((int) $value['value'] & 0xFFFFFFFF) : 0;
    }

    public static function readUint64Be(string $data, int $offset): int
    {
        $value = unpack('Nhigh/Nlow', substr($data, $offset, 8));
        if (! is_array($value)) {
            return 0;
        }

        return ((int) $value['high'] * 4_294_967_296) + (int) $value['low'];
    }

    public static function readInt64Be(string $data, int $offset): int
    {
        $value = unpack('Nhigh/Nlow', substr($data, $offset, 8));
        if (! is_array($value)) {
            return 0;
        }

        $high = (int) $value['high'];
        if (($high & 0x80000000) !== 0) {
            $high -= 4_294_967_296;
        }

        return ($high * 4_294_967_296) + (int) $value['low'];
    }

    public static function crc32Unsigned(string $data): int
    {
        return crc32($data) & 0xFFFFFFFF;
    }
}
