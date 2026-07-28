<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/Profiles.php';

class NavimowDevice extends IPSModule
{
    private const DATA_INTERFACE = '{54620029-127D-470D-97C7-44265496FAA0}';
    private const MESSAGE_SCHEMA_VERSION = 1;
    private const VEHICLE_STATE_DOCKED = 2;
    private const VEHICLE_STATE_RUNNING = 1;
    private const VEHICLE_STATE_PAUSED = 4;
    private const VEHICLE_STATE_DOCKING = 5;
    private const VEHICLE_STATE_OFFLINE = 11;
    private const MAX_DEBUG_JSON_BYTES = 16384;
    private const COMMAND_DOCK = 6;
    private const COMMAND_PAUSE = 4;
    private const COMMAND_RESUME = 5;
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
    private const SHORT_VERIFICATION_TIMEOUT_SECONDS = 60;
    private const SHORT_VERIFICATION_SCHEDULE_SECONDS = [2, 5, 10, 20, 30, 60];
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
        $this->RegisterAttributeInteger('CommandKind', 0);

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

        if ($Ident === 'Pause') {
            $this->Pause();
            return;
        }

        if ($Ident === 'Resume') {
            $this->Resume();
            return;
        }

        throw new InvalidArgumentException('Unsupported device action.');
    }

    public function Dock(): string
    {
        return $this->executeCommand(
            'Dock',
            self::COMMAND_DOCK,
            self::COMMAND_VERIFICATION_TIMEOUT_SECONDS
        );
    }

    public function Pause(): string
    {
        if ($this->ReadAttributeBoolean('CommandActive')) {
            return 'Another mower command is still being verified.';
        }

        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            return 'Device ID is not configured.';
        }

        $readResult = $this->refreshStatusInternal();
        if ($readResult['success'] !== true) {
            return 'Pause requires a current successful status read.';
        }

        if ($this->GetValue('VehicleState') !== self::VEHICLE_STATE_RUNNING) {
            return 'Pause is only available while the mower is running.';
        }

        return $this->executeCommand(
            'Pause',
            self::COMMAND_PAUSE,
            self::SHORT_VERIFICATION_TIMEOUT_SECONDS
        );
    }

    public function Resume(): string
    {
        if ($this->ReadAttributeBoolean('CommandActive')) {
            return 'Another mower command is still being verified.';
        }

        $deviceId = trim($this->ReadPropertyString('DeviceId'));
        if ($deviceId === '') {
            return 'Device ID is not configured.';
        }

        $readResult = $this->refreshStatusInternal();
        if ($readResult['success'] !== true) {
            return 'Resume requires a current successful status read.';
        }

        if ($this->GetValue('VehicleState') !== self::VEHICLE_STATE_PAUSED) {
            return 'Resume is only available while the mower is paused.';
        }

        return $this->executeCommand(
            'Resume',
            self::COMMAND_RESUME,
            self::SHORT_VERIFICATION_TIMEOUT_SECONDS,
            false
        );
    }

    private function executeCommand(
        string $command,
        int $commandValue,
        int $timeoutSeconds,
        bool $allowAlreadyInState = true
    ): string {
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
            $now + $timeoutSeconds
        );
        $this->WriteAttributeInteger(
            'CommandVerificationState',
            self::COMMAND_STATE_ACCEPTED
        );
        $this->WriteAttributeInteger('CommandKind', $commandValue);
        $this->SetValue('LastCommand', $commandValue);
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
                'Command' => $command,
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
                        : $command . ' command failed.'
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

            if (
                $cloudResult === self::COMMAND_RESULT_ALREADY_IN_STATE
                && !$allowAlreadyInState
            ) {
                throw new UnexpectedValueException(
                    'Resume already-in-state response is unsupported.'
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
                ? $command . ' command is already in state.'
                : $command . ' command was accepted.';
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
        $commandKind = $this->activeCommandKind();
        if ($commandKind === self::COMMAND_PAUSE) {
            $expectedState = self::VEHICLE_STATE_PAUSED;
        } elseif ($commandKind === self::COMMAND_RESUME) {
            $expectedState = self::VEHICLE_STATE_RUNNING;
        } else {
            $expectedState = self::VEHICLE_STATE_DOCKED;
        }
        $verified = $readResult['success'] === true
            && $vehicleState === $expectedState;

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
            && $commandKind === self::COMMAND_DOCK
            && $vehicleState === self::VEHICLE_STATE_DOCKING
        ) {
            $this->WriteAttributeInteger(
                'CommandVerificationState',
                self::COMMAND_STATE_RETURNING
            );
            $this->scheduleNextCommandVerification();
            return;
        }

        if (
            $readResult['success'] === true
            && $commandKind === self::COMMAND_PAUSE
            && $vehicleState !== self::VEHICLE_STATE_RUNNING
        ) {
            $this->finishCommand(
                self::COMMAND_RESULT_FAILED,
                'Paused state was not confirmed; an unexpected mower state was observed.'
            );
            return;
        }

        if (
            $readResult['success'] === true
            && $commandKind === self::COMMAND_RESUME
            && $vehicleState !== self::VEHICLE_STATE_PAUSED
        ) {
            $this->finishCommand(
                self::COMMAND_RESULT_FAILED,
                'Running state was not confirmed; an unexpected mower state was observed.'
            );
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
        $this->WriteAttributeInteger('CommandKind', 0);
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

        if (
            in_array(
                $this->activeCommandKind(),
                [self::COMMAND_PAUSE, self::COMMAND_RESUME],
                true
            )
        ) {
            $startedAt = $this->ReadAttributeInteger('CommandStartedAt');
            $elapsed = max(0, $now - $startedAt);
            $nextOffset = self::SHORT_VERIFICATION_TIMEOUT_SECONDS;
            foreach (self::SHORT_VERIFICATION_SCHEDULE_SECONDS as $offset) {
                if ($offset > $elapsed) {
                    $nextOffset = $offset;
                    break;
                }
            }
            $normalInterval = max(1, ($nextOffset - $elapsed) * 1000);
        } else {
            $state = $this->ReadAttributeInteger('CommandVerificationState');
            $normalInterval = in_array(
                $state,
                [self::COMMAND_STATE_RETURNING, self::COMMAND_STATE_WAITING_READ],
                true
            )
                ? self::COMMAND_VERIFICATION_POLL_MILLISECONDS
                : self::COMMAND_VERIFICATION_DELAY_MILLISECONDS;
        }
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
        $commandKind = $this->activeCommandKind();
        if ($commandKind === self::COMMAND_PAUSE) {
            $message = 'Paused state was not confirmed before the verification timeout.';
        } elseif ($commandKind === self::COMMAND_RESUME) {
            $message = 'Running state was not confirmed before the verification timeout.';
        } else {
            $message = 'Docked state was not confirmed before the verification timeout.';
        }
        $this->finishCommand(
            self::COMMAND_RESULT_VERIFICATION_TIMEOUT,
            $message
        );
    }

    private function activeCommandKind(): int
    {
        $commandKind = $this->ReadAttributeInteger('CommandKind');
        if (
            in_array(
                $commandKind,
                [self::COMMAND_PAUSE, self::COMMAND_RESUME],
                true
            )
        ) {
            return $commandKind;
        }

        // Active commands created by an older module build are Dock commands.
        return self::COMMAND_DOCK;
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
