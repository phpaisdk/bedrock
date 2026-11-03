# PHP AI SDK — Amazon Bedrock

Text generation, streaming, tools, and multimodal generation through Amazon Bedrock. Part of the framework-agnostic [PHP AI SDK](https://phpaisdk.com).

## Installation

```bash
composer require aisdk/bedrock
```

Set `AWS_REGION` and `AWS_BEARER_TOKEN_BEDROCK`, or use the standard AWS credential chain. Enable the selected model in your AWS region.

## Usage

```php
use AiSdk\Bedrock;
use AiSdk\Generate;

$result = Generate::text('Explain closures in PHP in one sentence.')
    ->model(Bedrock::model('anthropic.claude-sonnet-5-5'))
    ->run();

echo $result->text;
```

## Documentation

[Read the documentation](https://phpaisdk.com/docs/0.x/providers/bedrock) for configuration, supported features, and more examples.

[Contributing](https://github.com/phpaisdk/.github/blob/main/CONTRIBUTING.md) · [Support](https://github.com/phpaisdk/.github/blob/main/SUPPORT.md)

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
