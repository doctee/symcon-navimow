<?php

declare(strict_types=1);

namespace Navimow;

final class CommandContract
{
    public const DOCK = 'Dock';
    public const PAUSE = 'Pause';
    public const RESUME = 'Resume';

    public static function createPayload(
        string $command,
        string $deviceId
    ): array {
        if (!in_array($command, [self::DOCK, self::PAUSE, self::RESUME], true)) {
            throw new \InvalidArgumentException(
                'The requested mower command is not enabled.'
            );
        }

        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 128) {
            throw new \InvalidArgumentException('Device ID is invalid.');
        }

        if ($command === self::DOCK) {
            $execution = [
                'command' => 'action.devices.commands.Dock',
                'params' => new \stdClass(),
            ];
        } else {
            $execution = [
                'command' => 'action.devices.commands.PauseUnpause',
                'params' => ['on' => $command === self::RESUME],
            ];
        }

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
