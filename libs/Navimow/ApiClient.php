<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;
use RuntimeException;

final class ApiClient
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const MAX_RESPONSE_BYTES = 1048576;

    /** @var callable|null */
    private $transport;

    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        ?callable $transport = null
    ) {
        $this->baseUrl = self::validateBaseUrl($baseUrl);
        $this->transport = $transport;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function exchangeAuthorizationCode(
        string $code,
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ): array {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ]);
    }

    public function refreshAccessToken(
        string $refreshToken,
        string $clientId,
        string $clientSecret
    ): array {
        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
    }

    public function getAuthorizedDevices(string $accessToken): array
    {
        return $this->authorizedRequest(
            'GET',
            '/openapi/smarthome/authList',
            $accessToken
        );
    }

    public function getVehicleStatus(string $accessToken, string $deviceId): array
    {
        return $this->authorizedRequest(
            'POST',
            '/openapi/smarthome/getVehicleStatus',
            $accessToken,
            ['devices' => [['id' => $deviceId]]]
        );
    }

    private function tokenRequest(array $fields): array
    {
        return $this->send([
            'method' => 'POST',
            'url' => $this->baseUrl . '/openapi/oauth/getAccessToken',
            'headers' => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            'body' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            'operation' => 'OAuth token request',
        ]);
    }

    private function authorizedRequest(
        string $method,
        string $path,
        string $accessToken,
        ?array $payload = null
    ): array {
        if ($accessToken === '') {
            throw new ApiException('authentication', 'Access token is missing.');
        }

        $requestId = self::createRequestId();
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
            'requestId: ' . $requestId,
        ];

        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ApiException(
                    'payload',
                    'REST request payload could not be encoded.',
                    0,
                    null,
                    $requestId,
                    $exception
                );
            }
        }

        return $this->send([
            'method' => $method,
            'url' => $this->baseUrl . $path,
            'headers' => $headers,
            'body' => $body,
            'operation' => $path,
            'requestId' => $requestId,
        ]);
    }

    private function send(array $request): array
    {
        try {
            $response = $this->transport !== null
                ? ($this->transport)($request)
                : $this->curlTransport($request);
        } catch (ApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ApiException(
                'transport',
                'REST transport failed.',
                0,
                null,
                $request['requestId'] ?? null,
                $exception
            );
        }

        $status = $response['status'] ?? null;
        $body = $response['body'] ?? null;

        if (!is_int($status) || !is_string($body)) {
            throw new ApiException(
                'transport',
                'REST transport returned an invalid response envelope.',
                0,
                null,
                $request['requestId'] ?? null
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new ApiException(
                'http',
                sprintf('REST request failed with HTTP status %d.', $status),
                $status,
                null,
                $request['requestId'] ?? null
            );
        }

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new ApiException(
                'payload',
                'REST response exceeded the size limit.',
                $status,
                null,
                $request['requestId'] ?? null
            );
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiException(
                'payload',
                'REST response was not valid JSON.',
                $status,
                null,
                $request['requestId'] ?? null,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                'payload',
                'REST response JSON was not an object.',
                $status,
                null,
                $request['requestId'] ?? null
            );
        }

        return $decoded;
    }

    private function curlTransport(array $request): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('transport', 'cURL is unavailable.');
        }

        $handle = curl_init($request['url']);
        if ($handle === false) {
            throw new ApiException('transport', 'cURL initialization failed.');
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ];

        if ($request['body'] !== null) {
            $options[CURLOPT_POSTFIELDS] = $request['body'];
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);

        if ($body === false) {
            $errorCode = curl_errno($handle);
            curl_close($handle);

            throw new ApiException(
                'transport',
                sprintf('REST transport failed with cURL error %d.', $errorCode),
                0,
                null,
                $request['requestId'] ?? null
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    private static function validateBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);

        if (
            $baseUrl === ''
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new ApiException(
                'configuration',
                'Base URL must be a credential-free HTTPS origin.'
            );
        }

        return $baseUrl;
    }

    private static function createRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}

final class ApiException extends RuntimeException
{
    public function __construct(
        private readonly string $kind,
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?int $apiCode = null,
        private readonly ?string $requestId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getApiCode(): ?int
    {
        return $this->apiCode;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
