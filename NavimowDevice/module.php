<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/Profiles.php';
$configurationHashHelper = __DIR__
    . '/../libs/SAEF/helpers/diagnostics/ConfigurationHash.php';
if (
    !function_exists('SAEF_CreateConfigurationHash')
    && is_file($configurationHashHelper)
) {
    require_once $configurationHashHelper;
}
unset($configurationHashHelper);
require_once __DIR__ . '/../libs/Navimow/MqttPathSegmenter.php';
require_once __DIR__ . '/../libs/Navimow/ZoneStatisticsReducer.php';
require_once __DIR__ . '/../libs/Navimow/LocalMapSceneProjector.php';
require_once __DIR__ . '/../libs/Navimow/RevisionBoundedTrackStore.php';
require_once __DIR__ . '/../libs/Navimow/LocalMapSvgRenderer.php';

class NavimowDevice extends IPSModule
{
    private const INSTANCE_STATUS_ACTIVE = 102;
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
    private const LOCAL_MAP_MAX_PACKAGE_BYTES = 1024 * 1024;
    private const LOCAL_MAP_MAX_EVIDENCE_BYTES = 256 * 1024;
    private const LOCAL_MAP_MAX_ERROR_ENTRIES = 20;
    private const LOCAL_MAP_REST_STALE_SECONDS = 300;
    private const LOCAL_MAP_SEMAPHORE_TIMEOUT_MILLISECONDS = 1000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('DisplayName', '');
        $this->RegisterPropertyBoolean('DebugPayloads', false);
        $this->RegisterPropertyBoolean('EnableLocalMap', false);
        $this->RegisterPropertyBoolean('EnableZoneStatistics', false);
        $this->RegisterPropertyString('AcceptedMapProjection', '');
        $this->RegisterPropertyString('AcceptedGeometryKey', '');
        $this->RegisterPropertyString('HiddenZoneSequences', '[1]');
        $this->RegisterPropertyString('MapTheme', 'dark');
        $this->RegisterPropertyInteger('TrackRetentionHours', 72);
        $this->RegisterPropertyInteger('MapRefreshInterval', 60);
        $this->RegisterPropertyInteger('MapIdleRefreshInterval', 300);

        $this->RegisterAttributeBoolean('CommandActive', false);
        $this->RegisterAttributeInteger('CommandCloudResult', 0);
        $this->RegisterAttributeInteger('CommandStatusBaseline', 0);
        $this->RegisterAttributeInteger('CommandStartedAt', 0);
        $this->RegisterAttributeInteger('CommandDeadline', 0);
        $this->RegisterAttributeInteger('CommandVerificationState', self::COMMAND_STATE_IDLE);
        $this->RegisterAttributeInteger('CommandKind', 0);
        $this->RegisterAttributeString('LocalMapRevisionRegistry', '{}');
        $this->RegisterAttributeString('LocalMapTrackState', '{}');
        $this->RegisterAttributeString('LocalMapStatisticsState', '{}');
        $this->RegisterAttributeString('LocalMapStatisticsGeometryKey', '');
        $this->RegisterAttributeString('LocalMapRenderMetadata', '{}');
        $this->RegisterAttributeString('LocalMapErrorHistory', '[]');

        $this->RegisterTimer(
            'CommandVerification',
            0,
            'NAVDV_VerifyCommand($_IPS["TARGET"]);'
        );
        $this->RegisterTimer(
            'LocalMapRefresh',
            0,
            'NAVDV_RefreshLocalMap($_IPS["TARGET"]);'
        );
        $this->registerKernelStartMessage();
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
        $this->RegisterVariableString('LocalMap', 'Local Map', '~HTMLBox', 100);

        if ($this->ReadPropertyBoolean('EnableZoneStatistics')) {
            $this->registerZoneStatisticsVariables();
        } elseif ($this->variableExists('StatisticsState')) {
            $this->SetValue('StatisticsState', 0);
        }

