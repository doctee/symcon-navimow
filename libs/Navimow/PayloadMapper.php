<?php

declare(strict_types=1);

namespace Navimow;

final class PayloadMapper
{
    public const VEHICLE_STATE_UNKNOWN = 0;
    public const VEHICLE_STATE_RUNNING = 1;
    public const VEHICLE_STATE_DOCKED = 2;
    public const VEHICLE_STATE_IDLE = 3;
    public const VEHICLE_STATE_PAUSED = 4;
    public const VEHICLE_STATE_DOCKING = 5;
    public const VEHICLE_STATE_MAPPING = 6;
    public const VEHICLE_STATE_LIFTED = 7;
    public const VEHICLE_STATE_ERROR = 8;
    public const VEHICLE_STATE_SOFTWARE_UPDATE = 9;
    public const VEHICLE_STATE_SELF_CHECKING = 10;
    public const VEHICLE_STATE_OFFLINE = 11;

    public const COMMAND_RESULT_NONE = 0;
    public const COMMAND_RESULT_REQUESTED = 1;
    public const COMMAND_RESULT_ACCEPTED = 2;
    public const COMMAND_RESULT_ALREADY_IN_STATE = 3;
    public const COMMAND_RESULT_PENDING_VERIFICATION = 4;
    public const COMMAND_RESULT_VERIFIED = 5;
    public const COMMAND_RESULT_REJECTED = 6;
    public const COMMAND_RESULT_FAILED = 7;
    public const COMMAND_RESULT_VERIFICATION_TIMEOUT = 8;

    private const VEHICLE_STATE_MAP = [
        'isRunning' => self::VEHICLE_STATE_RUNNING,
        'isDocked' => self::VEHICLE_STATE_DOCKED,
        'isIdle' => self::VEHICLE_STATE_IDLE,
        'isPaused' => self::VEHICLE_STATE_PAUSED,
        'isDocking' => self::VEHICLE_STATE_DOCKING,
        'isMapping' => self::VEHICLE_STATE_MAPPING,
        'isLifted' => self::VEHICLE_STATE_LIFTED,
        'Error' => self::VEHICLE_STATE_ERROR,
        'inSoftwareUpdate' => self::VEHICLE_STATE_SOFTWARE_UPDATE,
        'Self-Checking' => self::VEHICLE_STATE_SELF_CHECKING,
        'Offline' => self::VEHICLE_STATE_OFFLINE,
    ];

    public static function mapTokenResponse(array $payload): array
    {
        return [
            'hasAccessToken' => isset($payload['access_token']) && is_string($payload['access_token']) && $payload['access_token'] !== '',
            'hasRefreshToken' => isset($payload['refresh_token']) && is_string($payload['refresh_token']) && $payload['refresh_token'] !== '',
            'tokenType' => isset($payload['token_type']) && is_string($payload['token_type']) ? $payload['token_type'] : null,
            'expiresIn' => isset($payload['expires_in']) && is_int($payload['expires_in']) ? $payload['expires_in'] : null,
        ];
    }

    public static function parseTokenResponse(array $payload): array
    {
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $tokenType = $payload['token_type'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new \UnexpectedValueException(
                'Token response does not contain a usable access token.'
            );
        }

        if ($refreshToken !== null && !is_string($refreshToken)) {
            throw new \UnexpectedValueException(
                'Token response contains an invalid refresh token.'
            );
        }

        if ($tokenType !== null && (!is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0)) {
            throw new \UnexpectedValueException(
                'Token response contains an unsupported token type.'
            );
        }

        if (!is_int($expiresIn) || $expiresIn <= 0) {
            throw new \UnexpectedValueException(
                'Token response does not contain a valid expiry.'
            );
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            'tokenType' => $tokenType ?? 'Bearer',
            'expiresIn' => $expiresIn,
        ];
    }

    public static function assertApiSuccess(array $payload): void
    {
        $code = $payload['code'] ?? null;
        if ($code === 1) {
            return;
        }

        $error = self::mapApiError($payload);
        throw new \UnexpectedValueException(
            sprintf(
                'Navimow API failed with code %s: %s',
                $error['code'] === null ? 'unknown' : (string) $error['code'],
                self::limitDiagnostic($error['desc'])
            )
        );
    }

    public static function mapDiscovery(array $payload): array
    {
        $devices = self::payloadDevices($payload);

        return array_values(array_filter(array_map(static function (array $device): ?array {
            if (!isset($device['id']) || !is_string($device['id']) || $device['id'] === '') {
                return null;
            }

            return [
                'id' => $device['id'],
                'name' => isset($device['name']) && is_string($device['name']) ? $device['name'] : '',
                'model' => isset($device['model']) && is_string($device['model']) ? $device['model'] : '',
                'firmware' => isset($device['firmware']) && is_string($device['firmware']) ? $device['firmware'] : '',
            ];
        }, $devices)));
    }

