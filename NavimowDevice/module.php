<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/Profiles.php';

class NavimowDevice extends IPSModule
{
    private const DATA_INTERFACE = '{54620029-127D-470D-97C7-44265496FAA0}';
    private const MESSAGE_SCHEMA_VERSION = 1;
    private const VEHICLE_STATE_DOCKED = 2;
    private const VEHICLE_STATE_DOCKING = 5;
    private const VEHICLE_STATE_OFFLINE = 11;
    private const MAX_DEBUG_JSON_BYTES = 16384;
    private const COMMAND_DOCK = 6;
    private const COMMAND_RESULT_REQUESTED = 1;
    private const COMMAND_RESULT_ACCEPTED = 2;
    private const COMMAND_RESULT_ALREADY_IN_STATE = 3;
    private const COMMAND_RESULT_PENDING_VERIFICATION = 4;
    private const COMMAND_RESULT_VERIFIED = 5;
    private const COMMAND_RESULT_FAILED = 7;
    private const COMMAND_RESULT_VERIFICATION_TIMEOUT = 8;
    private const COMMAND_VERIFICATION_DELAY_MILLISECONDS = 5000;
    private const COMMAND_VERIFICATION_POLL_MILLISECONDS = 60000;
    private const COMMAND_VERIFICATION_TIMEOUT_SECONDS = 900;
    private const COMMAND_STATE_IDLE = 0;
    private const COMMAND_STATE_ACCEPTED = 1;
    private const COMMAND_STATE_RETURNING = 2;
    private const COMMAND_STATE_VERIFIED = 3;
    private const COMMAND_STATE_ALREADY_IN_STATE = 4;
    private const COMMAND_STATE_TIMED_OUT = 5;
    private const COMMAND_STATE_FAILED = 6;
    private const COMMAND_STATE_WAITING_READ = 7;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('DisplayName', '');
        $this->RegisterPropertyBoolean('DebugPayloads', false);

        $this->RegisterAttributeBoolean('CommandActive', false);
        $this->RegisterAttributeInteger('CommandCloudResult', 0);
        $this->RegisterAttributeInteger('CommandStatusBaseline', 0);
        $this->RegisterAttributeInteger('CommandStartedAt', 0);
        $this->RegisterAttributeInteger('CommandDeadline', 0);
        $this->RegisterAttributeInteger('CommandVerificationState', self::COMMAND_STATE_IDLE);

        $this->RegisterTimer(
            'CommandVerification',
            0,
            'NAVDV_VerifyCommand($_IPS["TARGET"]);'
        );
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

