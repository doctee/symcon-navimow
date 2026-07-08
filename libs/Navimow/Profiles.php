<?php

declare(strict_types=1);

namespace Navimow;

final class Profiles
{
    public static function ensure(): void
    {
        if (!function_exists('IPS_CreateVariableProfile')) {
            return;
        }

        self::ensureIntegerProfile('NAVIMOW.ConnectionState', [
            0 => 'Unconfigured',
            1 => 'Authorization Pending',
            2 => 'Authenticating',
            3 => 'Connected',
            4 => 'API Warning',
            5 => 'Reauth Required',
            6 => 'Offline',
            7 => 'Configuration Error',
        ]);

        self::ensureIntegerProfile('NAVIMOW.VehicleState', [
            0 => 'Unknown',
            1 => 'Running',
            2 => 'Docked',
            3 => 'Idle',
            4 => 'Paused',
            5 => 'Docking',
            6 => 'Mapping',
            7 => 'Lifted',
            8 => 'Error',
            9 => 'Software Update',
            10 => 'Self-Checking',
            11 => 'Offline',
        ]);

        self::ensureIntegerProfile('NAVIMOW.Command', [
            0 => 'None',
            1 => 'Refresh',
            2 => 'Start',
            3 => 'Stop',
            4 => 'Pause',
            5 => 'Resume',
            6 => 'Dock',
        ]);

        self::ensureIntegerProfile('NAVIMOW.CommandResult', [
            0 => 'None',
            1 => 'Requested',
            2 => 'Accepted',
            3 => 'Already In State',
            4 => 'Pending Verification',
            5 => 'Verified',
            6 => 'Rejected',
            7 => 'Failed',
            8 => 'Verification Timeout',
        ]);
    }

    private static function ensureIntegerProfile(string $name, array $associations): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }

        foreach ($associations as $value => $label) {
            IPS_SetVariableProfileAssociation($name, $value, $label, '', -1);
        }
    }
}
