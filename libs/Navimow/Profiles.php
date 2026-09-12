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

        self::ensureIntegerProfile('NAVIMOW.StatisticsState', [
            0 => 'Disabled',
            1 => 'No Data',
            2 => 'Available',
            3 => 'Stale',
            4 => 'Invalid',
        ]);

        self::ensureIntegerProfile('NAVIMOW.StatisticsQuality', [
            0 => 'No Data',
            1 => 'Low',
            2 => 'Medium',
            3 => 'High',
        ]);

        self::ensureIntegerProfile('NAVIMOW.MqttOperatingState', [
            0 => 'Disabled',
            1 => 'Starting',
            2 => 'Active',
            3 => 'Degraded',
            4 => 'Circuit Open',
            5 => 'Suspended',
            6 => 'Waiting for Authentication',
            7 => 'Reauthentication Required',
            8 => 'Configuration Error',
            9 => 'Stopping',
        ]);

        self::ensureIntegerProfile('NAVIMOW.MqttPositionFreshness', [
            0 => 'Unavailable',
            1 => 'Fresh',
            2 => 'Delayed',
            3 => 'Stale',
        ]);

        self::ensureIntegerProfile('NAVIMOW.MowingAnalyticsState', [
            0 => 'Disabled',
            1 => 'No Data',
            2 => 'Available',
            3 => 'Stale',
            4 => 'Invalid',
        ]);

        self::ensureIntegerProfile('NAVIMOW.MowingRecencyState', [
            0 => 'Unknown',
            1 => 'Current',
            2 => 'Due',
            3 => 'Overdue',
        ]);

        self::ensureFloatProfile('NAVIMOW.Percentage', ' %', 1);
        self::ensureFloatProfile('NAVIMOW.Area', ' m²', 1);
        self::ensureFloatProfile('NAVIMOW.Distance', ' m', 1);
        self::ensureFloatProfile('NAVIMOW.AreaPerformance', ' m²/h', 1);
        self::ensureIntegerUnitProfile('NAVIMOW.Duration', ' s');
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

    private static function ensureFloatProfile(
        string $name,
        string $suffix,
        int $digits
    ): void {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 2);
        }

        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, '', $suffix);
    }

    private static function ensureIntegerUnitProfile(
        string $name,
        string $suffix
    ): void {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }

        IPS_SetVariableProfileText($name, '', $suffix);
    }
}
