# aisdk/bedrock

Official Amazon Bedrock provider for the framework-agnostic PHP AI SDK. Anthropic models use native **InvokeModel** by default, other text models use **Converse**, and images use **InvokeModel**. Bedrock's OpenAI-compatible Chat Completions and Responses surfaces are also available.

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
    ->model(Bedrock::image('amazon.nova-canvas-v1:0'))
    ->aspectRatio('16:9')
    ->run();
```

Bedrock speech is intentionally not exposed through `Generate::speech()`: Nova Sonic uses a bidirectional streaming API and belongs in the future realtime package rather than the synchronous speech contract.

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

Bearer tokens and explicit static keys work without it.

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
