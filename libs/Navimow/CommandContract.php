<?php

declare(strict_types=1);

namespace Navimow;

final class CommandContract
{
    public const DOCK = 'Dock';

    public static function createPayload(
        string $command,
        string $deviceId
    ): array {
        if ($command !== self::DOCK) {
            throw new \InvalidArgumentException(
                'The requested mower command is not enabled.'
            );
        }

        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 128) {
            throw new \InvalidArgumentException('Device ID is invalid.');
        }

        return [
            'commands' => [
                [
                    'devices' => [
                        ['id' => $deviceId],
                    ],
                    'execution' => [
                        'command' => 'action.devices.commands.Dock',
                        'params' => new \stdClass(),
                    ],
                ],
            ],
        ];
    }
}
