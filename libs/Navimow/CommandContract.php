<?php

declare(strict_types=1);

namespace Navimow;

final class CommandContract
{
    public const DOCK = 'Dock';
    public const PAUSE = 'Pause';

    public static function createPayload(
        string $command,
        string $deviceId
    ): array {
        if (!in_array($command, [self::DOCK, self::PAUSE], true)) {
            throw new \InvalidArgumentException(
                'The requested mower command is not enabled.'
            );
        }

        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 128) {
            throw new \InvalidArgumentException('Device ID is invalid.');
        }

        $execution = $command === self::DOCK
            ? [
                'command' => 'action.devices.commands.Dock',
                'params' => new \stdClass(),
            ]
            : [
                'command' => 'action.devices.commands.PauseUnpause',
                'params' => ['on' => false],
            ];

        return [
            'commands' => [
                [
                    'devices' => [
                        ['id' => $deviceId],
                    ],
                    'execution' => $execution,
                ],
            ],
        ];
    }
}
