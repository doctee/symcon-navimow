<?php

declare(strict_types=1);

namespace Navimow;

use UnexpectedValueException;

final class MqttTransportConfiguration
{
    private const MAX_DEVICES = 64;
    private const CHANNELS = [
        'attributes',
        'event',
        'location',
        'state',
    ];

    public static function createSubscriptions(array $devices): array
    {
        if ($devices === [] || count($devices) > self::MAX_DEVICES) {
            throw new UnexpectedValueException(
                'MQTT discovery set is empty or oversized.'
            );
        }

        $topics = [];
        foreach ($devices as $device) {
            $deviceId = is_array($device)
                ? ($device['id'] ?? null)
                : null;
            if (
                !is_string($deviceId)
                || $deviceId === ''
                || strlen($deviceId) > 128
                || strpbrk($deviceId, '/#+') !== false
                || preg_match('/[[:cntrl:]]/', $deviceId) === 1
            ) {
                throw new UnexpectedValueException(
                    'MQTT discovery contains an invalid device identity.'
                );
            }
            foreach (self::CHANNELS as $channel) {
                $topic = sprintf(
                    '/downlink/vehicle/%s/realtimeDate/%s',
                    $deviceId,
                    $channel
                );
                $topics[$topic] = [
                    'Topic' => $topic,
                    'QualityOfService' => 0,
                ];
            }
        }

        ksort($topics);

        return array_values($topics);
    }

    public static function configuredSubscriptions(
        array $mqttConfiguration
    ): array {
        $subscriptions = $mqttConfiguration['Subscriptions'] ?? null;
        if (is_string($subscriptions)) {
            $subscriptions = json_decode($subscriptions, true, 16);
        }
        if (!is_array($subscriptions) || !array_is_list($subscriptions)) {
            throw new UnexpectedValueException(
                'MQTT subscriptions are malformed.'
            );
        }

        $normalized = [];
        foreach ($subscriptions as $subscription) {
            $topic = is_array($subscription)
                ? ($subscription['Topic'] ?? null)
                : null;
            $qualityOfService = is_array($subscription)
                ? ($subscription['QualityOfService'] ?? null)
                : null;
            if (
                !is_string($topic)
                || $topic === ''
                || strlen($topic) > 512
                || strpbrk($topic, '#+') !== false
                || $qualityOfService !== 0
            ) {
                throw new UnexpectedValueException(
                    'MQTT subscriptions violate the exact-topic contract.'
                );
            }
            if (isset($normalized[$topic])) {
                throw new UnexpectedValueException(
                    'MQTT subscriptions contain a duplicate.'
                );
            }
            $normalized[$topic] = [
                'Topic' => $topic,
                'QualityOfService' => 0,
            ];
        }
        ksort($normalized);

        return array_values($normalized);
    }

    public static function assertSubscriptionsMatch(
        array $configured,
        array $expected
    ): void {
        if ($configured !== $expected) {
            throw new UnexpectedValueException(
                'MQTT subscriptions do not match current discovery.'
            );
        }
    }

    public static function subscriptionShapeHash(
        array $subscriptions
    ): string {
        $topicCount = count($subscriptions);
        if ($topicCount === 0 || $topicCount % count(self::CHANNELS) !== 0) {
            throw new UnexpectedValueException(
                'MQTT subscription shape is invalid.'
            );
        }

        return self::hashCanonical([
            'formatVersion' => 1,
            'deviceCount' => intdiv(
                $topicCount,
                count(self::CHANNELS)
            ),
            'channels' => self::CHANNELS,
            'qualityOfService' => 0,
            'topicCount' => $topicCount,
        ]);
    }

    public static function transportShapeHash(
        array $mqttConfiguration,
        array $webSocketConfiguration,
        array $subscriptions,
        string $expectedClientId
    ): string {
        $url = is_string($webSocketConfiguration['URL'] ?? null)
            ? $webSocketConfiguration['URL']
            : '';
        $urlParts = $url === '' ? [] : parse_url($url);
        if (!is_array($urlParts)) {
            $urlParts = [];
        }
        $headers = self::headerShape(
            $webSocketConfiguration['Headers'] ?? ''
        );

        return self::hashCanonical([
            'formatVersion' => 1,
            'webSocket' => [
                'active' => ($webSocketConfiguration['Active'] ?? false)
                    === true,
                'typeBinary' => ($webSocketConfiguration['Type'] ?? null)
                    === 1,
                'verifyCertificate' => (
                    $webSocketConfiguration['VerifyCertificate'] ?? null
                ) === true,
                'schemeWss' => strtolower(
                    (string) ($urlParts['scheme'] ?? '')
                ) === 'wss',
                'port443' => ($urlParts['port'] ?? 443) === 443,
                'pathPresent' => (
                    (string) ($urlParts['path'] ?? '')
                ) !== '',
                'queryPresent' => (
                    (string) ($urlParts['query'] ?? '')
                ) !== '',
                'authorizationHeader' => $headers[
                    'authorizationHeader'
                ],
                'bearerValuePresent' => $headers[
                    'bearerValuePresent'
                ],
            ],
            'mqtt' => [
                'usernamePresent' => self::nonEmptyString(
                    $mqttConfiguration['UserName'] ?? null
                ),
                'passwordPresent' => self::nonEmptyString(
                    $mqttConfiguration['Password'] ?? null
                ),
                'clientIdMatches' => (
                    $mqttConfiguration['ClientID'] ?? null
                ) === $expectedClientId,
                'keepAlive' => is_int(
                    $mqttConfiguration['KeepAliveInterval'] ?? null
                )
                    ? $mqttConfiguration['KeepAliveInterval']
                    : 0,
            ],
            'subscriptionShapeHash' => self::subscriptionShapeHash(
                $subscriptions
            ),
        ]);
    }

