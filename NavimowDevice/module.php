<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/Profiles.php';

class NavimowDevice extends IPSModule
{
    private const DATA_INTERFACE = '{54620029-127D-470D-97C7-44265496FAA0}';
    private const MESSAGE_SCHEMA_VERSION = 1;
    private const VEHICLE_STATE_OFFLINE = 11;
    private const MAX_DEBUG_JSON_BYTES = 16384;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('DisplayName', '');
        $this->RegisterPropertyBoolean('DebugPayloads', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        Navimow\Profiles::ensure();

        $this->RegisterVariableInteger('VehicleState', 'Vehicle State', 'NAVIMOW.VehicleState', 10);
        $this->RegisterVariableBoolean('Online', 'Online', '', 20);
        $this->RegisterVariableInteger('BatteryLevel', 'Battery Level', '~Intensity.100', 30);
        $this->RegisterVariableInteger('LastStatusUpdate', 'Last Status Update', '~UnixTimestamp', 40);
        $this->RegisterVariableInteger('LastCommand', 'Last Command', 'NAVIMOW.Command', 50);
        $this->RegisterVariableInteger('LastCommandAt', 'Last Command At', '~UnixTimestamp', 60);
        $this->RegisterVariableInteger('LastCommandResult', 'Last Command Result', 'NAVIMOW.CommandResult', 70);
        $this->RegisterVariableString('LastCommandError', 'Last Command Error', '', 80);

        if ($this->ReadPropertyBoolean('DebugPayloads')) {
            $this->RegisterVariableString('RawStatusJson', 'Raw Status JSON', '', 90);
        }
    }

    public function RefreshStatus(): string
    {
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            return 'Device ID is not configured.';
        }

        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID' => self::DATA_INTERFACE,
                'SchemaVersion' => self::MESSAGE_SCHEMA_VERSION,
                'Function' => 'GetStatus',
                'DeviceId' => $deviceId,
            ], JSON_THROW_ON_ERROR));

            $result = json_decode(
                $response,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($result)) {
                throw new UnexpectedValueException(
                    'Account returned an invalid status result.'
                );
            }

            if (($result['status'] ?? null) !== 'ok') {
                $this->applyReadFailure($result);
                return $this->limitMessage(
                    is_string($result['message'] ?? null)
                        ? $result['message']
                        : 'Status refresh failed.'
                );
            }

            $this->applyStatusResult($result);
            return 'Status refresh succeeded.';
        } catch (Throwable $exception) {
            $this->applyReadFailure([
                'message' => $exception->getMessage(),
                'staleAfter' => 300,
            ]);
            return $this->limitMessage($exception->getMessage());
        }
    }

    public function ReceiveData($JSONString)
    {
        try {
            $message = json_decode(
                $JSONString,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (
                is_array($message)
                && ($message['DataID'] ?? null) === self::DATA_INTERFACE
                && ($message['SchemaVersion'] ?? null) === self::MESSAGE_SCHEMA_VERSION
                && ($message['Function'] ?? null) === 'PollStatus'
            ) {
                $this->RefreshStatus();
            }
        } catch (Throwable $exception) {
            $this->SendDebug(
                'ReceiveData',
                $this->limitMessage($exception->getMessage()),
                0
            );
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Refresh') {
            $this->RefreshStatus();
            return;
        }

        throw new InvalidArgumentException('Unsupported device action.');
    }

    private function applyStatusResult(array $result): void
    {
        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            throw new UnexpectedValueException(
                'Status result does not contain mapped data.'
            );
        }

        $vehicleState = $data['vehicleState'] ?? null;
        if (!is_int($vehicleState) || $vehicleState < 0 || $vehicleState > 11) {
            throw new UnexpectedValueException('Mapped vehicle state is invalid.');
        }

        $battery = $data['batteryLevel'] ?? null;
        if ($battery !== null && (!is_int($battery) || $battery < 0 || $battery > 100)) {
            throw new UnexpectedValueException('Mapped battery level is invalid.');
        }

        $receivedAt = $result['receivedAt'] ?? null;
        if (!is_int($receivedAt) || $receivedAt <= 0) {
            throw new UnexpectedValueException('Status timestamp is invalid.');
        }

        $this->SetValue('VehicleState', $vehicleState);
        if ($battery !== null) {
            $this->SetValue('BatteryLevel', $battery);
        }

        $online = $vehicleState !== self::VEHICLE_STATE_OFFLINE;
        if (is_bool($data['online'] ?? null)) {
            $online = $data['online'];
        }

        $this->SetValue('Online', $online);
        $this->SetValue('LastStatusUpdate', $receivedAt);

        if (
            $this->ReadPropertyBoolean('DebugPayloads')
            && @$this->GetIDForIdent('RawStatusJson') > 0
        ) {
            $debug = json_encode($data, JSON_THROW_ON_ERROR);
            $this->SetValue(
                'RawStatusJson',
                substr($debug, 0, self::MAX_DEBUG_JSON_BYTES)
            );
        }
    }

    private function applyReadFailure(array $result): void
    {
        $staleAfter = $result['staleAfter'] ?? 300;
        if (!is_int($staleAfter) || $staleAfter < 300) {
            $staleAfter = 300;
        }

        $lastUpdate = $this->GetValue('LastStatusUpdate');
        if (is_int($lastUpdate) && $lastUpdate > 0 && time() - $lastUpdate > $staleAfter) {
            $this->SetValue('Online', false);
        }

        $this->SendDebug(
            'StatusRefreshFailure',
            $this->limitMessage(
                is_string($result['message'] ?? null)
                    ? $result['message']
                    : 'Status refresh failed.'
            ),
            0
        );
    }

    private function limitMessage(string $message): string
    {
        return substr(
            preg_replace('/[[:cntrl:]]/', '', $message) ?? '',
            0,
            200
        );
    }
}
