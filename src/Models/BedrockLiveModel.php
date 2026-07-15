<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Models;

use AiSdk\Bedrock\Auth\BedrockAuth;
use AiSdk\Bedrock\Auth\StreamingSigV4Signer;
use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Bedrock\Live\BedrockLiveSession;
use AiSdk\Contracts\BaseModel;
use AiSdk\Exceptions\UnsupportedLiveActionException;
use AiSdk\Exceptions\UnsupportedTransportException;
use AiSdk\Live\Contracts\LiveModelInterface;
use AiSdk\Live\Contracts\LiveSessionDriverInterface;
use AiSdk\Live\Contracts\TransportInterface;
use AiSdk\Live\Http2Endpoint;
use AiSdk\Live\LiveOperation;
use AiSdk\Live\LiveRequest;
use AiSdk\Utils\Support\Url;

final class BedrockLiveModel extends BaseModel implements LiveModelInterface
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

    public function createLiveSession(LiveRequest $request, TransportInterface $transport): LiveSessionDriverInterface
    {
        if ($request->operation !== LiveOperation::Voice) {
            throw UnsupportedLiveActionException::for($this->provider(), $request->operation, 'connect');
        }

        $url = Url::joinPath(
            $this->options->baseUrlConfigured
                ? $this->options->baseUrl
                : BedrockOptions::defaultRuntimeUrl($this->options->region),
            '/model/' . rawurlencode($this->modelId) . '/invoke-with-bidirectional-stream',
        );
        $credentials = (new BedrockAuth($this->options))->streamingCredentials();
        $signing = (new StreamingSigV4Signer(
            accessKeyId: $credentials['accessKeyId'],
            secretAccessKey: $credentials['secretAccessKey'],
            sessionToken: $credentials['sessionToken'],
            region: $this->options->region,
        ))->authorize('POST', $url, $this->options->headers);

        $endpoint = new Http2Endpoint($url, $signing->headers);
        if (! $transport->supports($endpoint)) {
            throw UnsupportedTransportException::for($endpoint);
        }

        return BedrockLiveSession::start(
            connection: $transport->connect($endpoint),
            signing: $signing,
            request: $request,
            modelId: $this->modelId,
        );
    }
}