    public static function assertAdoptionCandidate(
        array $mqttConfiguration,
        array $webSocketConfiguration
    ): void {
        if (($webSocketConfiguration['Active'] ?? false) === true) {
            throw new UnexpectedValueException(
                'MQTT adoption requires an inactive WebSocket.'
            );
        }
        if (
            !self::headersAreEmpty(
                $webSocketConfiguration['Headers'] ?? null
            )
            || self::nonEmptyString(
                $mqttConfiguration['UserName'] ?? null
            )
            || self::nonEmptyString(
                $mqttConfiguration['Password'] ?? null
            )
        ) {
            throw new UnexpectedValueException(
                'MQTT adoption requires empty credential properties.'
            );
        }
    }

    public static function assertInactiveConnectedShape(
        array $mqttConfiguration,
        array $webSocketConfiguration,
        array $expectedSubscriptions,
        string $expectedClientId
    ): void {
        self::assertSubscriptionsMatch(
            self::configuredSubscriptions($mqttConfiguration),
            $expectedSubscriptions
        );
        $url = $webSocketConfiguration['URL'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $headers = self::headerShape(
            $webSocketConfiguration['Headers'] ?? ''
        );
        if (
            ($webSocketConfiguration['Active'] ?? true) !== false
            || ($webSocketConfiguration['Type'] ?? null) !== 1
            || (
                $webSocketConfiguration['VerifyCertificate'] ?? null
            ) !== true
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'wss'
            || ($parts['port'] ?? 443) !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !$headers['authorizationHeader']
            || !$headers['bearerValuePresent']
            || !self::nonEmptyString(
                $mqttConfiguration['UserName'] ?? null
            )
            || !self::nonEmptyString(
                $mqttConfiguration['Password'] ?? null
            )
            || ($mqttConfiguration['ClientID'] ?? null)
                !== $expectedClientId
            || ($mqttConfiguration['KeepAliveInterval'] ?? null) !== 60
        ) {
            throw new UnexpectedValueException(
                'MQTT inactive transport shape is invalid.'
            );
        }
    }

    public static function clientId(string $identity): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $identity) !== 1) {
            throw new UnexpectedValueException(
                'MQTT local client identity is invalid.'
            );
        }

        return 'symcon_navimow_' . substr($identity, 0, 24);
    }

    public static function authorizationHeaders(
        string $accessToken
    ): string {
        if (
            $accessToken === ''
            || strlen($accessToken) > 8192
            || preg_match('/[[:cntrl:]]/', $accessToken) === 1
        ) {
            throw new UnexpectedValueException(
                'MQTT bearer token is invalid.'
            );
        }

        return json_encode([[
            'Name' => 'Authorization',
            'Value' => 'Bearer ' . $accessToken,
        ]], JSON_THROW_ON_ERROR);
    }

    private static function headerShape(mixed $headers): array
    {
        if (is_string($headers)) {
            $headers = $headers === ''
                ? []
                : json_decode($headers, true, 8);
        }
        if (!is_array($headers) || !array_is_list($headers)) {
            return [
                'authorizationHeader' => false,
                'bearerValuePresent' => false,
            ];
        }

        $authorizationCount = 0;
        $bearerValuePresent = false;
        foreach ($headers as $header) {
            if (
                is_array($header)
                && strcasecmp(
                    (string) ($header['Name'] ?? ''),
                    'Authorization'
                ) === 0
            ) {
                $authorizationCount++;
                $bearerValuePresent = is_string(
                    $header['Value'] ?? null
                ) && str_starts_with(
                    $header['Value'],
                    'Bearer '
                ) && strlen($header['Value']) > 7;
            }
        }

        return [
            'authorizationHeader' => $authorizationCount === 1,
            'bearerValuePresent' => $authorizationCount === 1
                && $bearerValuePresent,
        ];
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private static function headersAreEmpty(mixed $headers): bool
    {
        if ($headers === '') {
            return true;
        }
        if (is_string($headers)) {
            $headers = json_decode($headers, true, 8);
        }

        return is_array($headers)
            && array_is_list($headers)
            && $headers === [];
    }

    private static function hashCanonical(array $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            )
        );
    }
}
