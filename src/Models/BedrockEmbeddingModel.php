<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Models;

use AiSdk\Bedrock\Auth\BedrockAuth;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\EmbeddingModelInterface;
use AiSdk\Exceptions\APIConnectionException;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Generate;
use AiSdk\Requests\EmbeddingRequest;
use AiSdk\Responses\EmbeddingResponse;
use AiSdk\Results\EmbeddingData;
use AiSdk\Support\Json;
use AiSdk\Support\Sdk;
use AiSdk\Support\Usage;
use AiSdk\Utils\Errors\HttpErrorNormalizer;
use AiSdk\Utils\Support\Url;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BedrockEmbeddingModel extends BaseModel implements EmbeddingModelInterface
{
    private const string COHERE_V3 = 'cohere-v3';

    private const string COHERE_V4 = 'cohere-v4';

    private const string TITAN_V1 = 'titan-v1';

    private const string TITAN_V2 = 'titan-v2';

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

    public function generate(EmbeddingRequest $request): EmbeddingResponse
    {
        return match ($this->modelFamily()) {
            self::COHERE_V3, self::COHERE_V4 => $this->generateCohere($request),
            self::TITAN_V1, self::TITAN_V2 => $this->generateTitan($request),
            default => throw new InvalidArgumentException("Bedrock embedding model [{$this->modelId}] has an unsupported wire format."),
        };
    }

    private function generateCohere(EmbeddingRequest $request): EmbeddingResponse
    {
        $family = $this->modelFamily();

        if (count($request->inputs) > 96) {
            throw new InvalidArgumentException('Bedrock Cohere Embed accepts at most 96 text inputs per request.');
        }

        if ($family === self::COHERE_V3 && $request->dimensions !== null) {
            throw new InvalidArgumentException('Bedrock Cohere Embed v3 does not support configurable dimensions.');
        }

        if ($family === self::COHERE_V4 && $request->dimensions !== null && ! in_array($request->dimensions, [256, 512, 1024, 1536], true)) {
            throw new InvalidArgumentException('Bedrock Cohere Embed v4 dimensions must be 256, 512, 1024, or 1536.');
        }

        $body = array_filter([
            'texts' => $request->inputs,
            'embedding_types' => ['float'],
            'output_dimension' => $family === self::COHERE_V4 ? $request->dimensions : null,
        ], static fn(mixed $value): bool => $value !== null);
        $body = $this->mergeProviderOptions($body, $request);

        $inputType = $body['input_type'] ?? null;
        if (! is_string($inputType) || ! in_array($inputType, ['search_document', 'search_query', 'classification', 'clustering'], true)) {
            throw new InvalidArgumentException('Bedrock Cohere Embed requires input_type: search_document, search_query, classification, or clustering.');
        }

        if ($family === self::COHERE_V3 && array_key_exists('output_dimension', $body)) {
            throw new InvalidArgumentException('Bedrock Cohere Embed v3 does not support output_dimension.');
        }

        if ($family === self::COHERE_V4 && isset($body['output_dimension']) && (! is_int($body['output_dimension']) || ! in_array($body['output_dimension'], [256, 512, 1024, 1536], true))) {
            throw new InvalidArgumentException('Bedrock Cohere Embed v4 output_dimension must be 256, 512, 1024, or 1536.');
        }

        $embeddingTypes = $body['embedding_types'] ?? null;
        if (! is_array($embeddingTypes) || ! in_array('float', $embeddingTypes, true)) {
            throw new InvalidArgumentException('Bedrock Cohere Embed requests must include the float embedding type.');
        }

        $payload = $this->invoke($body);
        $values = $payload['embeddings'] ?? null;
        if (is_array($values) && isset($values['float'])) {
            $values = $values['float'];
        }

        $embeddings = $this->embeddings($values);
        if (count($embeddings) !== count($request->inputs)) {
            throw InvalidResponseException::forProvider(
                $this->provider(),
                'Bedrock Cohere Embed returned an unexpected number of valid float embeddings.',
                ['body' => $payload],
            );
        }

        return new EmbeddingResponse(
            embeddings: $embeddings,
            usage: Usage::empty(),
            rawResponse: $payload,
            providerMetadata: [
                $this->provider() => array_filter([
                    'id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
                    'model' => $this->modelId,
                    'response_type' => is_string($payload['response_type'] ?? null) ? $payload['response_type'] : null,
                ], static fn(mixed $value): bool => $value !== null),
            ],
        );
    }

    private function generateTitan(EmbeddingRequest $request): EmbeddingResponse
    {
        $family = $this->modelFamily();

        if ($family === self::TITAN_V1 && $request->dimensions !== null) {
            throw new InvalidArgumentException('Bedrock Titan Text Embeddings V1 does not support configurable dimensions.');
        }

        if ($family === self::TITAN_V2 && $request->dimensions !== null && ! in_array($request->dimensions, [256, 512, 1024], true)) {
            throw new InvalidArgumentException('Bedrock Titan Text Embeddings V2 dimensions must be 256, 512, or 1024.');
        }

        $embeddings = [];
        $payloads = [];
        $inputTokens = 0;

        foreach ($request->inputs as $index => $input) {
            $body = ['inputText' => $input];
            if ($family === self::TITAN_V2) {
                $body = array_filter([
                    ...$body,
                    'dimensions' => $request->dimensions,
                    'embeddingTypes' => ['float'],
                ], static fn(mixed $value): bool => $value !== null);
            }

            $body = $this->mergeProviderOptions($body, $request);
            $this->validateTitanBody($body, $family);

            $payload = $this->invoke($body);
            $payloads[] = $payload;

            $vector = $this->vector($payload['embedding'] ?? null);
            if ($vector === []) {
                throw InvalidResponseException::forProvider(
                    $this->provider(),
                    'Bedrock Titan Text Embeddings returned no valid float embedding.',
                    ['body' => $payload],
                );
            }

            $embeddings[] = new EmbeddingData($vector, $index);
            if (is_numeric($payload['inputTextTokenCount'] ?? null)) {
                $inputTokens += (int) $payload['inputTextTokenCount'];
            }
        }

        return new EmbeddingResponse(
            embeddings: $embeddings,
            usage: new Usage(inputTokens: $inputTokens),
            rawResponse: ['responses' => $payloads],
            providerMetadata: [
                $this->provider() => ['model' => $this->modelId],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function validateTitanBody(array $body, string $family): void
    {
        if ($family === self::TITAN_V1) {
            $unsupported = array_diff(array_keys($body), ['inputText']);
            if ($unsupported !== []) {
                throw new InvalidArgumentException('Bedrock Titan Text Embeddings V1 only supports inputText.');
            }

            return;
        }

        if (isset($body['dimensions']) && (! is_int($body['dimensions']) || ! in_array($body['dimensions'], [256, 512, 1024], true))) {
            throw new InvalidArgumentException('Bedrock Titan Text Embeddings V2 dimensions must be 256, 512, or 1024.');
        }

        if (isset($body['normalize']) && ! is_bool($body['normalize'])) {
            throw new InvalidArgumentException('Bedrock Titan Text Embeddings V2 normalize must be a boolean.');
        }

        $embeddingTypes = $body['embeddingTypes'] ?? null;
        if (! is_array($embeddingTypes) || ! in_array('float', $embeddingTypes, true)) {
            throw new InvalidArgumentException('Bedrock Titan Text Embeddings V2 requests must include the float embedding type.');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function mergeProviderOptions(array $body, EmbeddingRequest $request): array
    {
        $providerOptions = $request->providerOptionsFor($this->provider());
        $raw = $providerOptions['raw'] ?? null;
        unset($providerOptions['raw']);

        $body = array_replace($body, $providerOptions);

        return is_array($raw) ? array_replace($body, $raw) : $body;
    }

    private function modelFamily(): string
    {
        $suffix = '(?::[A-Za-z0-9_-]+)*$~';

        if (preg_match('~(?:^|[./])cohere\.embed-v4' . $suffix, $this->modelId) === 1) {
            return self::COHERE_V4;
        }

        if (preg_match('~(?:^|[./])cohere\.embed-(?:english|multilingual)-v3' . $suffix, $this->modelId) === 1) {
            return self::COHERE_V3;
        }

        if (preg_match('~(?:^|[./])amazon\.titan-embed-text-v2' . $suffix, $this->modelId) === 1) {
            return self::TITAN_V2;
        }

        if (preg_match('~(?:^|[./])amazon\.titan-embed-text-v1' . $suffix, $this->modelId) === 1) {
            return self::TITAN_V1;
        }

        throw new InvalidArgumentException("Bedrock embedding model [{$this->modelId}] has an unsupported wire format. Supported families are Cohere Embed v3/v4 and Amazon Titan Text Embeddings V1/V2.");
    }

    /**
     * @return array<int, EmbeddingData>
     */
    private function embeddings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $embeddings = [];
        foreach ($values as $index => $value) {
            $vector = $this->vector($value);
            if ($vector === []) {
                return [];
            }

            $embeddings[] = new EmbeddingData($vector, (int) $index);
        }

        return $embeddings;
    }

    /**
     * @return array<int, float>
     */
    private function vector(mixed $values): array
    {
        if (! is_array($values) || $values === []) {
            return [];
        }

        $vector = [];
        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return [];
            }

            $vector[] = (float) $value;
        }

        return $vector;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function invoke(array $body): array
    {
        $sdk = $this->options->sdk ?? Generate::sdk();
        $baseUrl = $this->options->baseUrlConfigured
            ? $this->options->baseUrl
            : BedrockOptions::defaultRuntimeUrl($this->options->region);
        $url = Url::joinPath($baseUrl, '/model/' . rawurlencode($this->modelId) . '/invoke');
        $httpRequest = $sdk->requestFactory->createRequest('POST', $url)
            ->withBody($sdk->streamFactory->createStream(Json::encode($body)))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
        $response = $this->send($sdk, $this->authorize($httpRequest, $sdk));
        $this->ensureSuccess($response);

        return Json::decode((string) $response->getBody(), $this->provider());
    }

    private function authorize(RequestInterface $request, Sdk $sdk): RequestInterface
    {
        $request = $request->withHeader('User-Agent', $sdk->userAgent);
        foreach ($this->options->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return (new BedrockAuth($this->options))->signRequest($request);
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

    private function send(Sdk $sdk, RequestInterface $request): ResponseInterface
    {
        try {
            return $sdk->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface|TransferException $e) {
            throw new APIConnectionException(
                message: 'Bedrock transport error: ' . $e->getMessage(),
                context: ['provider' => $this->provider(), 'modelId' => $this->modelId, 'url' => (string) $request->getUri()],
                previous: $e,
            );
        }
    }
}
