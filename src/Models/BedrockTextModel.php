<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Models;

use AiSdk\Bedrock\Auth\BedrockAuth;
use AiSdk\Bedrock\Aws\EventStream;
use AiSdk\Bedrock\BedrockApi;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\Converters\ConvertsUsage;
use AiSdk\Bedrock\Converters\MapsFinishReasons;
use AiSdk\Bedrock\Parsers\AnthropicMessagesStreamParser;
use AiSdk\Bedrock\Support\AnthropicMessagesCommandBuilder;
use AiSdk\Bedrock\Support\ConverseCommandBuilder;
use AiSdk\Capability;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\TextModelInterface;
use AiSdk\Exceptions\APIConnectionException;
use AiSdk\FinishReason;
use AiSdk\Generate;
use AiSdk\OpenAICompatible\ChatRequestBuilder;
use AiSdk\OpenAICompatible\ChatResponseParser;
use AiSdk\OpenAICompatible\ChatStreamParser;
use AiSdk\OpenAICompatible\ResponsesRequestBuilder;
use AiSdk\OpenAICompatible\ResponsesResponseParser;
use AiSdk\OpenAICompatible\ResponsesStreamParser;
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
use AiSdk\Utils\Stream\SseParser;
use AiSdk\Utils\Support\Url;
use Generator;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BedrockTextModel extends BaseModel implements TextModelInterface
{
    private const array ADAPTER_CAPABILITIES = [
        Capability::TextGeneration,
        Capability::Streaming,
        Capability::Reasoning,
        Capability::ToolCalling,
        Capability::StructuredOutput,
        Capability::TextInput,
        Capability::ImageInput,
        Capability::FileInput,
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

        $api = $this->resolveApi($request);
        $sdk = $this->sdk();
        $provider = $this->provider();

        if ($api === BedrockApi::MantleChat || $api === BedrockApi::MantleResponses) {
            $body = $api === BedrockApi::MantleChat
                ? ChatRequestBuilder::build($this->modelId, $provider, $request, stream: false)
                : ResponsesRequestBuilder::build($this->modelId, $provider, $request, stream: false);
            unset($body['api']);

            $httpRequest = $this->jsonRequest($sdk, $this->endpoint($api, false), $body, 'application/json');
            $response = $this->send($sdk, $this->authorize($httpRequest, $sdk), false);
            $this->ensureSuccess($response);
            $payload = Json::decode((string) $response->getBody(), $provider);

            return $api === BedrockApi::MantleChat
                ? ChatResponseParser::parse($payload, $provider)
                : ResponsesResponseParser::parse($payload, $provider);
        }

        $command = $this->buildCommand($api, $request);
        $url = $this->endpoint($api, false);

        $httpRequest = $this->jsonRequest($sdk, $url, $command, 'application/json');
        $response = $this->send($sdk, $this->authorize($httpRequest, $sdk), false);
        $this->ensureSuccess($response);

        $payload = Json::decode((string) $response->getBody(), $provider);

        return match ($api) {
            BedrockApi::Converse => $this->mapConverseResponse($payload),
            BedrockApi::Invoke => $this->mapAnthropicResponse($payload),
        };
    }

    public function stream(TextModelRequest $request): Generator
    {
        $this->ensureTextRequestSupported($request, self::ADAPTER_CAPABILITIES, streaming: true);

        $api = $this->resolveApi($request);
        $sdk = $this->sdk();
        $provider = $this->provider();

        if ($api === BedrockApi::MantleChat) {
            $body = ChatRequestBuilder::build($this->modelId, $provider, $request, stream: true);
            unset($body['api']);
            $response = $this->sendMantleStream($sdk, $api, $body);

            yield from ChatStreamParser::parse(
                SseParser::parseStream($response->getBody()),
                $provider,
            );

            return;
        }

        if ($api === BedrockApi::MantleResponses) {
            $body = ResponsesRequestBuilder::build($this->modelId, $provider, $request, stream: true);
            unset($body['api']);
            $response = $this->sendMantleStream($sdk, $api, $body);

            yield from ResponsesStreamParser::parse(
                SseParser::parseStream($response->getBody()),
                $provider,
            );

            return;
        }

        $command = $this->buildCommand($api, $request);
        $url = $this->endpoint($api, true);

        $accept = 'application/vnd.amazon.eventstream';

        $httpRequest = $this->jsonRequest($sdk, $url, $command, $accept);
        $response = $this->send($sdk, $this->authorize($httpRequest, $sdk), true);
        $this->ensureSuccess($response);

        if ($api === BedrockApi::Converse) {
            yield from $this->mapConverseStream($response->getBody());

            return;
        }

        yield from $this->mapAnthropicStream($response->getBody());
    }

    private function resolveApi(TextModelRequest $request): BedrockApi
    {
        $override = $request->providerOptionsFor($this->provider())['api'] ?? null;
        if ($override !== null) {
            return BedrockApi::resolve($override);
        }

        // Anthropic models default to the native Invoke (Messages) path so the
        // provider-specific features (prompt caching, anthropic_beta,
        // structured output_config) are reachable. Everything else uses
        // Converse; mantle surfaces require an explicit api selection.
        if ($this->options->apiConfigured) {
            return $this->options->api;
        }

        if (str_contains($this->modelId, 'anthropic.')) {
            return BedrockApi::Invoke;
        }

        return $this->options->api;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommand(BedrockApi $api, TextModelRequest $request): array
    {
        $opts = $request->providerOptionsFor($this->provider());
        unset($opts['api']);

        return match ($api) {
            BedrockApi::Converse => ConverseCommandBuilder::build($this->modelId, $request, $opts),
            BedrockApi::Invoke => AnthropicMessagesCommandBuilder::build($request, $opts),
            BedrockApi::MantleChat, BedrockApi::MantleResponses => $opts,
        };
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

    private function endpoint(BedrockApi $api, bool $stream): string
    {
        $baseUrl = $this->options->baseUrlConfigured
            ? $this->options->baseUrl
            : BedrockOptions::defaultUrl($this->options->region, $api);

        return match ($api) {
            BedrockApi::Converse => Url::joinPath(
                $baseUrl,
                '/model/' . rawurlencode($this->modelId) . '/' . ($stream ? 'converse-stream' : 'converse'),
            ),
            BedrockApi::Invoke => Url::joinPath(
                $baseUrl,
                '/model/' . rawurlencode($this->modelId) . '/' . ($stream ? 'invoke-with-response-stream' : 'invoke'),
            ),
            BedrockApi::MantleChat => Url::joinPath($baseUrl, '/chat/completions'),
            BedrockApi::MantleResponses => Url::joinPath($baseUrl, '/responses'),
        };
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

    private function send(Sdk $sdk, RequestInterface $request, bool $stream): ResponseInterface
    {
        try {
            if ($stream && $sdk->httpClient instanceof GuzzleClientInterface) {
                return $sdk->httpClient->send($request, [
                    'allow_redirects' => false,
                    'stream' => true,
                ]);
            }

            return $sdk->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface|TransferException $e) {
            throw new APIConnectionException(
                message: 'Bedrock transport error: ' . $e->getMessage(),
                context: ['provider' => $this->provider(), 'modelId' => $this->modelId, 'url' => (string) $request->getUri()],
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mapConverseResponse(array $payload): TextModelResponse
    {
        $text = '';
        $reasoning = '';

        $message = $payload['output']['message'] ?? $payload['message'] ?? null;
        foreach (($message['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
            $reasoningText = $block['reasoningContent']['reasoningText']['text']
                ?? $block['reasoningContent']['text']
                ?? null;
            if (is_string($reasoningText)) {
                $reasoning .= $reasoningText;
            }
        }

        $parts = [];
        if ($reasoning !== '') {
            $parts[] = new ReasoningPart($reasoning);
        }
        if ($text !== '') {
            $parts[] = new TextPart($text);
        }

        $usage = isset($payload['usage']) && is_array($payload['usage'])
            ? ConvertsUsage::fromBedrock($payload['usage'])
            : Usage::empty();

        return new TextModelResponse(
            parts: $parts,
            finishReason: MapsFinishReasons::fromBedrock((string) ($payload['stopReason'] ?? '')),
            usage: $usage,
            rawResponse: $payload,
            providerMetadata: [($this->provider()) => ['model' => $payload['model'] ?? $this->modelId]],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mapAnthropicResponse(array $payload): TextModelResponse
    {
        $text = '';
        $reasoning = '';
        foreach (($payload['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (isset($block['type']) && $block['type'] === 'text' && is_string($block['text'])) {
                $text .= $block['text'];
            }
            if (isset($block['type']) && $block['type'] === 'thinking' && is_string($block['thinking'] ?? null)) {
                $reasoning .= $block['thinking'];
            }
        }

        $parts = [];
        if ($reasoning !== '') {
            $parts[] = new ReasoningPart($reasoning);
        }
        if ($text !== '') {
            $parts[] = new TextPart($text);
        }

        $usage = isset($payload['usage']) && is_array($payload['usage'])
            ? ConvertsUsage::fromAnthropic($payload['usage'])
            : Usage::empty();

        return new TextModelResponse(
            parts: $parts,
            finishReason: MapsFinishReasons::fromAnthropic($payload['stop_reason'] ?? null),
            usage: $usage,
            rawResponse: $payload,
            providerMetadata: [($this->provider()) => ['model' => $payload['model'] ?? $this->modelId]],
        );
    }

    /**
     * @return Generator<int, StreamPart>
     */
    private function mapConverseStream(\Psr\Http\Message\StreamInterface $raw): Generator
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

    /**
     * @return Generator<int, StreamPart>
     */
    private function mapAnthropicStream(\Psr\Http\Message\StreamInterface $raw): Generator
    {
        yield from AnthropicMessagesStreamParser::parse($this->anthropicEvents($raw));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sendMantleStream(Sdk $sdk, BedrockApi $api, array $body): ResponseInterface
    {
        $request = $this->jsonRequest($sdk, $this->endpoint($api, true), $body, 'text/event-stream');
        $response = $this->send($sdk, $this->authorize($request, $sdk), true);
        $this->ensureSuccess($response);

        return $response;
    }

    /** @return Generator<int, array<string, mixed>> */
    private function anthropicEvents(\Psr\Http\Message\StreamInterface $raw): Generator
    {
        foreach (EventStream::decodeStreamChunks($raw) as $event) {
            $envelope = json_decode($event['data'], true);
            if (! is_array($envelope) || ! is_string($envelope['bytes'] ?? null)) {
                continue;
            }

            $decoded = base64_decode($envelope['bytes'], true);
            $payload = is_string($decoded) ? json_decode($decoded, true) : null;
            if (is_array($payload)) {
                yield $payload;
            }
        }
    }
}
