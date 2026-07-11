<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Tests\Fakes;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    public function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly string $contentType = 'application/json',
        private readonly ?ClientExceptionInterface $exception = null,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return new Response($this->status, ['Content-Type' => $this->contentType], $this->body);
    }

    /**
     * @return array<string, mixed>
     */
    public function sentBody(): array
    {
        $decoded = json_decode((string) $this->lastRequest?->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
