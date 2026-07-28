<?php

declare(strict_types=1);

namespace Navimow;

use UnexpectedValueException;

final class MqttCredentialMapper
{
    private const MAX_ENDPOINT_BYTES = 4096;
    private const MAX_HOST_BYTES = 253;
    private const MAX_PATH_BYTES = 1024;
    private const MAX_QUERY_BYTES = 2048;
    private const MAX_USERNAME_BYTES = 512;
    private const MAX_PASSWORD_BYTES = 2048;

    public static function map(array $payload): array
    {
        if (($payload['code'] ?? null) !== 1) {
            throw new UnexpectedValueException(
                'MQTT credential endpoint returned a business error.'
            );
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new UnexpectedValueException(
                'MQTT credential response data is invalid.'
            );
        }

        $mqttHost = self::requiredString(
            $data,
            'mqttHost',
            self::MAX_ENDPOINT_BYTES
        );
        $mqttUrl = self::requiredString(
            $data,
            'mqttUrl',
            self::MAX_ENDPOINT_BYTES
        );
        $username = self::requiredString(
            $data,
            'userName',
            self::MAX_USERNAME_BYTES
        );
        $password = self::requiredString(
            $data,
            'pwdInfo',
            self::MAX_PASSWORD_BYTES
        );

        return [
            'wssUrl' => self::createWssUrl($mqttHost, $mqttUrl),
            'mqttUsername' => $username,
            'mqttPassword' => $password,
        ];
    }

    private static function requiredString(
        array $data,
        string $field,
        int $maximumBytes
    ): string {
        $value = $data[$field] ?? null;
        if (
            !is_string($value)
            || $value === ''
            || strlen($value) > $maximumBytes
            || preg_match('/[[:cntrl:]]/', $value) === 1
        ) {
            throw new UnexpectedValueException(
                'MQTT credential response contains an invalid required field.'
            );
        }

        return $value;
    }

    private static function createWssUrl(
        string $mqttHost,
        string $mqttUrl
    ): string {
        $urlParts = parse_url($mqttUrl);
        if (!is_array($urlParts)) {
            throw new UnexpectedValueException(
                'MQTT WSS endpoint is invalid.'
            );
        }

        if (isset($urlParts['scheme'])) {
            $candidate = $mqttUrl;
        } else {
            if (str_starts_with($mqttUrl, '//')) {
                throw new UnexpectedValueException(
                    'MQTT WSS endpoint is invalid.'
                );
            }
            $hostCandidate = str_contains($mqttHost, '://')
                ? $mqttHost
                : 'wss://' . $mqttHost;
            $hostParts = parse_url($hostCandidate);
            if (
                !is_array($hostParts)
                || isset($hostParts['user'])
                || isset($hostParts['pass'])
                || isset($hostParts['query'])
                || isset($hostParts['fragment'])
                || !in_array(
                    $hostParts['path'] ?? '',
                    ['', '/'],
                    true
                )
            ) {
                throw new UnexpectedValueException(
                    'MQTT WSS endpoint is invalid.'
                );
            }
            $path = str_starts_with($mqttUrl, '/')
                ? $mqttUrl
                : '/' . $mqttUrl;
            $candidate = sprintf(
                '%s://%s%s%s',
                $hostParts['scheme'] ?? '',
                $hostParts['host'] ?? '',
                isset($hostParts['port'])
                    ? ':' . $hostParts['port']
                    : '',
                $path
            );
        }

        return self::validateWssUrl($candidate);
    }

    private static function validateWssUrl(string $candidate): string
    {
        $parts = parse_url($candidate);
        $host = is_array($parts)
            ? ($parts['host'] ?? null)
            : null;
        $path = is_array($parts)
            ? ($parts['path'] ?? '')
            : '';
        $query = is_array($parts)
            ? ($parts['query'] ?? '')
            : '';
        if (
            strlen($candidate) > self::MAX_ENDPOINT_BYTES
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'wss'
            || !is_string($host)
            || $host === ''
            || strlen($host) > self::MAX_HOST_BYTES
            || preg_match('/^[A-Za-z0-9.-]+$/D', $host) !== 1
            || (($parts['port'] ?? 443) !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !str_starts_with($path, '/')
            || strlen($path) > self::MAX_PATH_BYTES
            || strlen($query) > self::MAX_QUERY_BYTES
            || preg_match('/[[:cntrl:]]/', $candidate) === 1
        ) {
            throw new UnexpectedValueException(
                'MQTT WSS endpoint is invalid.'
            );
        }

        return $candidate;
    }
}
