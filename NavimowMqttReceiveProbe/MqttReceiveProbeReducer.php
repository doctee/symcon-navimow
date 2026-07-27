<?php

declare(strict_types=1);

namespace Navimow\Spike;

use JsonException;

final class MqttReceiveProbeReducer
{
    public const RECEIVE_DATA_ID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    private const CHANNELS = [
        'state',
        'event',
        'attributes',
        'location',
    ];

    private const MAX_ENVELOPE_BYTES = 65536;
    private const MAX_PAYLOAD_BYTES = 32768;
    private const MAX_RECEIVE_CALLS = 128;
    private const MAX_ACCEPTED_MESSAGES = 32;
    private const MAX_SHAPES = 8;
    private const MAX_FIELDS = 64;
    private const MAX_JSON_DEPTH = 32;

    public static function initialState(int $now): array
    {
        return [
            'formatVersion' => 1,
            'probeVersion' => 1,
            'startedAt' => $now,
            'closedAt' => 0,
            'accepting' => true,
            'receiveCallCount' => 0,
            'acceptedMessageCount' => 0,
            'rejectedMessageCount' => 0,
            'oversizedMessageCount' => 0,
            'unknownTopicCount' => 0,
            'channelCounts' => [
                'state' => 0,
                'event' => 0,
                'attributes' => 0,
                'location' => 0,
            ],
            'envelopeShapes' => [],
            'payloadShapes' => [],
            'minimumPayloadBytes' => null,
            'maximumPayloadBytes' => 0,
            'publishAttemptCount' => 0,
            'commandAttemptCount' => 0,
            'limitReached' => false,
            'lastResult' => 'armed',
        ];
    }

    public static function consume(
        array $state,
        string $envelope,
        string $expectedDeviceId,
        int $now
    ): array {
        if (!self::isStateUsable($state) || ($state['accepting'] ?? false) !== true) {
            return $state;
        }

        if (($state['receiveCallCount'] ?? 0) >= self::MAX_RECEIVE_CALLS) {
            return self::closeWithLimit($state, $now);
        }

        $state['receiveCallCount']++;

        if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            $state['oversizedMessageCount']++;
            return self::reject($state, 'oversized-envelope');
        }

        try {
            $decoded = json_decode(
                $envelope,
                true,
                self::MAX_JSON_DEPTH,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return self::reject($state, 'invalid-envelope-json');
        }

        if (!self::isObject($decoded) || count($decoded) > self::MAX_FIELDS) {
            return self::reject($state, 'invalid-envelope-shape');
        }

        if (($decoded['DataID'] ?? null) !== self::RECEIVE_DATA_ID) {
            return self::reject($state, 'unexpected-data-id');
        }

        $topics = self::expectedTopics($expectedDeviceId);
        if ($topics === []) {
            return self::reject($state, 'invalid-device-id');
        }

        $topicMatches = [];
        foreach ($decoded as $key => $value) {
            if (is_string($value) && isset($topics[$value])) {
                $topicMatches[$key] = $value;
            }
        }

        if (count($topicMatches) !== 1) {
            $state['unknownTopicCount']++;
            return self::reject($state, 'unknown-or-ambiguous-topic');
        }

        $topicKey = array_key_first($topicMatches);
        $topic = $topicMatches[$topicKey];
        $channel = $topics[$topic];
        $payloadCandidate = self::findPayloadCandidate(
            $decoded,
            $topicKey,
            $topic
        );
        if ($payloadCandidate === null) {
            return self::reject($state, 'missing-or-ambiguous-payload');
        }

        $payload = $payloadCandidate['payload'];
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $state['oversizedMessageCount']++;
            return self::reject($state, 'oversized-payload');
        }

        $payloadValue = $payloadCandidate['decoded'];
        if (
            $channel === 'state'
            && (
                !self::isObject($payloadValue)
                || ($payloadValue['device_id'] ?? null) !== $expectedDeviceId
            )
        ) {
            return self::reject($state, 'state-device-mismatch');
        }

        $envelopeShape = self::describeObject($decoded);
        $payloadShape = [
            'channel' => $channel,
            'shape' => self::describeJson($payloadValue),
        ];

        $state['envelopeShapes'] = self::appendUniqueShape(
            $state['envelopeShapes'],
            $envelopeShape
        );
        $state['payloadShapes'] = self::appendUniqueShape(
            $state['payloadShapes'],
            $payloadShape
        );

        $payloadBytes = strlen($payload);
        $minimum = $state['minimumPayloadBytes'];
        $state['minimumPayloadBytes'] = $minimum === null
            ? $payloadBytes
            : min($minimum, $payloadBytes);
        $state['maximumPayloadBytes'] = max(
            $state['maximumPayloadBytes'],
            $payloadBytes
        );
        $state['acceptedMessageCount']++;
        $state['channelCounts'][$channel]++;
        $state['lastResult'] = 'accepted-' . $channel;

