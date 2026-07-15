# aisdk/bedrock

<a href="https://github.com/phpaisdk/bedrock/actions"><img alt="GitHub Workflow Status" src="https://img.shields.io/github/actions/workflow/status/phpaisdk/bedrock/tests.yml?branch=main&label=Tests"></a>
<a href="https://packagist.org/packages/aisdk/bedrock"><img alt="Total Downloads" src="https://img.shields.io/packagist/dt/aisdk/bedrock"></a>
<a href="https://packagist.org/packages/aisdk/bedrock"><img alt="Latest Version" src="https://img.shields.io/packagist/v/aisdk/bedrock"></a>
<a href="https://packagist.org/packages/aisdk/bedrock"><img alt="License" src="https://img.shields.io/packagist/l/aisdk/bedrock"></a>
<a href="https://whyphp.dev"><img src="https://img.shields.io/badge/Why_PHP-in_2026-7A86E8?style=flat-square&labelColor=18181b" alt="Why PHP in 2026"></a>

------

Official Amazon Bedrock provider for the framework-agnostic PHP AI SDK. Anthropic models use native **InvokeModel** by default, other text models use **Converse**, and images and embeddings use **InvokeModel**. Bedrock's OpenAI-compatible Chat Completions and Responses surfaces are also available.

## Installation

```bash
composer require aisdk/bedrock
```

## Basic Usage

```php
use AiSdk\Bedrock;
use AiSdk\Generate;

$result = Generate::text()
    ->model(Bedrock::model('anthropic.claude-3-5-sonnet-20240620-v1:0'))
    ->prompt('Explain closures in PHP.')
    ->run();

echo $result->text;
```

Bedrock model IDs pass through unchanged and do not need to be registered. This package does not ship a model inventory; the SDK performs internal adapter validation before Bedrock validates support for the selected model in the current account and region.

## API surfaces

Anthropic model IDs automatically use the native Messages format through `InvokeModel`. You can override that choice per request:

```php
$result = Generate::text('Explain this code.')
    ->model(Bedrock::model('anthropic.claude-3-5-haiku-20241022-v1:0'))
    ->providerOptions('amazon-bedrock', ['api' => 'converse'])
    ->run();
```

Supported values are `converse`, `invoke`, `mantle_chat`, and `mantle_responses`. Mantle selections automatically use the regional `bedrock-mantle.{region}.api.aws/v1` endpoint unless you configured a custom base URL. You can also set `api` in `Bedrock::create()` to choose one surface for that provider instance.

## Streaming

```php
foreach (Generate::text('Tell me a story.')->model(Bedrock::model('anthropic.claude-3-haiku-20240307-v1:0'))->stream()->chunks() as $chunk) {
    echo $chunk;
}
```

## Image generation

```php
$image = Generate::image('A studio product photograph')
    ->model(Bedrock::model('amazon.nova-canvas-v1:0'))
    ->aspectRatio('16:9')
    ->run();
```

## Embeddings

Amazon Titan Text Embeddings V1/V2 and Cohere Embed v3/v4 use their native Bedrock request and response formats:

```php
$embedding = Generate::embedding('A document to index')
    ->model(Bedrock::model('cohere.embed-v4:0'))
    ->dimensions(512)
    ->providerOptions('amazon-bedrock', [
        'input_type' => 'search_document',
    ])
    ->run();

$vector = $embedding->output->vector;
```

Cohere requires an `input_type`: `search_document`, `search_query`, `classification`, or `clustering`. Cohere v4 supports 256, 512, 1024, or 1536 dimensions; Cohere v3 has a fixed output size.

Titan V2 supports 256, 512, or 1024 dimensions and its `normalize` option can be passed through `providerOptions()`. Titan V1 has a fixed output size. Bedrock accepts one Titan text per invocation, so the SDK invokes the model once per input when you pass a list.

Other Bedrock embedding model families are rejected because their native wire formats are not interchangeable.

## Video generation

Amazon Nova Reel writes generated videos to your S3 bucket.

```php
$result = Generate::video('A cinematic forest flyover')
    ->model(Bedrock::model('amazon.nova-reel-v1:1'))
    ->resolution('1280x720')
    ->duration(6)
    ->providerOptions('amazon-bedrock', ['outputS3Uri' => 's3://my-video-bucket/outputs'])
    ->run(timeout: 1200);
```

## Live voice with Nova 2 Sonic

Nova Sonic uses Bedrock's full-duplex `InvokeModelWithBidirectionalStream`
operation. Install the ready-made transport package for HTTP/2:

```bash
composer require aisdk/transport
```

```php
use AiSdk\Bedrock;
use AiSdk\Live;
use AiSdk\Live\AudioDelta;
use AiSdk\Live\TranscriptDelta;
use AiSdk\Transport;

Bedrock::create([
    'accessKeyId' => getenv('AWS_ACCESS_KEY_ID'),
    'secretAccessKey' => getenv('AWS_SECRET_ACCESS_KEY'),
    'sessionToken' => getenv('AWS_SESSION_TOKEN') ?: null,
    'region' => 'us-east-1',
]);

$session = Live::voice()
    ->model(Bedrock::model('amazon.nova-2-sonic-v1:0'))
    ->instructions('Be concise and helpful.')
    ->voice('matthew')
    ->inputAudioFormat('pcm16')
    ->outputAudioFormat('pcm16')
    ->connect(Transport::auto());

$session->sendAudio($pcm16Chunk);

foreach ($session->events() as $event) {
    if ($event instanceof AudioDelta) {
        playPcm16($event->bytes);
    }

    if ($event instanceof TranscriptDelta) {
        echo $event->delta;
    }
}

$session->close();
```

