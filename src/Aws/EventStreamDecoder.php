<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Aws;

final class EventStreamDecoder
{
    private string $buffer = '';

    public function __construct(private readonly int $maximumFrameBytes = 16_777_216)
    {
        if ($this->maximumFrameBytes < 16) {
            throw new \InvalidArgumentException('The EventStream frame limit must be at least 16 bytes.');
        }
    }

    /** @return list<EventStreamMessage> */
    public function push(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];

        while (strlen($this->buffer) >= 4) {
            $totalLength = EventStream::readUint32Be($this->buffer, 0);
            if ($totalLength < 16) {
                throw EventStream::invalid('Bedrock returned an invalid event-stream frame length.');
            }
            if ($totalLength > $this->maximumFrameBytes) {
                throw EventStream::invalid('Bedrock returned an event-stream frame that exceeded the configured byte limit.');
            }
            if (strlen($this->buffer) < $totalLength) {
                break;
            }

            $frame = substr($this->buffer, 0, $totalLength);
            $this->buffer = substr($this->buffer, $totalLength);
            $messages[] = $this->decode($frame);
        }

        return $messages;
    }

    public function finish(): void
    {
        if ($this->buffer !== '') {
            throw EventStream::invalid('Bedrock ended an event stream with an incomplete frame.');
        }
    }

    private function decode(string $message): EventStreamMessage
    {
        $length = strlen($message);
        $totalLength = EventStream::readUint32Be($message, 0);
        $headersLength = EventStream::readUint32Be($message, 4);

        if ($length !== $totalLength || $totalLength < 16 + $headersLength) {
            throw EventStream::invalid('Bedrock returned inconsistent event-stream frame lengths.');
        }
        if (EventStream::crc32Unsigned(substr($message, 0, 8)) !== EventStream::readUint32Be($message, 8)) {
            throw EventStream::invalid('Bedrock returned an event stream with an invalid prelude checksum.');
        }
        if (EventStream::crc32Unsigned(substr($message, 0, -4)) !== EventStream::readUint32Be($message, $totalLength - 4)) {
            throw EventStream::invalid('Bedrock returned an event stream with an invalid message checksum.');
        }

        $headers = $this->decodeHeaders(substr($message, 12, $headersLength));
        $payload = substr($message, 12 + $headersLength, $totalLength - 16 - $headersLength);

        return new EventStreamMessage($headers, $payload);
    }

    /** @return array<string, mixed> */
    private function decodeHeaders(string $bytes): array
    {
        $headers = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $nameLength = ord($bytes[$offset] ?? "\0");
            $offset++;
            if ($nameLength === 0 || $offset + $nameLength + 1 > $length) {
                throw EventStream::invalid('Bedrock returned a malformed event-stream header name.');
            }

            $name = substr($bytes, $offset, $nameLength);
            $offset += $nameLength;
            $type = ord($bytes[$offset]);
            $offset++;

            $headers[$name] = match ($type) {
                EventStream::TYPE_BOOLEAN_TRUE => true,
                EventStream::TYPE_BOOLEAN_FALSE => false,
                EventStream::TYPE_BYTE => $this->readNumeric($bytes, $offset, 1),
                EventStream::TYPE_SHORT => $this->readNumeric($bytes, $offset, 2),
                EventStream::TYPE_INTEGER => $this->readNumeric($bytes, $offset, 4),
                EventStream::TYPE_LONG, EventStream::TYPE_TIMESTAMP => $this->readLong($bytes, $offset),
                EventStream::TYPE_BINARY, EventStream::TYPE_STRING => $this->readVariable($bytes, $offset),
                EventStream::TYPE_UUID => $this->readUuid($bytes, $offset),
                default => throw EventStream::invalid("Bedrock returned unsupported event-stream header type [{$type}]."),
            };
        }

        return $headers;
    }

    private function readNumeric(string $bytes, int &$offset, int $length): int
    {
        if ($offset + $length > strlen($bytes)) {
            throw EventStream::invalid('Bedrock returned a truncated event-stream numeric header.');
        }
        $decoded = unpack(match ($length) {
            1 => 'cvalue',
            2 => 'nvalue',
            4 => 'Nvalue',
            default => throw EventStream::invalid('Bedrock returned an unsupported event-stream numeric header length.'),
        }, substr($bytes, $offset, $length));
        $offset += $length;

        $value = is_array($decoded) ? (int) $decoded['value'] : 0;
        if ($length === 2 && $value > 0x7FFF) {
            return $value - 0x10000;
        }
        if ($length === 4 && $value > 0x7FFFFFFF) {
            return $value - 0x100000000;
        }

        return $value;
    }

    private function readLong(string $bytes, int &$offset): int
    {
        if ($offset + 8 > strlen($bytes)) {
            throw EventStream::invalid('Bedrock returned a truncated event-stream long header.');
        }
        $value = EventStream::readInt64Be($bytes, $offset);
        $offset += 8;

        return $value;
    }

    private function readVariable(string $bytes, int &$offset): string
    {
        if ($offset + 2 > strlen($bytes)) {
            throw EventStream::invalid('Bedrock returned a truncated event-stream variable header.');
        }
        $lengthData = unpack('nvalue', substr($bytes, $offset, 2));
        $valueLength = is_array($lengthData) ? (int) $lengthData['value'] : 0;
        $offset += 2;
        if ($offset + $valueLength > strlen($bytes)) {
            throw EventStream::invalid('Bedrock returned a truncated event-stream variable header value.');
        }
        $value = substr($bytes, $offset, $valueLength);
        $offset += $valueLength;

        return $value;
    }

    private function readUuid(string $bytes, int &$offset): string
    {
        if ($offset + 16 > strlen($bytes)) {
            throw EventStream::invalid('Bedrock returned a truncated event-stream UUID header.');
        }
        $hex = bin2hex(substr($bytes, $offset, 16));
        $offset += 16;

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