        if ($state['acceptedMessageCount'] >= self::MAX_ACCEPTED_MESSAGES) {
            return self::closeWithLimit($state, $now);
        }

        return $state;
    }

    public static function close(array $state, int $now): array
    {
        if (!self::isStateUsable($state)) {
            return self::emptyClosedState();
        }

        $state['accepting'] = false;
        if (($state['closedAt'] ?? 0) === 0) {
            $state['closedAt'] = $now;
        }
        if (($state['lastResult'] ?? '') === 'armed') {
            $state['lastResult'] = 'closed-without-message';
        }

        return $state;
    }

    public static function report(array $state): array
    {
        if (!self::isStateUsable($state)) {
            return self::emptyClosedState();
        }

        return $state;
    }

    private static function findPayloadCandidate(
        array $envelope,
        string $topicKey,
        string $topic
    ): ?array {
        $candidates = [];

        foreach ($envelope as $key => $value) {
            if (
                $key === 'DataID'
                || $key === $topicKey
                || !is_string($value)
                || $value === $topic
                || strlen($value) > self::MAX_PAYLOAD_BYTES
            ) {
                continue;
            }

            try {
                $decoded = json_decode(
                    $value,
                    true,
                    self::MAX_JSON_DEPTH,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                continue;
            }

            if (!is_array($decoded)) {
                continue;
            }

            $candidates[] = [
                'payload' => $value,
                'decoded' => $decoded,
            ];
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private static function expectedTopics(string $deviceId): array
    {
        if ($deviceId === '' || strpbrk($deviceId, '/#+') !== false) {
            return [];
        }

        $topics = [];
        foreach (self::CHANNELS as $channel) {
            $topics[
                sprintf(
                    '/downlink/vehicle/%s/realtimeDate/%s',
                    $deviceId,
                    $channel
                )
            ] = $channel;
        }

        return $topics;
    }

    private static function describeObject(array $value): array
    {
        $fields = [];
        foreach ($value as $key => $fieldValue) {
            if (!self::isSafeFieldName((string) $key)) {
                $fields['<rejected-key>'] = 'invalid';
                continue;
            }

            $fields[(string) $key] = self::valueType($fieldValue);
        }
        ksort($fields);

        return [
            'type' => 'object',
            'fields' => $fields,
        ];
    }

    private static function describeJson(mixed $value): array
    {
        if (self::isObject($value)) {
            return self::describeObject($value);
        }

        if (is_array($value)) {
            $itemTypes = [];
            $objectFields = [];
            foreach (array_slice($value, 0, self::MAX_FIELDS) as $item) {
                $itemTypes[self::valueType($item)] = true;
                if (!self::isObject($item)) {
                    continue;
                }

                foreach (self::describeObject($item)['fields'] as $key => $type) {
                    $objectFields[$key][$type] = true;
                }
            }

            $normalizedFields = [];
            foreach ($objectFields as $key => $types) {
                $names = array_keys($types);
                sort($names);
                $normalizedFields[$key] = $names;
            }
            ksort($normalizedFields);

            $types = array_keys($itemTypes);
            sort($types);

            return [
                'type' => 'array',
                'itemTypes' => $types,
                'objectFields' => $normalizedFields,
            ];
        }

        return [
            'type' => self::valueType($value),
        ];
    }

    private static function valueType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => 'unsupported',
        };
    }

    private static function appendUniqueShape(
        array $shapes,
        array $candidate
    ): array {
        $encodedCandidate = json_encode($candidate, JSON_THROW_ON_ERROR);
        foreach ($shapes as $shape) {
            if (json_encode($shape, JSON_THROW_ON_ERROR) === $encodedCandidate) {
                return $shapes;
            }
        }

        if (count($shapes) < self::MAX_SHAPES) {
            $shapes[] = $candidate;
        }

        return $shapes;
    }

    private static function reject(array $state, string $reason): array
    {
        $state['rejectedMessageCount']++;
        $state['lastResult'] = $reason;

        return $state;
    }

    private static function closeWithLimit(array $state, int $now): array
    {
        $state['limitReached'] = true;
        $state['lastResult'] = 'limit-reached';

        return self::close($state, $now);
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && !array_is_list($value);
    }

    private static function isSafeFieldName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) === 1;
    }

    private static function isStateUsable(array $state): bool
    {
        return ($state['formatVersion'] ?? null) === 1
            && isset($state['channelCounts'])
            && is_array($state['channelCounts']);
    }

    private static function emptyClosedState(): array
    {
        $state = self::initialState(0);
        $state['accepting'] = false;
        $state['lastResult'] = 'not-armed';

        return $state;
    }
}