        if ($this->ReadPropertyBoolean('DebugPayloads')) {
            $this->RegisterVariableString('RawStatusJson', 'Raw Status JSON', '', 90);
        }

        if ($this->ReadAttributeBoolean('CommandActive')) {
            $this->scheduleNextCommandVerification();
        } else {
            $this->SetTimerInterval('CommandVerification', 0);
        }

        if ($this->localMapConfigurationIsValid()) {
            $this->setLocalMapHidden(false);
            $this->scheduleLocalMapRefresh();
        } else {
            $this->SetTimerInterval('LocalMapRefresh', 0);
            $this->SetValue('LocalMap', '');
            $this->setLocalMapHidden(true);
        }

        $this->SetStatus(self::INSTANCE_STATUS_ACTIVE);
    }

    public function RefreshStatus(): string
    {
        $result = $this->refreshStatusInternal();
        return $result['message'];
    }

    public function RefreshLocalMap(): string
    {
        if (!$this->ReadPropertyBoolean('EnableLocalMap')) {
            $this->SetTimerInterval('LocalMapRefresh', 0);
            return 'Local map is disabled.';
        }
        if (!$this->localMapConfigurationIsValid()) {
            $this->SetTimerInterval('LocalMapRefresh', 0);
            $this->SetValue('LocalMap', '');
            $this->setLocalMapHidden(true);
            return 'Local map configuration is invalid.';
        }
        $lockName = 'NAVIMOW_LOCAL_MAP_' . $this->InstanceID;
        if (
            !IPS_SemaphoreEnter(
                $lockName,
                self::LOCAL_MAP_SEMAPHORE_TIMEOUT_MILLISECONDS
            )
        ) {
            return 'Local map refresh is already running.';
        }

        try {
            $package = $this->acceptedLocalMapPackage();
            $geometryKey = SAEF_CreateConfigurationHash(
                $package['geometry']
            );
            if (
                !hash_equals(
                    $this->ReadPropertyString('AcceptedGeometryKey'),
                    $geometryKey
                )
            ) {
                throw new RuntimeException(
                    'Accepted map revision does not match the projection.'
                );
            }
            $deviceId = trim($this->ReadPropertyString('DeviceId'));
            if ($deviceId === '') {
                throw new RuntimeException('Device ID is not configured.');
            }
            $response = $this->SendDataToParent(json_encode([
                'DataID' => self::DATA_INTERFACE,
                'SchemaVersion' => self::MESSAGE_SCHEMA_VERSION,
                'Function' => 'GetLocalMapEvidence',
                'DeviceId' => $deviceId,
            ], JSON_THROW_ON_ERROR));
            if (strlen($response) > self::LOCAL_MAP_MAX_EVIDENCE_BYTES) {
                throw new RuntimeException(
                    'Local map evidence exceeds the byte limit.'
                );
            }
            $evidence = json_decode(
                $response,
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($evidence)) {
                throw new RuntimeException(
                    'Local map evidence is invalid.'
                );
            }
            $status = $evidence['status'] ?? null;
            if (!in_array($status, ['ok', 'stale'], true)) {
                if (
                    in_array(
                        $status,
                        ['disabled', 'inactive', 'unavailable', 'ambiguous'],
                        true
                    )
                ) {
                    $stored = $this->renderStoredLocalMap(
                        $package,
                        $geometryKey
                    );
                    $this->SetValue('LocalMap', $stored['svg']);
                    $this->writeLocalMapMetadata(
                        $status,
                        $geometryKey,
                        $stored['segmentCount'],
                        $stored['pointCount']
                    );
                    $this->updateZoneStatisticsVariables(
                        $package,
                        $stored['statistics'],
                        null,
                        true
                    );
                    $this->scheduleLocalMapRefresh();
                    return 'Local map rendered without fresh MQTT evidence.';
                }
                $this->writeLocalMapMetadata(
                    is_string($status) ? $status : 'invalid',
                    $geometryKey,
                    0,
                    0
                );
                $this->scheduleLocalMapRefresh();
                return 'Local map evidence is ' . (
                    is_string($status) ? $status : 'invalid'
                ) . '.';
            }
            $this->assertLocalMapEvidenceAuthority($evidence);
            $position = $evidence['position'] ?? null;
            $task = $evidence['task'] ?? null;
            if (!is_array($position) || !is_array($task)) {
                throw new RuntimeException(
                    'Local map evidence projections are missing.'
                );
            }
            $track = $position['track'] ?? null;
            if (!is_array($track) || !array_is_list($track)) {
                throw new RuntimeException(
                    'Local map position track is invalid.'
                );
            }
            $cutoff = $this->currentTimestamp()
                - $this->trackRetentionHours() * 3600;
            $track = array_values(array_filter(
                $track,
                static fn (mixed $point): bool => is_array($point)
                    && is_int($point['receivedAt'] ?? null)
                    && $point['receivedAt'] >= $cutoff
            ));
            $passes = $task['passes'] ?? null;
            if (!is_array($passes) || !array_is_list($passes)) {
                throw new RuntimeException(
                    'Local map task passes are invalid.'
                );
            }
            $path = Navimow\MqttPathSegmenter::build($track, $passes);
            $areas = $this->configuredZoneAreas($package);
            $statistics = Navimow\ZoneStatisticsReducer::reduce(
                $task,
                $areas
            );
            $revision = [
                'currentGeometryKey' => $geometryKey,
                'acceptedGeometryKey' => $geometryKey,
                'pathGeometryKey' => $geometryKey,
                'statisticsGeometryKey' => $geometryKey,
                'frameCorrelationApproved' => true,
            ];
            $scene = Navimow\LocalMapSceneProjector::build(
                $package['geometry'],
                $path,
                $statistics,
                $package['bindings'],
                $revision
            );
            $trackState = $this->restoreLocalMapTrackState();
            $trackState = Navimow\RevisionBoundedTrackStore::pruneBefore(
                $trackState,
                max(1, $cutoff)
            );
            $trackState = Navimow\RevisionBoundedTrackStore::ingestScene(
                $trackState,
                $scene
            );
            $scene['path'] = Navimow\RevisionBoundedTrackStore::scenePath(
                $trackState,
                $geometryKey
            );
            $svg = Navimow\LocalMapSvgRenderer::render($scene, [
                'stationState' => $this->localMapStationState(),
                'mowerState' => $this->localMapMowerState(),
                'showMower' => $status === 'ok'
                    && $this->localMapMowerState() !== 'docked',
                'hiddenZoneSequences' => $this->hiddenZoneSequences(),
                'theme' => $this->localMapTheme(),
            ]);
            $trackProjection = Navimow\RevisionBoundedTrackStore::project(
                $trackState
            );
            $this->WriteAttributeString(
                'LocalMapTrackState',
                Navimow\RevisionBoundedTrackStore::serializeState(
                    $trackState
                )
            );
            $this->WriteAttributeString(
                'LocalMapStatisticsState',
                json_encode($statistics, JSON_THROW_ON_ERROR)
            );
            $this->WriteAttributeString(
                'LocalMapStatisticsGeometryKey',
                $geometryKey
            );
            $this->WriteAttributeString(
                'LocalMapRevisionRegistry',
                json_encode([
                    'formatVersion' => 1,
                    'acceptedGeometryKey' => $geometryKey,
                    'lastValidatedAt' => $this->currentTimestamp(),
                ], JSON_THROW_ON_ERROR)
            );
            $this->SetValue('LocalMap', $svg);
            $this->updateZoneStatisticsVariables(
                $package,
                $statistics,
                $evidence['observedAt'],
                false
            );
            $this->writeLocalMapMetadata(
                $status,
                $geometryKey,
                $trackProjection['segmentCount'],
                $trackProjection['pointCount']
            );
            $this->scheduleLocalMapRefresh();

            return 'Local map refresh succeeded.';
        } catch (Throwable $exception) {
            $this->recordLocalMapError($exception->getMessage());
            if (
                $this->ReadPropertyBoolean('EnableZoneStatistics')
                && $this->variableExists('StatisticsState')
            ) {
                $this->SetValue('StatisticsState', 4);
            }
            $this->scheduleLocalMapRefresh();
            return 'Local map refresh failed.';
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
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
                $targetDeviceId = $message['DeviceId'] ?? null;
                if (
                    $targetDeviceId !== null
                    && (
                        !is_string($targetDeviceId)
                        || !hash_equals(
                            trim($this->ReadPropertyString('DeviceId')),
                            $targetDeviceId
                        )
                    )
                ) {
                    return;
                }
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

    public function MessageSink(
        $TimeStamp,
        $SenderID,
        $Message,
        $Data
    ) {
        if (
            $SenderID === 0
            && $Message === $this->kernelStartedMessageId()
        ) {
            $this->scheduleLocalMapRefresh();
        }
    }

    /** @return array<string, mixed> */
    private function acceptedLocalMapPackage(): array
    {
        $encoded = trim($this->ReadPropertyString(
            'AcceptedMapProjection'
        ));
        if (
            $encoded === ''
            || strlen($encoded) > self::LOCAL_MAP_MAX_PACKAGE_BYTES
        ) {
            throw new RuntimeException(
                'Accepted map projection is missing or oversized.'
            );
        }
        $package = json_decode(
            $encoded,
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($package)
            || ($package['formatVersion'] ?? null) !== 1
            || !is_array($package['geometry'] ?? null)
            || !is_array($package['bindings'] ?? null)
            || !array_is_list($package['bindings'])
            || ($package['frameCorrelationApproved'] ?? null) !== true
        ) {
            throw new RuntimeException(
                'Accepted map package is invalid.'
            );
        }
        $key = trim($this->ReadPropertyString('AcceptedGeometryKey'));
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new RuntimeException(
                'Accepted map revision key is invalid.'
            );
        }

        return $package;
    }

    private function localMapConfigurationIsValid(): bool
    {
        if (
            !$this->ReadPropertyBoolean('EnableLocalMap')
            || trim($this->ReadPropertyString('DeviceId')) === ''
        ) {
            return false;
        }
        try {
            $package = $this->acceptedLocalMapPackage();
            $geometryKey = $this->ReadPropertyString(
                'AcceptedGeometryKey'
            );
            $this->hiddenZoneSequences();
            $this->localMapTheme();
            if (
                !hash_equals(
                    $geometryKey,
                    SAEF_CreateConfigurationHash($package['geometry'])
                )
            ) {
                return false;
            }
            $this->validateLocalMapPackage($package, $geometryKey);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $package */
    private function validateLocalMapPackage(
        array $package,
        string $geometryKey
    ): void {
        $this->configuredZoneAreas($package);
        Navimow\LocalMapSceneProjector::build(
            $package['geometry'],
            $this->emptyLocalMapPath(),
            $this->emptyLocalMapStatistics(),
            $package['bindings'],
            [
                'currentGeometryKey' => $geometryKey,
                'acceptedGeometryKey' => $geometryKey,
                'pathGeometryKey' => $geometryKey,
                'statisticsGeometryKey' => $geometryKey,
                'frameCorrelationApproved' => true,
            ]
        );
    }

    /** @param array<string, mixed> $evidence */
    private function assertLocalMapEvidenceAuthority(array $evidence): void
    {
        $authority = $evidence['authority'] ?? null;
        if (
            ($evidence['formatVersion'] ?? null) !== 1
            || !is_array($authority)
            || ($authority['state'] ?? null) !== 'rest-authoritative'
            || ($authority['path'] ?? null) !== 'mqtt-inference'
            || ($authority['task'] ?? null) !== 'mqtt-inference'
            || !is_int($evidence['observedAt'] ?? null)
            || $evidence['observedAt'] <= 0
        ) {
            throw new RuntimeException(
                'Local map evidence authority is invalid.'
            );
        }
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array<string, float>
     */
    private function configuredZoneAreas(array $package): array
    {
        $zones = $package['geometry']['zones'] ?? null;
        if (!is_array($zones) || !array_is_list($zones)) {
            throw new RuntimeException('Map zones are invalid.');
        }
        $areaById = [];
        foreach ($zones as $zone) {
            if (
                !is_array($zone)
                || !is_int($zone['id'] ?? null)
            ) {
                throw new RuntimeException('Map zone identity is invalid.');
            }
            $reported = $zone['reportedArea'] ?? null;
            if (
                (is_int($reported) || is_float($reported))
                && is_finite((float) $reported)
                && (float) $reported > 0.0
            ) {
                $areaById[$zone['id']] = (float) $reported;
            }
        }
        $areas = [];
        foreach ($package['bindings'] as $binding) {
            if (!is_array($binding)) {
                throw new RuntimeException('Map binding is invalid.');
            }
            $zoneId = $binding['zoneId'] ?? null;
            $zoneKey = $binding['zoneKey'] ?? null;
            if ($zoneKey === null) {
                continue;
            }
            if (
                !is_int($zoneId)
                || !isset($areaById[$zoneId])
                || !is_string($zoneKey)
                || preg_match('/^[a-f0-9]{64}$/D', $zoneKey) !== 1
            ) {
                throw new RuntimeException(
                    'Map zone-area binding is invalid.'
                );
            }
            $areas[$zoneKey] = $areaById[$zoneId];
        }

        return $areas;
    }

    /** @return list<int> */
    private function hiddenZoneSequences(): array
    {
        $decoded = json_decode(
            $this->ReadPropertyString('HiddenZoneSequences'),
            true,
            8,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($decoded)
            || !array_is_list($decoded)
            || count($decoded) > 32
        ) {
            throw new RuntimeException(
                'Hidden zone sequences are invalid.'
            );
        }
        $result = [];
        foreach ($decoded as $sequence) {
            if (!is_int($sequence) || $sequence < 1 || $sequence > 32) {
                throw new RuntimeException(
                    'Hidden zone sequence is invalid.'
                );
            }
            $result[$sequence] = $sequence;
        }
        ksort($result);

        return array_values($result);
    }

    private function localMapTheme(): string
    {
        $theme = $this->ReadPropertyString('MapTheme');
        if (!in_array($theme, ['dark', 'light'], true)) {
            throw new RuntimeException('Local map theme is invalid.');
        }

        return $theme;
    }

    private function registerZoneStatisticsVariables(): void
    {
        $this->RegisterVariableInteger(
            'StatisticsState',
            'Zone Statistics State',
            'NAVIMOW.StatisticsState',
            110
        );
        $this->RegisterVariableInteger(
            'StatisticsUpdatedAt',
            'Zone Statistics Updated At',
            '~UnixTimestamp',
            115
        );
        if ($this->GetValue('StatisticsState') === 0) {
            $this->SetValue('StatisticsState', 1);
        }

        try {
            $definitions = $this->statisticsZoneDefinitions(
                $this->acceptedLocalMapPackage()
            );
        } catch (Throwable) {
            $this->SetValue('StatisticsState', 4);
            return;
        }
        foreach ($definitions as $index => $definition) {
            $prefix = 'Zone' . $definition['zoneId'];
            $name = $definition['label'];
            $position = 120 + $index * 10;
            $this->RegisterVariableFloat(
                $prefix . 'PassProgress',
                $name . ' - Pass Progress',
                'NAVIMOW.Percentage',
                $position
            );
            $this->RegisterVariableFloat(
                $prefix . 'ObservedArea',
                $name . ' - Observed Area (Retained)',
                'NAVIMOW.Area',
                $position + 1
            );
            $this->RegisterVariableInteger(
                $prefix . 'LastObservedAt',
                $name . ' - Last Observed At',
                '~UnixTimestamp',
                $position + 2
            );
            $this->RegisterVariableInteger(
                $prefix . 'StatisticsQuality',
                $name . ' - Statistics Quality',
                'NAVIMOW.StatisticsQuality',
                $position + 3
            );
        }
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return list<array{zoneId: int, zoneKey: string, label: string}>
     */
    private function statisticsZoneDefinitions(array $package): array
    {
        $definitions = [];
        $seen = [];
        foreach ($package['bindings'] as $binding) {
            if (!is_array($binding) || ($binding['zoneKey'] ?? null) === null) {
                continue;
            }
            $zoneId = $binding['zoneId'] ?? null;
            $zoneKey = $binding['zoneKey'];
            $label = $binding['label'] ?? null;
            if (
                !is_int($zoneId)
                || $zoneId <= 0
                || $zoneId > 1000000000
                || isset($seen[$zoneId])
                || !is_string($zoneKey)
                || preg_match('/^[a-f0-9]{64}$/D', $zoneKey) !== 1
                || !is_string($label)
                || $label === ''
                || strlen($label) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
            ) {
                throw new RuntimeException(
                    'Statistics zone binding is invalid.'
                );
            }
            $seen[$zoneId] = true;
            $definitions[] = [
                'zoneId' => $zoneId,
                'zoneKey' => $zoneKey,
                'label' => $label,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $package
     * @param array<string, mixed> $statistics
     */
    private function updateZoneStatisticsVariables(
        array $package,
        array $statistics,
        ?int $observedAt,
        bool $stale
    ): void {
        if (!$this->ReadPropertyBoolean('EnableZoneStatistics')) {
            return;
        }
        $this->registerZoneStatisticsVariables();
        $zones = $statistics['zones'] ?? null;
        if (!is_array($zones) || !array_is_list($zones)) {
            $this->SetValue('StatisticsState', 4);
            return;
        }
        $byKey = [];
        foreach ($zones as $zone) {
            if (
                !is_array($zone)
                || !is_string($zone['areaKey'] ?? null)
            ) {
                $this->SetValue('StatisticsState', 4);
                return;
            }
            $byKey[$zone['areaKey']] = $zone;
        }
        foreach ($this->statisticsZoneDefinitions($package) as $definition) {
            $zone = $byKey[$definition['zoneKey']] ?? null;
            if (!is_array($zone)) {
                continue;
            }
            $prefix = 'Zone' . $definition['zoneId'];
            $latest = $zone['latestPass'] ?? null;
            $progress = is_array($latest)
                ? $latest['passProgressPercent'] ?? null
                : null;
            $area = $zone['observedAreaTotal'] ?? null;
            $lastObservedAt = $zone['lastObservedAt'] ?? null;
            $quality = match ($zone['confidence'] ?? null) {
                'low' => 1,
                'medium' => 2,
                'high' => 3,
                default => 0,
            };
            if (is_int($progress) || is_float($progress)) {
                $this->SetValue(
                    $prefix . 'PassProgress',
                    round((float) $progress, 1)
                );
            }
            if (is_int($area) || is_float($area)) {
                $this->SetValue(
                    $prefix . 'ObservedArea',
                    round(max(0.0, (float) $area), 1)
                );
            }
            if (is_int($lastObservedAt) && $lastObservedAt > 0) {
                $this->SetValue(
                    $prefix . 'LastObservedAt',
                    $lastObservedAt
                );
            }
            $this->SetValue($prefix . 'StatisticsQuality', $quality);
        }
        $this->SetValue(
            'StatisticsState',
            $zones === [] ? 1 : ($stale ? 3 : 2)
        );
        if (!$stale && is_int($observedAt) && $observedAt > 0) {
            $this->SetValue('StatisticsUpdatedAt', $observedAt);
        }
    }

    private function variableExists(string $ident): bool
    {
        try {
            return $this->GetIDForIdent($ident) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function restoreLocalMapTrackState(): array
    {
        $encoded = $this->ReadAttributeString('LocalMapTrackState');
        if ($encoded === '' || $encoded === '{}') {
            return Navimow\RevisionBoundedTrackStore::initialState();
        }

        return Navimow\RevisionBoundedTrackStore::restoreState($encoded);
    }

    /** @return array<string, mixed> */
    private function restoreLocalMapStatistics(string $geometryKey): array
    {
        if (
            !hash_equals(
                $geometryKey,
                $this->ReadAttributeString(
                    'LocalMapStatisticsGeometryKey'
                )
            )
        ) {
            return $this->emptyLocalMapStatistics();
        }
        $encoded = $this->ReadAttributeString('LocalMapStatisticsState');
        if ($encoded === '' || $encoded === '{}') {
            return $this->emptyLocalMapStatistics();
        }
        $statistics = json_decode(
            $encoded,
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($statistics)
            || ($statistics['formatVersion'] ?? null) !== 1
            || !is_array($statistics['zones'] ?? null)
            || !array_is_list($statistics['zones'])
        ) {
            throw new RuntimeException(
                'Stored local-map statistics are invalid.'
            );
        }

        return $statistics;
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return array{svg: string, segmentCount: int, pointCount: int, statistics: array<string, mixed>}
     */
    private function renderStoredLocalMap(
        array $package,
        string $geometryKey
    ): array {
        $statistics = $this->restoreLocalMapStatistics($geometryKey);
        $scene = Navimow\LocalMapSceneProjector::build(
            $package['geometry'],
            $this->emptyLocalMapPath(),
            $statistics,
            $package['bindings'],
            [
                'currentGeometryKey' => $geometryKey,
                'acceptedGeometryKey' => $geometryKey,
                'pathGeometryKey' => $geometryKey,
                'statisticsGeometryKey' => $geometryKey,
                'frameCorrelationApproved' => true,
            ]
        );
        $state = $this->restoreLocalMapTrackState();
        $projection = Navimow\RevisionBoundedTrackStore::project($state);
        $scene['path'] = Navimow\RevisionBoundedTrackStore::scenePath(
            $state,
            $geometryKey
        );

        return [
            'svg' => Navimow\LocalMapSvgRenderer::render($scene, [
                'stationState' => $this->localMapStationState(),
                'mowerState' => $this->localMapMowerState(),
                'showMower' => false,
                'hiddenZoneSequences' => $this->hiddenZoneSequences(),
                'theme' => $this->localMapTheme(),
            ]),
            'segmentCount' => $projection['segmentCount'],
            'pointCount' => $projection['pointCount'],
            'statistics' => $statistics,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyLocalMapPath(): array
    {
        return [
            'formatVersion' => 1,
            'authority' => 'mqtt-inference',
            'coordinateFrame' => 'uncalibrated-local',
            'latest' => null,
            'segments' => [],
            'counters' => [],
            'policy' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function emptyLocalMapStatistics(): array
    {
        return [
            'formatVersion' => 1,
            'authority' => 'mqtt-inference',
            'percentageContract' => [
                'geometricCoveragePercent' => 'not-implemented',
            ],
            'zones' => [],
        ];
    }

    private function localMapStationState(): string
    {
        $lastUpdate = $this->GetValue('LastStatusUpdate');
        $online = $this->GetValue('Online');
        if (
            $online !== true
            || !is_int($lastUpdate)
            || $lastUpdate <= 0
            || $this->currentTimestamp() - $lastUpdate
                > self::LOCAL_MAP_REST_STALE_SECONDS
        ) {
            return 'unknown';
        }
        $state = $this->GetValue('VehicleState');
        if ($state === self::VEHICLE_STATE_DOCKED) {
            return 'docked';
        }
        if ($state === self::VEHICLE_STATE_DOCKING) {
            return 'docking';
        }
        if (is_int($state) && $state >= 1 && $state <= 10) {
            return 'undocked';
        }

        return 'unknown';
    }

    private function localMapMowerState(): string
    {
        $lastUpdate = $this->GetValue('LastStatusUpdate');
        $online = $this->GetValue('Online');
        if (
            !is_int($lastUpdate)
            || $lastUpdate <= 0
            || $this->currentTimestamp() - $lastUpdate
                > self::LOCAL_MAP_REST_STALE_SECONDS
        ) {
            return 'unknown';
        }

        $state = $this->GetValue('VehicleState');
        if ($state === self::VEHICLE_STATE_OFFLINE) {
            return 'offline';
        }
        if ($online !== true) {
            return 'unknown';
        }

        return match ($state) {
            1, 6 => 'active',
            3, 4, 10 => 'paused',
            self::VEHICLE_STATE_DOCKING => 'returning',
            7, 8 => 'attention',
            self::VEHICLE_STATE_DOCKED => 'docked',
            default => 'unknown',
        };
    }

    private function trackRetentionHours(): int
    {
        return max(1, min(
            720,
            $this->ReadPropertyInteger('TrackRetentionHours')
        ));
    }

    private function scheduleLocalMapRefresh(): void
    {
        if (!$this->localMapConfigurationIsValid()) {
            $this->SetTimerInterval('LocalMapRefresh', 0);
            return;
        }
        $active = $this->localMapStationState() !== 'docked'
            && $this->localMapStationState() !== 'unknown';
        $seconds = $active
            ? max(15, min(
                900,
                $this->ReadPropertyInteger('MapRefreshInterval')
            ))
            : max(60, min(
                3600,
                $this->ReadPropertyInteger('MapIdleRefreshInterval')
            ));
        $this->SetTimerInterval('LocalMapRefresh', $seconds * 1000);
    }

    private function writeLocalMapMetadata(
        string $status,
        string $geometryKey,
        int $segmentCount,
        int $pointCount
    ): void {
        $this->WriteAttributeString(
            'LocalMapRenderMetadata',
            json_encode([
                'formatVersion' => 1,
                'status' => substr($status, 0, 32),
                'geometryKey' => $geometryKey,
                'lastAttemptAt' => $this->currentTimestamp(),
                'segmentCount' => max(0, $segmentCount),
                'pointCount' => max(0, $pointCount),
            ], JSON_THROW_ON_ERROR)
        );
    }

    private function recordLocalMapError(string $message): void
    {
        $encoded = $this->ReadAttributeString('LocalMapErrorHistory');
        $history = json_decode($encoded, true, 16);
        if (!is_array($history) || !array_is_list($history)) {
            $history = [];
        }
        $history[] = [
            'timestamp' => $this->currentTimestamp(),
            'message' => $this->limitMessage($message),
            'context' => ['operation' => 'local-map-refresh'],
        ];
        $this->WriteAttributeString(
            'LocalMapErrorHistory',
            json_encode(
                array_slice(
                    $history,
                    -self::LOCAL_MAP_MAX_ERROR_ENTRIES
                ),
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function setLocalMapHidden(bool $hidden): void
    {
        try {
            $id = $this->GetIDForIdent('LocalMap');
            if ($id > 0 && function_exists('IPS_SetHidden')) {
                IPS_SetHidden($id, $hidden);
            }
        } catch (Throwable) {
        }
    }

    private function registerKernelStartMessage(): void
    {
        $registerMessage = [$this, 'Register' . 'Message'];
        if (is_callable($registerMessage)) {
            $registerMessage(0, $this->kernelStartedMessageId());
        }
    }

    private function kernelStartedMessageId(): int
    {
        if (!defined('IPS_KERNELSTARTED')) {
            return 10001;
        }
        $messageId = constant('IPS_KERNELSTARTED');

        return is_int($messageId) ? $messageId : 10001;
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