    public static function mapStatus(
        array $payload,
        ?string $deviceId = null
    ): array
    {
        $device = self::findDevice($payload, $deviceId);
        $sourceState = isset($device['vehicleState']) && is_string($device['vehicleState'])
            ? $device['vehicleState']
            : null;

        $vehicleState = $sourceState !== null
            ? (self::VEHICLE_STATE_MAP[$sourceState] ?? self::VEHICLE_STATE_UNKNOWN)
            : self::VEHICLE_STATE_UNKNOWN;

        return [
            'vehicleState' => $vehicleState,
            'vehicleStateSource' => $sourceState,
            'batteryLevel' => self::mapBatteryLevel($device),
            'online' => $vehicleState === self::VEHICLE_STATE_OFFLINE ? false : null,
        ];
    }

    public static function mapCommandResult(
        array $payload,
        string $deviceId
    ): array
    {
        $commands = $payload['data']['payload']['commands'] ?? null;
        if (!is_array($commands) || $commands === []) {
            throw new \UnexpectedValueException(
                'Command response does not contain command results.'
            );
        }

        $command = null;
        foreach ($commands as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $devices = $candidate['devices'] ?? null;
            if (!is_array($devices)) {
                continue;
            }

            foreach ($devices as $device) {
                if (
                    is_array($device)
                    && is_string($device['id'] ?? null)
                    && hash_equals($deviceId, $device['id'])
                ) {
                    $command = $candidate;
                    break 2;
                }
            }
        }

        if ($command === null) {
            throw new \UnexpectedValueException(
                'Command response does not contain the requested device.'
            );
        }

        $status = isset($command['status']) && is_string($command['status']) ? $command['status'] : null;
        $errorCode = isset($command['errorCode']) && is_string($command['errorCode']) ? $command['errorCode'] : null;

        if ($errorCode === 'alreadyInState') {
            return [
                'result' => self::COMMAND_RESULT_ALREADY_IN_STATE,
                'errorCode' => $errorCode,
                'status' => $status,
            ];
        }

        if ($status === 'SUCCESS') {
            return [
                'result' => self::COMMAND_RESULT_ACCEPTED,
                'errorCode' => null,
                'status' => $status,
            ];
        }

        throw new \UnexpectedValueException(
            'Navimow command response was not accepted.'
        );
    }

    public static function mapApiError(array $payload): array
    {
        $code = isset($payload['code']) && is_int($payload['code']) ? $payload['code'] : null;
        $desc = isset($payload['desc']) && is_string($payload['desc']) ? $payload['desc'] : '';

        return [
            'code' => $code,
            'desc' => $desc,
            'reauthRequired' => $code === 4005 || $desc === 'CODE_OAUTH_INFO_ILLEGAL',
        ];
    }

    private static function payloadDevices(array $payload): array
    {
        $devices = $payload['data']['payload']['devices'] ?? [];

        if (!is_array($devices)) {
            return [];
        }

        return array_values(array_filter($devices, static fn ($device): bool => is_array($device)));
    }

    private static function findDevice(
        array $payload,
        ?string $deviceId
    ): array {
        $devices = self::payloadDevices($payload);
        if ($deviceId === null) {
            return $devices[0] ?? [];
        }

        foreach ($devices as $device) {
            $candidate = $device['id'] ?? $device['device_id'] ?? null;
            if (is_string($candidate) && hash_equals($deviceId, $candidate)) {
                return $device;
            }
        }

        return [];
    }

    private static function mapBatteryLevel(array $device): ?int
    {
        $capacityRemaining = $device['capacityRemaining'] ?? [];

        if (is_array($capacityRemaining)) {
            foreach ($capacityRemaining as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (($entry['unit'] ?? null) !== 'PERCENTAGE') {
                    continue;
                }

                $value = $entry['rawValue'] ?? null;
                if (is_int($value) && $value >= 0 && $value <= 100) {
                    return $value;
                }
            }
        }

        $fallback = $device['battery'] ?? null;
        if (is_int($fallback) && $fallback >= 0 && $fallback <= 100) {
            return $fallback;
        }

        return null;
    }

    private static function limitDiagnostic(string $value): string
    {
        return substr(preg_replace('/[[:cntrl:]]/', '', $value) ?? '', 0, 160);
    }
}
