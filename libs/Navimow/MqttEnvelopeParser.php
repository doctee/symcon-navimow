<?php

declare(strict_types=1);

namespace Navimow;

use JsonException;

final class MqttEnvelopeParser
{
    public const RECEIVE_DATA_ID = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';

    private const MAX_ENVELOPE_BYTES = 65536;
    private const MAX_JSON_DEPTH = 32;
    private const MAX_TOPIC_BYTES = 512;
    private const MAX_PAYLOAD_BYTES = 32768;
    private const REQUIRED_KEYS = [
        'DataID',
        'PacketType',
        'Payload',
        'QualityOfService',
        'Retain',
        'Topic',
    ];

    public static function parse(string $envelopeJson): array
    {
        if (strlen($envelopeJson) > self::MAX_ENVELOPE_BYTES) {
            throw new MqttEnvelopeException(
                'MQTT envelope exceeds the 65,536-byte limit.'
            );
        }

        try {
            $decoded = json_decode(
                $envelopeJson,
                true,
                self::MAX_JSON_DEPTH,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new MqttEnvelopeException(
                'MQTT envelope is not valid UTF-8 JSON.',
                0,
                $exception
            );
        }

        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw new MqttEnvelopeException(
                'MQTT envelope must be a JSON object.'
            );
        }

        $keys = array_keys($decoded);
        sort($keys);
        $requiredKeys = self::REQUIRED_KEYS;
        sort($requiredKeys);
        if ($keys !== $requiredKeys) {
            throw new MqttEnvelopeException(
                'MQTT envelope keys do not match the exact receive contract.'
            );
        }

        if (
            !is_string($decoded['DataID'])
            || !hash_equals(self::RECEIVE_DATA_ID, $decoded['DataID'])
        ) {
            throw new MqttEnvelopeException(
                'MQTT envelope DataID is not the receive interface.'
            );
        }

        $packetType = $decoded['PacketType'];
        if (!is_int($packetType) || $packetType < 0 || $packetType > 15) {
            throw new MqttEnvelopeException(
                'MQTT envelope PacketType is outside the bounded range.'
            );
        }

        if ($decoded['QualityOfService'] !== 0) {
            throw new MqttEnvelopeException(
                'MQTT envelope QualityOfService must be integer zero.'
            );
        }

        if (!is_bool($decoded['Retain'])) {
            throw new MqttEnvelopeException(
                'MQTT envelope Retain flag must be boolean.'
            );
        }

        $topic = $decoded['Topic'];
        if (
            !is_string($topic)
            || $topic === ''
            || strlen($topic) > self::MAX_TOPIC_BYTES
        ) {
            throw new MqttEnvelopeException(
                'MQTT envelope Topic is invalid or oversized.'
            );
        }

        $payload = $decoded['Payload'];
        if (
            !is_string($payload)
            || strlen($payload) > self::MAX_PAYLOAD_BYTES
        ) {
            throw new MqttEnvelopeException(
                'MQTT envelope Payload is not a bounded string.'
            );
        }

        return [
            'topic' => $topic,
            'payload' => $payload,
            'qualityOfService' => 0,
            'retained' => $decoded['Retain'],
            'packetType' => $packetType,
        ];
    }
}