The input is signed 16-bit little-endian linear PCM. Input sample rate defaults
to 16 kHz and output defaults to 24 kHz. Provider-specific Nova settings can be
overridden without expanding the shared core API:

```php
$session = Live::voice()
    ->model(Bedrock::model('amazon.nova-2-sonic-v1:0'))
    ->turnDetection('medium')
    ->providerOptions('amazon-bedrock', [
        'inferenceConfiguration' => [
            'maxTokens' => 2048,
            'temperature' => 0.7,
            'topP' => 0.9,
        ],
        'turnDetectionConfiguration' => [
            'endpointingSensitivity' => 'MEDIUM',
        ],
        'inputAudioConfiguration' => [
            'sampleRateHertz' => 16000,
        ],
        'outputAudioConfiguration' => [
            'sampleRateHertz' => 24000,
        ],
    ])
    ->connect(Transport::http2());
```

`AiSdk\Live` and its transport contracts are provided by `aisdk/core`, so the
ready-made transport is optional. Without `aisdk/transport`, pass an
application transport that supports core's `Http2Endpoint`:

```php
use App\Ai\AppHttp2Transport;

$session = Live::voice()
    ->model(Bedrock::model('amazon.nova-2-sonic-v1:0'))
    ->connect(new AppHttp2Transport());
```

The custom transport only moves full-duplex HTTP/2 byte frames and implements
half-closing. AWS signing, EventStream framing, and Nova event semantics remain
inside `aisdk/bedrock`.

`sendText()` provides Nova's cross-modal text input. Tools registered through
`->tools()` are sent in `promptStart`; tool calls are normalized by core and
their results are returned with Nova's required tool event sequence.

Nova Sonic continuously applies server turn detection, so it does not expose
manual `commitAudio()`, `clearAudio()`, `requestResponse()`, or
`cancelResponse()` actions. Bedrock also does not expose dedicated
`Live::transcribe()` or `Live::translate()` sessions; voice sessions still emit
normalized transcription events.

The legacy `amazon.nova-sonic-v1:0` model remains protocol-compatible for
audio sessions, but it does not support Nova 2's cross-modal `sendText()` or
configurable endpointing sensitivity.

Bedrock bearer API keys cannot authenticate bidirectional streaming. Live uses
standard AWS credentials with SigV4. Explicit keys work without the AWS SDK;
profiles and the default credential chain require `aws/aws-sdk-php`.

## Authentication

Bedrock supports the full range of AWS authentication:

- **Bedrock API key** (bearer token) — no signing, no AWS SDK needed.
- **Explicit static access keys** — SigV4 signing (works standalone).
- **Named profile** — SSO + shared config/credentials files.
- **Default AWS credential chain** — env vars, shared config, SSO, IMDS
  (EC2), ECS container credentials, assume-role, and web-identity.

Profiles and the default chain use the official [`aws/aws-sdk-php`](https://github.com/aws/aws-sdk-php)
credential providers. Install it to enable them:

```bash
composer require aws/aws-sdk-php
```

Bearer tokens and explicit static keys work without it for ordinary Bedrock
requests. Live voice specifically requires SigV4 credentials and does not
support bearer tokens.

| Variable | Description |
|---|---|
| `AWS_BEARER_TOKEN_BEDROCK` | Bedrock API key (bearer token) |
| `AWS_ACCESS_KEY_ID` | AWS access key (SigV4) |
| `AWS_SECRET_ACCESS_KEY` | AWS secret key (SigV4) |
| `AWS_SESSION_TOKEN` | Optional session token (SigV4) |
| `AWS_PROFILE` | Named profile for SSO / shared config |
| `AWS_REGION` / `AWS_DEFAULT_REGION` | Region (defaults to `us-east-1`) |

```php
// Bedrock API key
Bedrock::create(['apiKey' => 'bedrock-...', 'region' => 'us-east-1']);

// Explicit static credentials (SigV4)
Bedrock::create([
    'accessKeyId' => 'AKIA...',
    'secretAccessKey' => '...',
    'region' => 'us-east-1',
]);

// Named profile (SSO / shared config)
Bedrock::create(['profile' => 'my-sso-profile', 'region' => 'us-east-1']);

// Default credential chain (env, config, SSO, IMDS, ECS, assume-role, web-identity)
Bedrock::create(['region' => 'us-east-1']);
```

## Reasoning

```php
use AiSdk\Reasoning;

$result = Generate::text('Explain the tradeoff.')
    ->model(Bedrock::model('anthropic.claude-3-7-sonnet-20250219-v1:0'))
    ->reasoning(Reasoning::budget(2048))
    ->run();
```

## Testing

```bash
composer test
```

The default suite is fixture- and conformance-based. Credentialed Live network
verification is separate and is not run by `composer test`.

## Links

- [Cohere Embed v4 on Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-embed-v4.html)
- [Cohere Embed v3 on Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-embed-v3.html)
- [Amazon Titan Text Embeddings](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-titan-embed-text.html)
- [Bedrock bidirectional streaming API](https://docs.aws.amazon.com/bedrock/latest/APIReference/API_runtime_InvokeModelWithBidirectionalStream.html)
- [Nova Sonic input events](https://docs.aws.amazon.com/nova/latest/nova2-userguide/sonic-input-events.html)
- [Nova Sonic output events](https://docs.aws.amazon.com/nova/latest/nova2-userguide/sonic-output-events.html)
- [Core Package](https://github.com/phpaisdk/core)
