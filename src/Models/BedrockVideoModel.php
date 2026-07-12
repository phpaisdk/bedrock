<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Models;

use AiSdk\Bedrock\Auth\BedrockAuth;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\ContentSource;
use AiSdk\Contracts\BaseModel;
use AiSdk\Contracts\VideoModelInterface;
use AiSdk\Exceptions\APIConnectionException;
use AiSdk\Exceptions\InvalidArgumentException;
use AiSdk\Exceptions\InvalidResponseException;
use AiSdk\Generate;
use AiSdk\Requests\VideoRequest;
use AiSdk\Responses\VideoJob;
use AiSdk\Responses\VideoJobStatus;
use AiSdk\Results\VideoData;
use AiSdk\Support\Json;
use AiSdk\Support\Usage;
use AiSdk\Utils\Errors\HttpErrorNormalizer;
use AiSdk\Utils\Support\Url;
use Psr\Http\Client\ClientExceptionInterface;

final class BedrockVideoModel extends BaseModel implements VideoModelInterface
{
    public function __construct(private readonly string $modelId, private readonly BedrockOptions $options) {}

    public function provider(): string
    {
        return BedrockOptions::PROVIDER_NAME;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function generate(VideoRequest $r): VideoJob
    {
        if ($r->video !== null) {
            throw new InvalidArgumentException('Amazon Nova Reel does not accept a source video.');
        }
        $o = $r->providerOptionsFor($this->provider());
        $s3 = $o['outputS3Uri'] ?? null;
        if (! is_string($s3) || ! str_starts_with($s3, 's3://')) {
            throw new InvalidArgumentException('Bedrock video generation requires outputS3Uri in bedrock provider options.');
        }$images = [];
        if ($r->image) {
            if ($r->image->source() === ContentSource::Url) {
                $images[] = ['format' => 'png', 'source' => ['s3Location' => ['uri' => $r->image->url()]]];
            } else {
                $images[] = ['format' => str_contains((string) $r->image->mimeType(), 'jpeg') ? 'jpeg' : 'png', 'source' => ['bytes' => $r->image->base64Data()]];
            }
        }$input = ['taskType' => 'TEXT_VIDEO', 'textToVideoParams' => array_filter(['text' => $r->prompt, 'images' => $images ?: null]), 'videoGenerationConfig' => array_filter(['durationSeconds' => (int) ($r->output !== null ? ($r->output->duration ?? 6) : 6), 'fps' => $r->output !== null ? ($r->output->fps ?? 24) : 24, 'dimension' => $r->output !== null ? ($r->output->resolution ?? '1280x720') : '1280x720', 'seed' => $r->output?->seed], fn($v) => $v !== null)];
        if (is_array($o['raw'] ?? null)) {
            $input = array_replace_recursive($input, $o['raw']);
        }$p = $this->request('POST', '/async-invoke', ['modelId' => $this->modelId, 'modelInput' => $input, 'outputDataConfig' => ['s3OutputDataConfig' => ['s3Uri' => $s3]]]);
        $id = $p['invocationArn'] ?? null;
        if (! is_string($id) || $id === '') {
            throw InvalidResponseException::forProvider($this->provider(), 'Bedrock returned no invocation ARN.', ['body' => $p]);
        }

        return new VideoJob($id, $this->provider(), $this->modelId, rawResponse: $p, providerMetadata: [$this->provider() => ['invocationArn' => $id, 'outputS3Uri' => $s3, 'pollIntervalMs' => (int) ($o['pollIntervalMs'] ?? 15000), 'pollTimeoutMs' => (int) ($o['pollTimeoutMs'] ?? 1200000)]]);
    }

    public function poll(VideoJob $j): VideoJob
    {
        $p = $this->request('GET', '/async-invoke/' . rawurlencode($j->id));
        $s = (string) ($p['status'] ?? 'InProgress');
        if ($s === 'Completed') {
            $base = rtrim((string) ($p['outputDataConfig']['s3OutputDataConfig']['s3Uri'] ?? $j->providerMetadata[$this->provider()]['outputS3Uri']), '/');

            return new VideoJob($j->id, $j->provider, $j->modelId, VideoJobStatus::Succeeded, new VideoData(url: $base . '/output.mp4', resolution: '1280x720'), usage: Usage::empty(), rawResponse: $p, providerMetadata: $j->providerMetadata);
        }

        return new VideoJob($j->id, $j->provider, $j->modelId, $s === 'Failed' ? VideoJobStatus::Failed : VideoJobStatus::Running, errorMessage: $s === 'Failed' ? (string) ($p['failureMessage'] ?? 'Bedrock video generation failed.') : null, rawResponse: $p, providerMetadata: $j->providerMetadata);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $sdk = $this->options->sdk ?? Generate::sdk();
        $req = $sdk->requestFactory->createRequest($method, Url::joinPath($this->options->baseUrl, $path))->withHeader('Accept', 'application/json')->withHeader('User-Agent', $sdk->userAgent);
        if ($method !== 'GET') {
            $req = $req->withBody($sdk->streamFactory->createStream(Json::encode($body)))->withHeader('Content-Type', 'application/json');
        }foreach ($this->options->headers as $n => $v) {
            $req = $req->withHeader($n, $v);
        }$req = (new BedrockAuth($this->options))->signRequest($req);

        try {
            $res = $sdk->httpClient->sendRequest($req);
        } catch (ClientExceptionInterface $e) {
            throw new APIConnectionException(message: 'Bedrock video transport error: ' . $e->getMessage(), previous: $e);
        }if ($res->getStatusCode() < 200 || $res->getStatusCode() >= 300) {
            throw HttpErrorNormalizer::normalize($this->provider(), $res->getStatusCode(), (string) $res->getBody(), modelId: $this->modelId);
        }

        return Json::decode((string) $res->getBody(), $this->provider());
    }
}