        if ($this->ReadAttributeBoolean('CommandActive')) {
            $this->scheduleNextCommandVerification();
        } else {
            $this->SetTimerInterval('CommandVerification', 0);
        }
    }

    public function RefreshStatus(): string
    {
        $result = $this->refreshStatusInternal();
        return $result['message'];
    }

    private function refreshStatusInternal(): array
    {
        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            return [
                'success' => false,
                'message' => 'Device ID is not configured.',
            ];
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
                return [
                    'success' => false,
                    'message' => $this->limitMessage(
                        is_string($result['message'] ?? null)
                            ? $result['message']
                            : 'Status refresh failed.'
                    ),
                ];
            }

            $this->applyStatusResult($result);
            return [
                'success' => true,
                'message' => 'Status refresh succeeded.',
            ];
        } catch (Throwable $exception) {
            $this->applyReadFailure([
                'message' => $exception->getMessage(),
                'staleAfter' => 300,
            ]);
            return [
                'success' => false,
                'message' => $this->limitMessage($exception->getMessage()),
            ];
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

        if ($Ident === 'Dock') {
            $this->Dock();
            return;
        }

        throw new InvalidArgumentException('Unsupported device action.');
    }

    public function Dock(): string
    {
        if ($this->ReadAttributeBoolean('CommandActive')) {
            return 'Another mower command is still being verified.';
        }

        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            return 'Device ID is not configured.';
        }

        $now = $this->currentTimestamp();
        $this->WriteAttributeBoolean('CommandActive', true);
        $this->WriteAttributeInteger('CommandCloudResult', 0);
        $this->WriteAttributeInteger(
            'CommandStatusBaseline',
            $this->GetValue('LastStatusUpdate')
        );
        $this->WriteAttributeInteger('CommandStartedAt', $now);
        $this->WriteAttributeInteger(
            'CommandDeadline',
            $now + self::COMMAND_VERIFICATION_TIMEOUT_SECONDS
        );
        $this->WriteAttributeInteger(
            'CommandVerificationState',
            self::COMMAND_STATE_ACCEPTED
        );
        $this->SetValue('LastCommand', self::COMMAND_DOCK);
        $this->SetValue('LastCommandAt', $now);
        $this->SetValue(
            'LastCommandResult',
            self::COMMAND_RESULT_REQUESTED
        );
        $this->SetValue('LastCommandError', '');

        try {
            $response = $this->SendDataToParent(json_encode([
                'DataID' => self::DATA_INTERFACE,
                'SchemaVersion' => self::MESSAGE_SCHEMA_VERSION,
                'Function' => 'SendCommand',
                'DeviceId' => $deviceId,
                'Command' => 'Dock',
            ], JSON_THROW_ON_ERROR));

            $result = json_decode(
                $response,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($result)) {
                throw new UnexpectedValueException(
                    'Account returned an invalid command result.'
                );
            }

            if (($result['status'] ?? null) !== 'ok') {
                throw new RuntimeException(
                    is_string($result['message'] ?? null)
                        ? $result['message']
                        : 'Dock command failed.'
                );
            }

            $cloudResult = $result['result'] ?? null;
            if (
                $cloudResult !== self::COMMAND_RESULT_ACCEPTED
                && $cloudResult !== self::COMMAND_RESULT_ALREADY_IN_STATE
            ) {
                throw new UnexpectedValueException(
                    'Account returned an unsupported command result.'
                );
            }

            $this->WriteAttributeInteger(
                'CommandCloudResult',
                $cloudResult
            );
            if ($cloudResult === self::COMMAND_RESULT_ALREADY_IN_STATE) {
                $this->WriteAttributeInteger(
                    'CommandVerificationState',
                    self::COMMAND_STATE_ALREADY_IN_STATE
                );
            }
            $this->SetValue('LastCommandResult', $cloudResult);
            $this->scheduleNextCommandVerification();

            return $cloudResult === self::COMMAND_RESULT_ALREADY_IN_STATE
                ? 'Dock command is already in state.'
                : 'Dock command was accepted.';
        } catch (Throwable $exception) {
            $this->finishCommand(
                self::COMMAND_RESULT_FAILED,
                $this->limitMessage($exception->getMessage())
            );

            return $this->limitMessage($exception->getMessage());
        }
    }

    public function VerifyCommand(): void
    {
        $this->SetTimerInterval('CommandVerification', 0);
        if (!$this->ReadAttributeBoolean('CommandActive')) {
            return;
        }

        $cloudResult = $this->ReadAttributeInteger(
            'CommandCloudResult'
        );
        if ($cloudResult === self::COMMAND_RESULT_ACCEPTED) {
            $this->SetValue(
                'LastCommandResult',
                self::COMMAND_RESULT_PENDING_VERIFICATION
            );
        }

        $deadline = $this->ReadAttributeInteger('CommandDeadline');
        $verificationStartedAt = $this->currentTimestamp();
        if ($deadline <= 0 || $verificationStartedAt > $deadline) {
            $this->timeoutCommandVerification();
            return;
        }

        $readResult = $this->refreshStatusInternal();

        $vehicleState = $this->GetValue('VehicleState');
        $verified = $readResult['success'] === true
            && $vehicleState === self::VEHICLE_STATE_DOCKED;

        if ($verified) {
            $result = $cloudResult === self::COMMAND_RESULT_ALREADY_IN_STATE
                ? self::COMMAND_RESULT_ALREADY_IN_STATE
                : self::COMMAND_RESULT_VERIFIED;
            $this->WriteAttributeInteger(
                'CommandVerificationState',
                $cloudResult === self::COMMAND_RESULT_ALREADY_IN_STATE
                    ? self::COMMAND_STATE_ALREADY_IN_STATE
                    : self::COMMAND_STATE_VERIFIED
            );
            $this->finishCommand($result, '');
            return;
        }

        if ($this->currentTimestamp() >= $deadline) {
            $this->timeoutCommandVerification();
            return;
        }

        if (
            $readResult['success'] === true
            && $vehicleState === self::VEHICLE_STATE_DOCKING
        ) {
            $this->WriteAttributeInteger(
                'CommandVerificationState',
                self::COMMAND_STATE_RETURNING
            );
            $this->scheduleNextCommandVerification();
            return;
        }

        $this->WriteAttributeInteger(
            'CommandVerificationState',
            self::COMMAND_STATE_WAITING_READ
        );
        $this->scheduleNextCommandVerification();
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
        if (
            is_int($lastUpdate)
            && $lastUpdate > 0
            && $this->currentTimestamp() - $lastUpdate > $staleAfter
        ) {
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

    private function finishCommand(int $result, string $error): void
    {
        $this->SetTimerInterval('CommandVerification', 0);
        $this->SetValue('LastCommandResult', $result);
        $this->SetValue('LastCommandError', $this->limitMessage($error));
        $this->WriteAttributeBoolean('CommandActive', false);
        $this->WriteAttributeInteger('CommandCloudResult', 0);
        $this->WriteAttributeInteger('CommandStatusBaseline', 0);
        $this->WriteAttributeInteger('CommandStartedAt', 0);
        $this->WriteAttributeInteger('CommandDeadline', 0);
        if ($result === self::COMMAND_RESULT_FAILED) {
            $this->WriteAttributeInteger(
                'CommandVerificationState',
                self::COMMAND_STATE_FAILED
            );
        }
    }

    protected function currentTimestamp(): int
    {
        return time();
    }

    private function scheduleNextCommandVerification(): void
    {
        $deadline = $this->ReadAttributeInteger('CommandDeadline');
        $now = $this->currentTimestamp();
        if ($deadline <= 0 || $now >= $deadline) {
            $this->SetTimerInterval('CommandVerification', 1);
            return;
        }

        $state = $this->ReadAttributeInteger('CommandVerificationState');
        $normalInterval = in_array(
            $state,
            [self::COMMAND_STATE_RETURNING, self::COMMAND_STATE_WAITING_READ],
            true
        )
            ? self::COMMAND_VERIFICATION_POLL_MILLISECONDS
            : self::COMMAND_VERIFICATION_DELAY_MILLISECONDS;
        $remainingInterval = max(1, ($deadline - $now) * 1000);

        $this->SetTimerInterval(
            'CommandVerification',
            min($normalInterval, $remainingInterval)
        );
    }

    private function timeoutCommandVerification(): void
    {
        $this->WriteAttributeInteger(
            'CommandVerificationState',
            self::COMMAND_STATE_TIMED_OUT
        );
        $this->finishCommand(
            self::COMMAND_RESULT_VERIFICATION_TIMEOUT,
            'Docked state was not confirmed before the verification timeout.'
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
