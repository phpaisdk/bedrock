<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Models;

use AiSdk\Bedrock\Auth\BedrockAuth;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\ImageModelInterface;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Generate;
use AiSdk\Requests\ImageRequest;
use AiSdk\Responses\ImageResponse;
use AiSdk\Results\ImageData;
use AiSdk\Support\Json;
use AiSdk\Support\Sdk;
use AiSdk\Support\Usage;
use AiSdk\Utils\Errors\HttpErrorNormalizer;
use AiSdk\Utils\Support\Url;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BedrockImageModel extends BaseModel implements ImageModelInterface
{
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

    public function generate(ImageRequest $request): ImageResponse
    {
        [$width, $height] = $this->dimensions($request);
        $body = [
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => ['text' => $request->prompt],
            'imageGenerationConfig' => array_filter([
                'numberOfImages' => $request->count,
                'width' => $width,
                'height' => $height,
                'seed' => $request->seed,
            ], static fn(mixed $value): bool => $value !== null),
        ];

        $providerOptions = $request->providerOptionsFor($this->provider());
        $raw = $providerOptions['raw'] ?? null;
        unset($providerOptions['raw']);
        $body = array_replace_recursive($body, $providerOptions);
        if (is_array($raw)) {
            $body = array_replace_recursive($body, $raw);
        }

        $sdk = $this->options->sdk ?? Generate::sdk();
        $url = Url::joinPath($this->options->baseUrl, '/model/' . rawurlencode($this->modelId) . '/invoke');
        $httpRequest = $sdk->requestFactory->createRequest('POST', $url)
            ->withBody($sdk->streamFactory->createStream(Json::encode($body)))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json');
        $response = $sdk->httpClient->sendRequest($this->authorize($httpRequest, $sdk));
        $this->ensureSuccess($response);

        $payload = Json::decode((string) $response->getBody(), $this->provider());
        $images = [];
        foreach (($payload['images'] ?? []) as $base64) {
            if (is_string($base64) && $base64 !== '') {
                $images[] = new ImageData(base64: $base64, width: $width, height: $height);
            }
        }
        if ($images === []) {
            $message = is_string($payload['error'] ?? null) ? $payload['error'] : 'Bedrock returned no generated images.';

            throw InvalidResponseException::forProvider($this->provider(), $message, ['body' => $payload]);
        }

        return new ImageResponse($images, Usage::empty(), $payload, [
            $this->provider() => ['model' => $this->modelId],
        ]);
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(ImageRequest $request): array
    {
        $size = $request->size ?? match ($request->aspectRatio) {
            null, '1:1' => '1024x1024',
            '16:9', '3:2' => '1280x768',
            '9:16', '2:3' => '768x1280',
            default => throw new InvalidArgumentException("Bedrock image generation cannot infer dimensions for aspect ratio [{$request->aspectRatio}]. Pass size()."),
        };
        if (preg_match('/^(\d+)x(\d+)$/', $size, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid Bedrock image size [{$size}]. Expected WIDTHxHEIGHT.");
        }

        return [(int) $matches[1], (int) $matches[2]];
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

        throw HttpErrorNormalizer::normalize(
            $this->provider(),
            $response->getStatusCode(),
            is_array($decoded) ? $decoded : $body,
            modelId: $this->modelId,
        );
    }

}
