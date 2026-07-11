<?php

declare(strict_types=1);

namespace AiSdk\Bedrock;

use AiSdk\Support\Sdk;
use AiSdk\Utils\Support\Env;
use AiSdk\Utils\Support\Url;

final class BedrockOptions
{
    public const string DEFAULT_REGION = 'us-east-1';

    public const string PROVIDER_NAME = 'amazon-bedrock';

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ?string $bearerToken,
        public readonly ?string $accessKeyId,
        public readonly ?string $secretAccessKey,
        public readonly ?string $sessionToken,
        public readonly ?string $profile,
        public readonly string $region,
        public readonly string $baseUrl,
        public readonly array $headers = [],
        public readonly ?Sdk $sdk = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config = []): self
    {
        $region = self::resolveRegion($config);

        $rawBearer = isset($config['apiKey'])
            ? (string) $config['apiKey']
            : Env::loadOptionalSetting(null, 'AWS_BEARER_TOKEN_BEDROCK');
        $bearer = is_string($rawBearer) && trim($rawBearer) !== '' ? trim($rawBearer) : null;

        $accessKey = isset($config['accessKeyId'])
            ? (string) $config['accessKeyId']
            : Env::loadOptionalSetting(null, 'AWS_ACCESS_KEY_ID');
        $secretKey = isset($config['secretAccessKey'])
            ? (string) $config['secretAccessKey']
            : Env::loadOptionalSetting(null, 'AWS_SECRET_ACCESS_KEY');
        $session = isset($config['sessionToken'])
            ? (string) $config['sessionToken']
            : Env::loadOptionalSetting(null, 'AWS_SESSION_TOKEN');

        $accessKey = ($accessKey !== null && $accessKey !== '') ? $accessKey : null;
        $secretKey = ($secretKey !== null && $secretKey !== '') ? $secretKey : null;
        $session = ($session !== null && $session !== '') ? $session : null;

        $profile = isset($config['profile']) ? (string) $config['profile'] : Env::loadOptionalSetting(null, 'AWS_PROFILE');
        $profile = ($profile !== null && $profile !== '') ? $profile : null;

        // No hard requirement here: credentials may also come from the default
        // AWS credential chain (env, INI, SSO, IMDS, ECS, assume-role,
        // web-identity) resolved by aws/aws-sdk-php at request time.

        $base = Env::loadOptionalSetting(isset($config['baseUrl']) ? (string) $config['baseUrl'] : null, 'AWS_BEDROCK_BASE_URL');
        $baseUrl = Url::withoutTrailingSlash($base ?? self::defaultRuntimeUrl($region));

        /** @var array<string, string> $headers */
        $headers = isset($config['headers']) && is_array($config['headers']) ? $config['headers'] : [];
        $sdk = $config['sdk'] ?? null;

        return new self(
            bearerToken: $bearer,
            accessKeyId: $accessKey,
            secretAccessKey: $secretKey,
            sessionToken: $session,
            profile: $profile,
            region: $region,
            baseUrl: $baseUrl,
            headers: $headers,
            sdk: $sdk instanceof Sdk ? $sdk : null,
        );
    }

    public static function defaultRuntimeUrl(string $region): string
    {
        return 'https://bedrock-runtime.' . rawurlencode($region) . '.amazonaws.com';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function resolveRegion(array $config): string
    {
        if (isset($config['region']) && is_string($config['region']) && $config['region'] !== '') {
            return $config['region'];
        }

        $env = Env::loadOptionalSetting(null, 'AWS_REGION')
            ?? Env::loadOptionalSetting(null, 'AWS_DEFAULT_REGION');

        return ($env !== null && $env !== '') ? $env : self::DEFAULT_REGION;
    }
}
