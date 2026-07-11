<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Models;

use AiSdk\Bedrock\Auth\BedrockAuth;
use AiSdk\Bedrock\Aws\EventStream;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\Converters\ConvertsMessages;
use AiSdk\Bedrock\Converters\ConvertsUsage;
use AiSdk\Bedrock\Converters\MapsFinishReasons;
use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\FinishReason;
use AiSdk\Generate;
use AiSdk\Requests\TextModelRequest;
use AiSdk\Responses\Parts\ReasoningPart;
use AiSdk\Responses\Parts\TextPart;
use AiSdk\Responses\TextModelResponse;
use AiSdk\Streaming\FinishPart;
use AiSdk\Streaming\ProviderMetadataPart;
use AiSdk\Streaming\ReasoningDeltaPart;
use AiSdk\Streaming\StreamPart;
use AiSdk\Streaming\TextDeltaPart;
use AiSdk\Support\Json;
use AiSdk\Support\Sdk;
use AiSdk\Support\Usage;
use AiSdk\Utils\Errors\HttpErrorNormalizer;
use AiSdk\Utils\Support\Url;
use Generator;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BedrockTextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::Reasoning,
        Capability::TextInput,
        Capability::ImageInput,
    ];

    public function __construct(
        private readonly string $modelId,
        private readonly BedrockOptions $options,
    ) {}

    public function provider(): string
    {
        return BedrockOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(TextModelRequest $request): TextModelResponse
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES);

        $command = $this->buildCommand($request);
        $url = $this->endpoint(false);
        $sdk = $this->sdk();

        $httpRequest = $this->jsonRequest($sdk, $url, $command, 'application/json');
        $response = $this->send($sdk, $this->authorize($httpRequest, $sdk), false);
        $this->ensureSuccess($response);

        /** @var array<string, mixed> $payload */
        $payload = Json::decode((string) $response->getBody(), $this->provider());

        return $this->mapResponse($payload);
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, streaming: true);

        $command = $this->buildCommand($request);
        $url = $this->endpoint(true);
        $sdk = $this->sdk();

        $httpRequest = $this->jsonRequest($sdk, $url, $command, 'application/vnd.amazon.eventstream');
        $response = $this->send($sdk, $this->authorize($httpRequest, $sdk), true);
        $this->ensureSuccess($response);

        yield from $this->mapStreamEvents($response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommand(TextModelRequest $request): array
    {
        $opts = $request->providerOptionsFor($this->provider());
        $converted = ConvertsMessages::toConverseMessages($request->messages, $request->system);

        $temperature = max(0.0, min(1.0, $request->temperature));

        $inference = ['maxTokens' => $request->maxTokens];
        if ($request->reasoning === null) {
            $inference['temperature'] = $temperature;
        }
        if ($request->topP !== null && $request->reasoning === null) {
            $inference['topP'] = $request->topP;
        }

        $command = [
            'system' => $converted['system'],
            'messages' => $converted['messages'],
            'inferenceConfig' => $inference,
        ];

        if ($request->reasoning !== null) {
            $thinking = $request->reasoning->budgetTokens !== null
                ? ['type' => 'enabled', 'budget_tokens' => $request->reasoning->budgetTokens]
                : ['type' => 'adaptive'];
            $additional = ['thinking' => $thinking];
            if ($request->reasoning->effort !== null) {
                $additional['output_config'] = ['effort' => $request->reasoning->effort];
            }
            $command['additionalModelRequestFields'] = $additional;
        }

        $raw = $opts['raw'] ?? null;
        unset($opts['raw']);
        $command = array_replace($command, $opts);
        if (is_array($raw)) {
            $command = array_replace($command, $raw);
        }

        return $command;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function jsonRequest(Sdk $sdk, string $url, array $command, string $accept): RequestInterface
    {
        return $sdk->requestFactory->createRequest('POST', $url)
            ->withBody($sdk->streamFactory->createStream(Json::encode($command)))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', $accept);
    }

    private function endpoint(bool $stream): string
    {
        $suffix = $stream ? 'converse-stream' : 'converse';

        return Url::joinPath($this->options->baseUrl, '/model/' . rawurlencode($this->modelId) . '/' . $suffix);
    }

    private function sdk(): Sdk
    {
        return $this->options->sdk ?? Generate::sdk();
    }

    private function authorize(RequestInterface $request, Sdk $sdk): RequestInterface
    {
        $req = $request->withHeader('User-Agent', $sdk->userAgent);
        foreach ($this->options->headers as $name => $value) {
            $req = $req->withHeader((string) $name, (string) $value);
        }

        return (new BedrockAuth($this->options))->signRequest($req);
    }

    private function ensureSuccess(ResponseInterface $response): void
    {
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return;
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $requestId = $response->getHeaderLine('x-amzn-requestid') ?: $response->getHeaderLine('x-request-id');

        throw HttpErrorNormalizer::normalize(
            provider: $this->provider(),
            status: $response->getStatusCode(),
            body: is_array($decoded) ? $decoded : $body,
            requestId: $requestId !== '' ? $requestId : null,
            modelId: $this->modelId,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mapResponse(array $payload): TextModelResponse
    {
        $message = $payload['output']['message'] ?? null;
        $parts = [];

        if (is_array($message) && isset($message['content']) && is_array($message['content'])) {
            foreach ($message['content'] as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = new TextPart($part['text']);
                }
                if (isset($part['reasoningContent']['reasoningText']['text'])) {
                    $parts[] = new ReasoningPart((string) $part['reasoningContent']['reasoningText']['text']);
                }
            }
        }

        $usage = isset($payload['usage']) && is_array($payload['usage'])
            ? ConvertsUsage::fromBedrock($payload['usage'])
            : Usage::empty();

        $stopReason = isset($payload['stopReason']) ? (string) $payload['stopReason'] : null;

        $meta = [];
        if (isset($payload['usage']) && is_array($payload['usage'])) {
            $meta['usage'] = $payload['usage'];
        }

        return new TextModelResponse(
            parts: $parts,
            finishReason: MapsFinishReasons::fromBedrock($stopReason),
            usage: $usage,
            rawResponse: $payload,
            providerMetadata: $meta !== [] ? [$this->provider() => $meta] : [],
        );
    }

    /**
     * @return Generator<int, StreamPart>
     */
    private function mapStreamEvents(\Psr\Http\Message\StreamInterface $raw): Generator
    {
        $usage = Usage::empty();
        $finishReason = FinishReason::Unknown;
        $metadata = [];

        foreach (EventStream::decodeStreamChunks($raw) as $evt) {
            $inner = json_decode($evt['data'], true);
            if (! is_array($inner)) {
                continue;
            }
            unset($inner['p']);

            $w = [$evt['eventType'] => $inner];

            if (isset($w['contentBlockDelta']['delta'])) {
                $delta = $w['contentBlockDelta']['delta'];
                if (isset($delta['text']) && is_string($delta['text'])) {
                    yield new TextDeltaPart($delta['text']);
                }
                if (isset($delta['reasoningContent']['text']) && is_string($delta['reasoningContent']['text'])) {
                    yield new ReasoningDeltaPart($delta['reasoningContent']['text']);
                }
            }

            if (isset($w['messageStop']['stopReason'])) {
                $finishReason = MapsFinishReasons::fromBedrock((string) $w['messageStop']['stopReason']);
            }

            if (isset($w['metadata']['usage']) && is_array($w['metadata']['usage'])) {
                $usage = ConvertsUsage::fromBedrock($w['metadata']['usage']);
                $metadata['usage'] = $w['metadata']['usage'];
            }
        }

        if ($metadata !== []) {
            yield new ProviderMetadataPart($this->provider(), $metadata);
        }

        yield new FinishPart($finishReason, $usage);
    }

    private function send(Sdk $sdk, RequestInterface $request, bool $stream): ResponseInterface
    {
        if ($sdk->httpClient instanceof GuzzleClientInterface) {
            return $sdk->httpClient->send($request, [
                'allow_redirects' => false,
                'stream' => $stream,
            ]);
        }

        return $sdk->httpClient->sendRequest($request);
    }

}
