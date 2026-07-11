<?php

declare(strict_types=1);

namespace AiSdk\Bedrock\Auth;

use AiSdk\Bedrock\BedrockOptions;
use AiSdk\Exceptions\InvalidArgumentException;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Resolves Amazon Bedrock authentication.
 *
 * Precedence:
 *  1. Bearer token (Bedrock API key) -> Authorization: Bearer header, no signing.
 *  2. SigV4 signing:
 *     - explicit static access keys, or
 *     - a named profile (SSO + INI), or
 *     - the default AWS credential chain (env, INI, SSO, IMDS, ECS,
 *       assume-role, web-identity) when aws/aws-sdk-php is installed.
 *
 * When aws/aws-sdk-php is unavailable, only bearer tokens and explicit static
 * keys (via the built-in fallback signer) are supported.
 */
final class BedrockAuth
{
    private const SERVICE = 'bedrock';

    /** @var (callable(): PromiseInterface)|null */
    private $credentialProvider = null;

    public function __construct(private readonly BedrockOptions $options) {}

    public function usesBearer(): bool
    {
        return $this->options->bearerToken !== null;
    }

    public function signRequest(RequestInterface $request): RequestInterface
    {
        if ($this->usesBearer()) {
            return $request->withHeader('Authorization', 'Bearer ' . $this->options->bearerToken);
        }

        if (class_exists(SignatureV4::class)) {
            return $this->signWithAwsSdk($request);
        }

        return $this->signWithFallback($request);
    }

    private function signWithAwsSdk(RequestInterface $request): RequestInterface
    {
        $credentials = $this->resolveAwsCredentials();
        $signer = new SignatureV4(self::SERVICE, $this->options->region);

        return $signer->signRequest($request, $credentials);
    }

    private function resolveAwsCredentials(): CredentialsInterface
    {
        if ($this->options->accessKeyId !== null && $this->options->secretAccessKey !== null) {
            return new Credentials(
                $this->options->accessKeyId,
                $this->options->secretAccessKey,
                $this->options->sessionToken,
            );
        }

        if ($this->credentialProvider === null) {
            if ($this->options->profile !== null) {
                $this->credentialProvider = CredentialProvider::memoize(
                    CredentialProvider::chain(
                        CredentialProvider::sso($this->options->profile),
                        CredentialProvider::ini($this->options->profile),
                    ),
                );
            } else {
                $this->credentialProvider = CredentialProvider::defaultProvider();
            }
        }

        $promise = ($this->credentialProvider)();
        assert($promise instanceof PromiseInterface);
        $credentials = $promise->wait();
        assert($credentials instanceof CredentialsInterface);

        return $credentials;
    }

    private function signWithFallback(RequestInterface $request): RequestInterface
    {
        if ($this->options->accessKeyId === null || $this->options->secretAccessKey === null) {
            throw new InvalidArgumentException(
                'Bedrock SigV4 with profiles or the default credential chain requires the aws/aws-sdk-php package. '
                . 'Install it with: composer require aws/aws-sdk-php — or provide accessKeyId/secretAccessKey, or a Bedrock API key.',
                ['provider' => BedrockOptions::PROVIDER_NAME],
            );
        }

        $signer = new SigV4Signer(
            $this->options->accessKeyId,
            $this->options->secretAccessKey,
            $this->options->sessionToken,
            $this->options->region,
        );

        return $signer->sign($request);
    }
}
