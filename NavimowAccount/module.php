<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/ApiClient.php';
require_once __DIR__ . '/../libs/Navimow/CommandContract.php';
require_once __DIR__ . '/../libs/Navimow/MqttEnvelopeException.php';
require_once __DIR__ . '/../libs/Navimow/MqttEnvelopeParser.php';
require_once __DIR__ . '/../libs/Navimow/MqttCredentialMapper.php';
require_once __DIR__ . '/../libs/Navimow/MqttPartialStateAccumulator.php';
require_once __DIR__ . '/../libs/Navimow/MqttPayloadException.php';
require_once __DIR__ . '/../libs/Navimow/MqttPayloadParser.php';
require_once __DIR__ . '/../libs/Navimow/MqttTransportConfiguration.php';
require_once __DIR__ . '/../libs/Navimow/OAuthHelper.php';
require_once __DIR__ . '/../libs/Navimow/PayloadMapper.php';
require_once __DIR__ . '/../libs/Navimow/Profiles.php';

class NavimowAccount extends IPSModule
{
    private const DATA_INTERFACE = '{54620029-127D-470D-97C7-44265496FAA0}';
    private const MESSAGE_SCHEMA_VERSION = 1;
    private const LOGIN_URL = 'https://navimow-h5-fra.willand.com/smartHome/login';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 300;
    private const MINIMUM_REFRESH_DELAY_SECONDS = 60;
    private const TOKEN_REFRESH_RETRY_DELAY_SECONDS = 60;
    private const TOKEN_REFRESH_RETRY_MAX_ATTEMPTS = 5;
    private const SEMAPHORE_TIMEOUT_MILLISECONDS = 5000;
    private const WAKE_POLLING_WINDOW_SECONDS = 180;
    private const MINIMUM_ACTIVITY_EVIDENCE_TTL_SECONDS = 900;
    private const MAXIMUM_TRACKED_ACTIVE_DEVICES = 64;
    private const MQTT_RECEIVER_MODULE_ID =
        '{1B9960A2-A30C-D846-DF55-800F583AA812}';
    private const MQTT_CLIENT_MODULE_ID =
        '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    private const WEB_SOCKET_CLIENT_MODULE_ID =
        '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    private const MQTT_MAX_ENVELOPE_BYTES = 65536;
    private const MQTT_MAX_TRACKED_DEVICES = 64;
    private const MQTT_MAX_ERROR_ENTRIES = 20;
    private const MQTT_SEMAPHORE_TIMEOUT_MILLISECONDS = 1000;
    private const MQTT_RECONCILIATION_MINIMUM_SECONDS = 30;
    private const MQTT_RECONCILIATION_MAX_PER_RUN = 4;
    private const MQTT_COMPARISON_MAX_AGE_SECONDS = 300;
    private const MQTT_OWNERSHIP_FORMAT_VERSION = 2;
    private const MQTT_KEEP_ALIVE_SECONDS = 60;
    private const MQTT_LIFECYCLE_INITIAL_DELAY_SECONDS = 5;
    private const MQTT_KERNEL_RECONCILIATION_DELAY_SECONDS = 15;
    private const MQTT_LIFECYCLE_OBSERVATION_SECONDS = 60;
    private const MQTT_LIFECYCLE_HEALTHY_RESET_SECONDS = 900;
    private const MQTT_RECONNECT_DELAYS_SECONDS = [60, 300, 900];
    private const MQTT_MAX_DIAGNOSTIC_COUNTER = 2147483647;
    private const MQTT_DIAGNOSTIC_ATTRIBUTE_MAX_BYTES = 262144;
    private const MQTT_LIFECYCLE_DISABLED = 'Disabled';
    private const MQTT_LIFECYCLE_WAITING_FOR_AUTHENTICATION =
        'WaitingForAuthentication';
    private const MQTT_LIFECYCLE_WAITING_FOR_PAIRING = 'WaitingForPairing';
    private const MQTT_LIFECYCLE_READY = 'Ready';
    private const MQTT_LIFECYCLE_CONFIGURING = 'Configuring';
    private const MQTT_LIFECYCLE_CONNECTING = 'Connecting';
    private const MQTT_LIFECYCLE_SHADOW_ACTIVE = 'ShadowActive';
    private const MQTT_LIFECYCLE_RECONNECT_SCHEDULED =
        'ReconnectScheduled';
    private const MQTT_LIFECYCLE_DISCONNECTED = 'Disconnected';
    private const MQTT_LIFECYCLE_REAUTHENTICATION_REQUIRED =
        'ReauthenticationRequired';
    private const MQTT_LIFECYCLE_CONFIGURATION_ERROR =
        'ConfigurationError';

    private const VEHICLE_STATE_RUNNING = 1;
    private const VEHICLE_STATE_DOCKED = 2;
    private const VEHICLE_STATE_IDLE = 3;
    private const VEHICLE_STATE_PAUSED = 4;
    private const VEHICLE_STATE_DOCKING = 5;
    private const VEHICLE_STATE_MAPPING = 6;
    private const VEHICLE_STATE_LIFTED = 7;
    private const VEHICLE_STATE_ERROR = 8;
    private const VEHICLE_STATE_SOFTWARE_UPDATE = 9;
    private const VEHICLE_STATE_SELF_CHECKING = 10;

    private const STATE_UNCONFIGURED = 0;
    private const STATE_AUTHORIZATION_PENDING = 1;
    private const STATE_AUTHENTICATING = 2;
    private const STATE_CONNECTED = 3;
    private const STATE_API_WARNING = 4;
    private const STATE_REAUTH_REQUIRED = 5;
    private const STATE_OFFLINE = 6;
    private const STATE_CONFIGURATION_ERROR = 7;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('BaseUrl', 'https://navimow-fra.ninebot.com');
        $this->RegisterPropertyString('ClientId', 'homeassistant');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('RedirectUri', 'http://localhost:1/callback');
        $this->RegisterPropertyInteger('PollInterval', 300);
        $this->RegisterPropertyInteger('ActivePollInterval', 60);
        $this->RegisterPropertyBoolean('DebugPayloads', false);
        $this->RegisterPropertyBoolean('EnableMqttShadow', false);
        $this->RegisterPropertyInteger('MqttReceiverInstanceId', 0);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiresAtInternal', 0);
        $this->RegisterAttributeInteger('RefreshRetryCount', 0);
        $this->RegisterAttributeString('OAuthState', '');
        $this->RegisterAttributeString('DiscoveryCache', '[]');
        $this->RegisterAttributeInteger('WakePollingUntil', 0);
        $this->RegisterAttributeString('ActiveDeviceObservations', '[]');
        $this->RegisterAttributeString('MqttOwnershipRegistry', '{}');
        $this->RegisterAttributeString('MqttClientIdentity', '');
        $this->RegisterAttributeString('MqttLifecycleRegistry', '{}');
        $this->RegisterAttributeString('MqttStatistics', '{}');
        $this->RegisterAttributeString('MqttErrorHistory', '[]');
        $this->RegisterAttributeString('MqttShadowState', '{}');
        $this->RegisterAttributeString('MqttPendingReconciliation', '{}');
        $this->registerKernelStartMessage();

        $this->RegisterTimer(
            'PollStatus',
            0,
            'NAVAC_PollReadOnlyStatus($_IPS["TARGET"]);'
        );
        $this->RegisterTimer(
            'RefreshToken',
            0,
            'NAVAC_RefreshAuthentication($_IPS["TARGET"]);'
        );
        $this->RegisterTimer(
            'MqttReconcile',
            0,
            'NAVAC_ProcessMqttReconciliation($_IPS["TARGET"]);'
        );
        $this->RegisterTimer(
            'MqttLifecycle',
            0,
            'NAVAC_ProcessMqttLifecycle($_IPS["TARGET"]);'
        );
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        Navimow\Profiles::ensure();

        $this->RegisterVariableInteger('ConnectionState', 'Connection State', 'NAVIMOW.ConnectionState', 10);
        $this->RegisterVariableBoolean('ReauthRequired', 'Reauthentication Required', '', 20);
        $this->RegisterVariableInteger('TokenExpiresAt', 'Token Expires At', '~UnixTimestamp', 30);
        $this->RegisterVariableInteger('LastDiscovery', 'Last Discovery', '~UnixTimestamp', 40);
        $this->RegisterVariableInteger('LastRestSuccess', 'Last REST Success', '~UnixTimestamp', 50);
        $this->RegisterVariableInteger('RestErrorCount', 'REST Error Count', '', 60);

        $kernelReconciliationRequired =
            $this->mqttKernelReconciliationMustTakePrecedence();
        $this->clearMqttEphemeralState();
        $this->SetTimerInterval('MqttReconcile', 0);
        $this->SetTimerInterval('MqttLifecycle', 0);
        if (!$kernelReconciliationRequired) {
            if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
                $this->disconnectOwnedMqttTransportSafely();
            } elseif (
                $this->ReadAttributeString('MqttOwnershipRegistry')
                    !== '{}'
            ) {
                $this->disconnectOwnedMqttTransportSafely();
            }
            $this->initializeMqttLifecycle();
        }

        if (!$this->hasValidConfiguration()) {
            $this->SetTimerInterval('PollStatus', 0);
            $this->SetTimerInterval('RefreshToken', 0);
            $this->clearAdaptivePollingState();
            $this->setAuthenticationState(self::STATE_CONFIGURATION_ERROR, true);
            if ($kernelReconciliationRequired) {
                $this->continueMqttKernelReconciliation();
            }
            return;
        }

        if ($this->ReadAttributeString('AccessToken') === '') {
            $this->SetTimerInterval('PollStatus', 0);
            $this->SetTimerInterval('RefreshToken', 0);
            $this->clearAdaptivePollingState();
            $this->setAuthenticationState(self::STATE_AUTHORIZATION_PENDING, true);
            if ($kernelReconciliationRequired) {
                $this->continueMqttKernelReconciliation();
            }
            return;
        }

        $retryCount = $this->ReadAttributeInteger('RefreshRetryCount');
        $this->scheduleTokenRefresh();
        $this->schedulePolling();
        $this->setAuthenticationState(
            $retryCount > 0 ? self::STATE_OFFLINE : self::STATE_CONNECTED,
            false
        );
        if ($kernelReconciliationRequired) {
            $this->continueMqttKernelReconciliation();
            return;
        }
        $this->markCurrentKernelEpochReconciled();
        $this->scheduleMqttStartupIfReady();
    }

    public function MessageSink(
        $TimeStamp,
        $SenderID,
        $Message,
        $Data
    ) {
        if (
            $SenderID !== 0
            || $Message !== $this->kernelStartedMessageId()
        ) {
            return;
        }

        $this->scheduleMqttKernelReconciliation();
    }

    public function GetAuthorizationUrl(): string
    {
        if (!$this->hasValidConfiguration()) {
            throw new RuntimeException(
                'Save Base URL, client ID, client secret and redirect URI first.'
            );
        }

        $state = Navimow\OAuthHelper::createState();
        $this->WriteAttributeString('OAuthState', $state);
        $this->setAuthenticationState(self::STATE_AUTHORIZATION_PENDING, true);

        return Navimow\OAuthHelper::buildAuthorizationUrl(
            self::LOGIN_URL,
            $this->ReadPropertyString('ClientId'),
            $this->ReadPropertyString('RedirectUri'),
            $state
        );
    }

    public function ExchangeAuthorizationCode(string $authorizationInput): string
    {
        if (!$this->hasValidConfiguration()) {
            return 'Authentication configuration is incomplete.';
        }

        $lockName = $this->lockName();
        if (!IPS_SemaphoreEnter($lockName, self::SEMAPHORE_TIMEOUT_MILLISECONDS)) {
            return 'Another account operation is still running.';
        }

        try {
            $this->setAuthenticationState(self::STATE_AUTHENTICATING, true);

            $expectedState = filter_var(
                trim($authorizationInput),
                FILTER_VALIDATE_URL
            ) !== false
                ? $this->ReadAttributeString('OAuthState')
                : null;

            $code = Navimow\OAuthHelper::parseAuthorizationInput(
                $authorizationInput,
                $expectedState
            );

            $response = $this->createApiClient()->exchangeAuthorizationCode(
                $code,
                $this->ReadPropertyString('ClientId'),
                $this->ReadPropertyString('ClientSecret'),
                $this->ReadPropertyString('RedirectUri')
            );

            $this->throwForApiAuthError($response);
            $tokens = Navimow\PayloadMapper::parseTokenResponse($response);
            $this->storeTokenResponse($tokens, false);
            $this->WriteAttributeString('OAuthState', '');
            $this->SetValue('LastRestSuccess', $this->currentTimestamp());
            $this->schedulePolling();
            $this->setAuthenticationState(self::STATE_CONNECTED, false);
            $this->scheduleMqttStartupIfReady();

            return 'Authentication succeeded.';
        } catch (Throwable $exception) {
            $this->recordAuthenticationFailure($exception, false);
            return $this->sanitizedErrorMessage($exception);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function RefreshAuthentication(): string
    {
        if (!$this->hasValidConfiguration()) {
            $this->WriteAttributeInteger('RefreshRetryCount', 0);
            $this->SetTimerInterval('RefreshToken', 0);
            $this->setAuthenticationState(self::STATE_CONFIGURATION_ERROR, true);
            return 'Authentication configuration is incomplete.';
        }

        $refreshToken = $this->ReadAttributeString('RefreshToken');
        if ($refreshToken === '') {
            $this->WriteAttributeInteger('RefreshRetryCount', 0);
            $this->SetTimerInterval('RefreshToken', 0);
            $this->setAuthenticationState(self::STATE_REAUTH_REQUIRED, true);
            return 'No refresh token is available.';
        }

        $lockName = $this->lockName();
        if (!IPS_SemaphoreEnter($lockName, self::SEMAPHORE_TIMEOUT_MILLISECONDS)) {
            return 'Another account operation is still running.';
        }

        try {
            $this->setAuthenticationState(self::STATE_AUTHENTICATING, false);

            $response = $this->createApiClient()->refreshAccessToken(
                $refreshToken,
                $this->ReadPropertyString('ClientId'),
                $this->ReadPropertyString('ClientSecret')
            );

            $this->throwForApiAuthError($response);
            $tokens = Navimow\PayloadMapper::parseTokenResponse($response);
            $this->storeTokenResponse($tokens, true);
            $this->SetValue('LastRestSuccess', $this->currentTimestamp());
            $this->schedulePolling();
            $this->setAuthenticationState(self::STATE_CONNECTED, false);
            $this->scheduleMqttCredentialRotation();

            return 'Token refresh succeeded.';
        } catch (Throwable $exception) {
            $this->recordAuthenticationFailure($exception, true);
            $this->suspendMqttAfterAuthenticationFailure();
            return $this->sanitizedErrorMessage($exception);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function ResetAuthentication(): void
    {
        $this->disconnectOwnedMqttTransportSafely();
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpiresAtInternal', 0);
        $this->WriteAttributeInteger('RefreshRetryCount', 0);
        $this->WriteAttributeString('OAuthState', '');
        $this->clearAdaptivePollingState();
        $this->SetTimerInterval('RefreshToken', 0);
        $this->SetTimerInterval('PollStatus', 0);
        $this->SetTimerInterval('MqttReconcile', 0);
        $this->SetTimerInterval('MqttLifecycle', 0);
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_REAUTHENTICATION_REQUIRED
        );
        $this->SetValue('TokenExpiresAt', 0);
        $this->setAuthenticationState(
            $this->hasValidConfiguration()
                ? self::STATE_AUTHORIZATION_PENDING
                : self::STATE_UNCONFIGURED,
            true
        );
    }

    public function PollReadOnlyStatus(): void
    {
        if (!$this->hasUsableAccessToken()) {
            $this->SetTimerInterval('PollStatus', 0);
            $this->clearAdaptivePollingState();
            return;
        }

        try {
            $this->SendDataToChildren(json_encode([
                'DataID' => self::DATA_INTERFACE,
                'SchemaVersion' => self::MESSAGE_SCHEMA_VERSION,
                'Function' => 'PollStatus',
            ], JSON_THROW_ON_ERROR));
        } finally {
            $this->schedulePolling();
        }
    }

    public function WakePolling(): string
    {
        if (!$this->hasUsableAccessToken()) {
            $this->SetTimerInterval('PollStatus', 0);
            $this->clearAdaptivePollingState();
            return 'Status polling wake requires a usable access token.';
        }

        $this->WriteAttributeInteger(
            'WakePollingUntil',
            $this->currentTimestamp() + self::WAKE_POLLING_WINDOW_SECONDS
        );
        $this->schedulePolling();
        $this->PollReadOnlyStatus();

        return 'Status polling wake requested.';
    }

    public function ForwardData($JSONString)
    {
        try {
            $message = json_decode(
                $JSONString,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($message)) {
                throw new InvalidArgumentException('Message must be an object.');
            }

            if (($message['DataID'] ?? null) !== self::DATA_INTERFACE) {
                throw new InvalidArgumentException('Unsupported data interface.');
            }

            if (($message['SchemaVersion'] ?? null) !== self::MESSAGE_SCHEMA_VERSION) {
                throw new InvalidArgumentException('Unsupported message schema version.');
            }

            $function = $message['Function'] ?? null;
            if ($function === 'GetDiscovery') {
                return $this->encodeResult($this->performDiscovery());
            }

            if ($function === 'GetStatus') {
                $deviceId = $this->validateDeviceId($message['DeviceId'] ?? null);
                return $this->encodeResult($this->performStatus($deviceId));
            }

            if ($function === 'SendCommand') {
                $deviceId = $this->validateDeviceId($message['DeviceId'] ?? null);
                $command = $message['Command'] ?? null;
                if (!is_string($command)) {
                    throw new InvalidArgumentException('Command is invalid.');
                }

                return $this->encodeResult(
                    $this->performCommand($deviceId, $command)
                );
            }

            throw new InvalidArgumentException('Unsupported account function.');
        } catch (Throwable $exception) {
            $this->recordReadFailure($exception);

            return $this->encodeResult([
                'status' => 'error',
                'kind' => $exception instanceof Navimow\ApiException
                    ? $exception->getKind()
                    : 'protocol',
                'message' => $this->sanitizedErrorMessage($exception),
                'staleAfter' => $this->staleAfterSeconds(),
            ]);
        }
    }

    public function DiscoverDevices(): string
    {
        try {
            $result = $this->performDiscovery();

            return sprintf(
                'Discovery succeeded with %d device(s).',
                count($result['devices'])
            );
        } catch (Throwable $exception) {
            $this->recordReadFailure($exception);
            return $this->sanitizedErrorMessage($exception);
        }
    }

    public function ValidateMqttShadowConfiguration(): string
    {
        return $this->encodeResult(
            $this->inspectMqttShadowConfiguration()
        );
    }

    public function ValidateMqttAdoptionCandidate(): string
    {
        return $this->encodeResult(
            $this->inspectMqttAdoptionCandidate()
        );
    }

    public function GetMqttDiagnostics(): string
    {
        $lifecycle = $this->decodeMqttDiagnosticAttribute(
            'MqttLifecycleRegistry'
        );
        $statistics = $this->decodeMqttDiagnosticAttribute(
            'MqttStatistics'
        );
        $errors = $this->mqttDiagnosticErrors();
        $shadow = $this->decodeMqttDiagnosticAttribute(
            'MqttShadowState'
        );
        $pending = $this->decodeMqttDiagnosticAttribute(
            'MqttPendingReconciliation'
        );
        $validation = $this->inspectMqttShadowConfiguration();

        return $this->encodeResult([
            'formatVersion' => 1,
            'featureEnabled' =>
                $this->ReadPropertyBoolean('EnableMqttShadow'),
            'configurationStatus' => $this->mqttDiagnosticCode(
                $validation['status'] ?? null,
                ['disabled', 'ready', 'configuration-invalid']
            ),
            'lifecycle' => [
                'state' => $this->mqttDiagnosticCode(
                    $lifecycle['state'] ?? null,
                    [
                        self::MQTT_LIFECYCLE_DISABLED,
                        self::MQTT_LIFECYCLE_WAITING_FOR_AUTHENTICATION,
                        self::MQTT_LIFECYCLE_WAITING_FOR_PAIRING,
                        self::MQTT_LIFECYCLE_READY,
                        self::MQTT_LIFECYCLE_CONFIGURING,
                        self::MQTT_LIFECYCLE_CONNECTING,
                        self::MQTT_LIFECYCLE_SHADOW_ACTIVE,
                        self::MQTT_LIFECYCLE_RECONNECT_SCHEDULED,
                        self::MQTT_LIFECYCLE_DISCONNECTED,
                        self::MQTT_LIFECYCLE_REAUTHENTICATION_REQUIRED,
                        self::MQTT_LIFECYCLE_CONFIGURATION_ERROR,
                    ]
                ),
                'stateChangedAt' => $this->mqttDiagnosticInteger(
                    $lifecycle['stateChangedAt'] ?? null
                ),
                'lastResult' => $this->mqttDiagnosticCode(
                    $lifecycle['lastResult'] ?? null,
                    [
                        'pairing-rejected',
                        'oversized-envelope',
                        'busy',
                        'retained-rejected',
                        'accepted',
                        'reconciliation-queued',
                        'invalid-input',
                    ]
                ),
                'lastResultAt' => $this->mqttDiagnosticInteger(
                    $lifecycle['lastResultAt'] ?? null
                ),
                'lastCoreStatus' => $this->mqttDiagnosticInteger(
                    $lifecycle['lastCoreStatus'] ?? null
                ),
                'observedAt' => $this->mqttDiagnosticInteger(
                    $lifecycle['observedAt'] ?? null
                ),
                'lastTransitionReason' => $this->mqttDiagnosticCode(
                    $lifecycle['lastTransitionReason'] ?? null,
                    [
                        'authentication-restored',
                        'connection-attempt',
                        'connection-failed',
                        'core-resumed',
                        'core-disconnected',
                        'disabled',
                        'healthy',
                        'kernel-start-observed',
                        'kernel-start-awaiting-ready',
                        'manual-disconnect',
                        'reconnect-exhausted',
                        'restart-scheduled',
                        'token-rotation',
                    ]
                ),
                'healthySince' => $this->mqttDiagnosticInteger(
                    $lifecycle['healthySince'] ?? null
                ),
                'nextAttemptAt' => $this->mqttDiagnosticInteger(
                    $lifecycle['nextAttemptAt'] ?? null
                ),
                'reconnectAttempt' => $this->mqttDiagnosticInteger(
                    $lifecycle['reconnectAttempt'] ?? null
                ),
                'kernelStartObservedAt' => $this->mqttDiagnosticInteger(
                    $lifecycle['kernelStartObservedAt'] ?? null
                ),
                'kernelStartReconciledAt' => $this->mqttDiagnosticInteger(
                    $lifecycle['kernelStartReconciledAt'] ?? null
                ),
                'kernelStartTime' => $this->mqttDiagnosticInteger(
                    $lifecycle['kernelStartTime'] ?? null
                ),
                'lastKernelCoreClassification' =>
                    $this->mqttDiagnosticCode(
                        $lifecycle['lastKernelCoreClassification']
                            ?? null,
                        [
                            'healthy',
                            'credential-free',
                            'unhealthy-with-credentials',
                            'disabled',
                            'authentication-unavailable',
                            'configuration-invalid',
                            'ownership-invalid',
                        ]
                    ),
                'lastKernelCoreClassificationAt' =>
                    $this->mqttDiagnosticInteger(
                        $lifecycle['lastKernelCoreClassificationAt']
                            ?? null
                    ),
            ],
            'statistics' => [
                'connectionAttempts' => $this->mqttDiagnosticInteger(
                    $statistics['connectionAttempts'] ?? null
                ),
                'connectionSuccesses' => $this->mqttDiagnosticInteger(
                    $statistics['connectionSuccesses'] ?? null
                ),
                'connectionFailures' => $this->mqttDiagnosticInteger(
                    $statistics['connectionFailures'] ?? null
                ),
                'unexpectedDisconnects' => $this->mqttDiagnosticInteger(
                    $statistics['unexpectedDisconnects'] ?? null
                ),
                'reconnectAttempts' => $this->mqttDiagnosticInteger(
                    $statistics['reconnectAttempts'] ?? null
                ),
                'reconnectExhausted' => $this->mqttDiagnosticInteger(
                    $statistics['reconnectExhausted'] ?? null
                ),
                'credentialRotations' => $this->mqttDiagnosticInteger(
                    $statistics['credentialRotations'] ?? null
                ),
                'coreResumeObservations' => $this->mqttDiagnosticInteger(
                    $statistics['coreResumeObservations'] ?? null
                ),
                'received' => $this->mqttDiagnosticInteger(
                    $statistics['received'] ?? null
                ),
                'accepted' => $this->mqttDiagnosticInteger(
                    $statistics['accepted'] ?? null
                ),
                'rejected' => $this->mqttDiagnosticInteger(
                    $statistics['rejected'] ?? null
                ),
                'reconciliationAttempts' =>
                    $this->mqttDiagnosticInteger(
                        $statistics['reconciliationAttempts'] ?? null
                    ),
                'comparisonMatches' => $this->mqttDiagnosticInteger(
                    $statistics['comparisonMatches'] ?? null
                ),
                'comparisonMismatches' => $this->mqttDiagnosticInteger(
                    $statistics['comparisonMismatches'] ?? null
                ),
                'comparisonStale' => $this->mqttDiagnosticInteger(
                    $statistics['comparisonStale'] ?? null
                ),
                'lastConnectionAttemptAt' =>
                    $this->mqttDiagnosticInteger(
                        $statistics['lastConnectionAttemptAt'] ?? null
                    ),
                'lastConnectionTrigger' => $this->mqttDiagnosticCode(
                    $statistics['lastConnectionTrigger'] ?? null,
                    [
                        'manual',
                        'initial',
                        'kernel-fallback',
                        'reconnect',
                        'rotation',
                    ]
                ),
                'lastConnectionTriggerAt' =>
                    $this->mqttDiagnosticInteger(
                        $statistics['lastConnectionTriggerAt'] ?? null
                    ),
                'lastReceivedAt' => $this->mqttDiagnosticInteger(
                    $statistics['lastReceivedAt'] ?? null
                ),
                'lastReconciliationAt' =>
                    $this->mqttDiagnosticInteger(
                        $statistics['lastReconciliationAt'] ?? null
                    ),
                'lastComparisonAt' => $this->mqttDiagnosticInteger(
                    $statistics['lastComparisonAt'] ?? null
                ),
                'lastReconciliationResult' =>
                    $this->mqttDiagnosticCode(
                        $statistics['lastReconciliationResult'] ?? null,
                        [
                            'target-not-discovered',
                            'rest-wake-sent',
                            'handoff-failed',
                        ]
                    ),
                'lastComparisonResult' => $this->mqttDiagnosticCode(
                    $statistics['lastComparisonResult'] ?? null,
                    ['match', 'mismatch', 'stale']
                ),
            ],
            'errors' => $errors,
            'shadow' => [
                'trackedDeviceCount' => $this->mqttDiagnosticCount(
                    $shadow['devices'] ?? null,
                    self::MQTT_MAX_TRACKED_DEVICES
                ),
                'pendingReconciliationCount' =>
                    $this->mqttDiagnosticCount(
                        $pending['entries'] ?? null,
                        self::MQTT_MAX_TRACKED_DEVICES
                    ),
            ],
        ]);
    }

    public function AdoptMqttShadowChain(): string
    {
        return $this->withMqttLifecycleLock(function (): string {
            if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
                return 'MQTT shadow is disabled.';
            }
            if ($this->ReadAttributeString('MqttOwnershipRegistry') !== '{}') {
                $validation = $this->inspectMqttShadowConfiguration();
                return ($validation['valid'] ?? false)
                    ? 'MQTT chain is already adopted.'
                    : 'MQTT ownership validation failed.';
            }

            $candidate = $this->inspectMqttAdoptionCandidate();
            if (!($candidate['valid'] ?? false)) {
                return 'MQTT adoption candidate validation failed.';
            }

            try {
                $topology = $this->mqttTopology();
                $identity = $this->mqttClientIdentity();
                $this->writeMqttOwnership($topology, $identity, null);
                $this->setMqttLifecycleState(self::MQTT_LIFECYCLE_READY);
                return 'MQTT chain adopted.';
            } catch (Throwable) {
                $this->appendMqttError('adoption-failed');
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                );
                return 'MQTT adoption failed.';
            }
        });
    }

    public function ConnectMqttShadow(): string
    {
        return $this->withMqttLifecycleLock(
            fn (): string => $this->connectMqttShadowLocked('manual')
        );
    }

    private function connectMqttShadowLocked(
        string $connectionTrigger
    ): string {
        if ($this->mqttKernelReconciliationIsPending()) {
            return 'MQTT kernel reconciliation is pending.';
        }
        if (!$this->hasUsableAccessToken()) {
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_WAITING_FOR_AUTHENTICATION
            );
            return 'MQTT connection requires a usable access token.';
        }

        $validation = $this->inspectMqttShadowConfiguration();
        if (!($validation['enabled'] ?? false)) {
            return 'MQTT shadow is disabled.';
        }
        if (!($validation['valid'] ?? false)) {
            return 'MQTT ownership validation failed.';
        }

        $topology = $this->mqttTopology();
        $identity = $this->ReadAttributeString('MqttClientIdentity');
        $clientId = Navimow\MqttTransportConfiguration::clientId(
            $identity
        );
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_CONFIGURING,
            'connection-attempt'
        );
        $this->markCurrentKernelEpochReconciled();
        $this->recordMqttConnectionAttempt($connectionTrigger);
        $this->markMqttConnectionPending();

        try {
            $this->setCoreProperty(
                $topology['webSocketInstanceId'],
                'Active',
                false
            );
            $this->applyCoreChanges(
                $topology['webSocketInstanceId']
            );

            $response = $this->createApiClient()->getMqttUserInfo(
                $this->requireAccessToken()
            );
            $credentials = Navimow\MqttCredentialMapper::map(
                $response
            );

            $mqttId = $topology['mqttInstanceId'];
            $this->setCoreProperty(
                $mqttId,
                'UserName',
                $credentials['mqttUsername']
            );
            $this->setCoreProperty(
                $mqttId,
                'Password',
                $credentials['mqttPassword']
            );
            $this->setCoreProperty($mqttId, 'ClientID', $clientId);
            $this->setCoreProperty(
                $mqttId,
                'KeepAliveInterval',
                self::MQTT_KEEP_ALIVE_SECONDS
            );
            $this->setCoreProperty(
                $mqttId,
                'Subscriptions',
                $this->encodeResult($topology['expectedSubscriptions'])
            );
            $this->applyCoreChanges($mqttId);

            $webSocketId = $topology['webSocketInstanceId'];
            $this->setCoreProperty(
                $webSocketId,
                'URL',
                $credentials['wssUrl']
            );
            $this->setCoreProperty(
                $webSocketId,
                'Headers',
                Navimow\MqttTransportConfiguration::
                    authorizationHeaders(
                        $this->requireAccessToken()
                    )
            );
            $this->setCoreProperty($webSocketId, 'Type', 1);
            $this->setCoreProperty(
                $webSocketId,
                'VerifyCertificate',
                true
            );
            $this->setCoreProperty(
                $webSocketId,
                'Active',
                false
            );
            $this->applyCoreChanges($webSocketId);

            $configured = $this->mqttTopology();
            Navimow\MqttTransportConfiguration::
                assertInactiveConnectedShape(
                    $configured['mqttConfiguration'],
                    $configured['webSocketConfiguration'],
                    $configured['expectedSubscriptions'],
                    $clientId
                );
            $this->writeMqttOwnership(
                $configured,
                $identity,
                $this->mqttAdoptedAt()
            );

            $this->setCoreProperty($webSocketId, 'Active', true);
            $this->applyCoreChanges($webSocketId);
            $this->writeMqttOwnership(
                $this->mqttTopology(),
                $identity,
                $this->mqttAdoptedAt()
            );
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_CONNECTING,
                'connection-attempt'
            );
            $this->scheduleMqttObservation();
            unset($credentials);

            return 'MQTT connection attempt started.';
        } catch (Throwable $exception) {
            $this->rollbackMqttConnection($topology);
            $this->appendMqttError('connection-failed');
            $this->recordMqttStatistic('connectionFailures');
            $this->markMqttConnectionNotPending();
            $this->handleMqttConnectionFailure($exception);
            return 'MQTT connection attempt failed.';
        }
    }

    public function DisconnectMqttShadow(): string
    {
        return $this->withMqttLifecycleLock(function (): string {
            if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
                return 'MQTT shadow is disabled.';
            }
            if (!($this->inspectMqttShadowConfiguration()['valid'] ?? false)) {
                return 'MQTT ownership validation failed.';
            }

            try {
                $this->disconnectOwnedMqttTransport();
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_DISCONNECTED,
                    'manual-disconnect'
                );
                return 'MQTT transport disconnected.';
            } catch (Throwable) {
                $this->appendMqttError('disconnect-failed');
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                );
                return 'MQTT disconnect failed.';
            }
        });
    }

    public function ProcessMqttLifecycle(): void
    {
        $this->SetTimerInterval('MqttLifecycle', 0);
        $result = $this->withMqttLifecycleLock(function (): string {
            $this->processMqttLifecycleLocked();
            return 'processed';
        });
        if ($result !== 'processed') {
            $this->SetTimerInterval('MqttLifecycle', 5000);
        }
    }

    private function processMqttLifecycleLocked(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $now = $this->currentTimestamp();
        $scheduledKind = is_string(
            $lifecycle['scheduledKind'] ?? null
        ) ? $lifecycle['scheduledKind'] : '';
        $scheduledTrigger = is_string(
            $lifecycle['scheduledTrigger'] ?? null
        ) ? $lifecycle['scheduledTrigger'] : '';
        $nextAttemptAt = is_int(
            $lifecycle['nextAttemptAt'] ?? null
        ) ? $lifecycle['nextAttemptAt'] : 0;
        if ($scheduledKind === 'kernel-reconcile') {
            if ($nextAttemptAt > $now) {
                $this->SetTimerInterval(
                    'MqttLifecycle',
                    max(1, $nextAttemptAt - $now) * 1000
                );
                return;
            }
            $this->processMqttKernelReconciliationLocked($now);
            return;
        }

        $validation = $this->inspectMqttShadowConfiguration();
        if (!($validation['valid'] ?? false)) {
            return;
        }

        $topology = $this->mqttTopology();
        $mqttStatus = $this->mqttCoreStatus(
            $topology['mqttInstanceId']
        );
        $webSocketStatus = $this->mqttCoreStatus(
            $topology['webSocketInstanceId']
        );
        $webSocketActive =
            ($topology['webSocketConfiguration']['Active'] ?? null)
                === true;
        $lifecycle['lastCoreStatus'] = $mqttStatus;
        $lifecycle['lastWebSocketStatus'] = $webSocketStatus;
        $lifecycle['observedAt'] = $now;
        $this->writeMqttLifecycle($lifecycle);

        if (
            $mqttStatus === 102
            && $webSocketStatus === 102
            && $webSocketActive
        ) {
            $this->recordMqttHealthyObservation($now);
            if ($this->mqttCredentialRotationIsPending()) {
                $this->performMqttCredentialRotation();
                return;
            }
            $this->scheduleMqttObservation();
            return;
        }

        $scheduledKind = is_string(
            $lifecycle['scheduledKind'] ?? null
        ) ? $lifecycle['scheduledKind'] : '';
        $nextAttemptAt = is_int(
            $lifecycle['nextAttemptAt'] ?? null
        ) ? $lifecycle['nextAttemptAt'] : 0;
        if ($scheduledKind !== '' && $nextAttemptAt > $now) {
            $this->SetTimerInterval(
                'MqttLifecycle',
                max(1, $nextAttemptAt - $now) * 1000
            );
            return;
        }
        if (
            in_array(
                $scheduledKind,
                ['initial', 'reconnect', 'rotation'],
                true
            )
            && $nextAttemptAt > 0
        ) {
            if ($scheduledKind === 'reconnect') {
                if (!$this->beginMqttReconnectAttempt()) {
                    return;
                }
            } else {
                $this->clearMqttLifecycleSchedule();
            }
            $this->connectMqttShadowLocked(
                $this->mqttConnectionTrigger(
                    $scheduledTrigger,
                    $scheduledKind
                )
            );
            return;
        }

        $state = $lifecycle['state'] ?? null;
        if (
            in_array(
                $state,
                [
                    self::MQTT_LIFECYCLE_CONNECTING,
                    self::MQTT_LIFECYCLE_SHADOW_ACTIVE,
                ],
                true
            )
        ) {
            $this->recordMqttStatistic('unexpectedDisconnects');
            $this->appendMqttError('unexpected-disconnect');
            try {
                $this->disconnectOwnedMqttTransport();
            } catch (Throwable) {
                $this->appendMqttError('credential-cleanup-skipped');
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                );
                return;
            }
            $this->scheduleMqttReconnect('core-disconnected');
        }
    }

    public function IngestMqttEnvelope(
        int $receiverInstanceId,
        string $envelopeJson
    ): string {
        $validation = $this->inspectMqttShadowConfiguration();
        if (
            !($validation['enabled'] ?? false)
            || !($validation['valid'] ?? false)
            || ($validation['receiverInstanceId'] ?? 0)
                !== $receiverInstanceId
        ) {
            $this->recordMqttResult('pairing-rejected', true);
            return 'pairing-rejected';
        }
        if (strlen($envelopeJson) > self::MQTT_MAX_ENVELOPE_BYTES) {
            $this->recordMqttResult('oversized-envelope', true);
            return 'oversized-envelope';
        }

        $lockName = 'NAVIMOW.MQTT.SHADOW.' . $this->InstanceID;
        if (
            !IPS_SemaphoreEnter(
                $lockName,
                self::MQTT_SEMAPHORE_TIMEOUT_MILLISECONDS
            )
        ) {
            $this->recordMqttResult('busy', true);
            return 'busy';
        }

        try {
            $envelope = Navimow\MqttEnvelopeParser::parse($envelopeJson);
            if ($envelope['retained']) {
                $this->recordMqttResult('retained-rejected', true);
                return 'retained-rejected';
            }

            $deviceId = $this->deviceIdFromMqttTopic($envelope['topic']);
            $receivedAt = $this->currentTimestamp();
            $payload = Navimow\MqttPayloadParser::parse(
                $envelope['topic'],
                $envelope['payload'],
                $deviceId,
                $receivedAt
            );
            $result = $this->reduceMqttPayload(
                $payload,
                $deviceId,
                $receivedAt
            );
            if ($result === 'accepted') {
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_SHADOW_ACTIVE
                );
            }
            $this->recordMqttResult($result, false);

            return $result;
        } catch (
            Navimow\MqttEnvelopeException
            | Navimow\MqttPayloadException $exception
        ) {
            $this->appendMqttError('invalid-input');
            $this->recordMqttResult('invalid-input', true);
            return 'invalid-input';
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function ProcessMqttReconciliation(): void
    {
        $this->SetTimerInterval('MqttReconcile', 0);
        $validation = $this->inspectMqttShadowConfiguration();
        if (
            !($validation['enabled'] ?? false)
            || !($validation['valid'] ?? false)
            || !$this->hasUsableAccessToken()
        ) {
            return;
        }

        $pending = $this->decodeMqttAttribute(
            'MqttPendingReconciliation',
            ['formatVersion' => 1, 'entries' => []]
        );
        $entries = is_array($pending['entries'] ?? null)
            ? $pending['entries']
            : [];
        $now = $this->currentTimestamp();
        $dueKeys = $this->dueMqttReconciliationKeys($entries, $now);

        foreach (
            array_slice(
                $dueKeys,
                0,
                self::MQTT_RECONCILIATION_MAX_PER_RUN
            ) as $deviceKey
        ) {
            $entry = $entries[$deviceKey] ?? null;
            $deviceId = is_array($entry)
                ? ($entry['deviceId'] ?? null)
                : null;
            if (
                !is_string($deviceId)
                || !$this->isDiscoveredDevice($deviceId)
            ) {
                unset($entries[$deviceKey]);
                $this->recordMqttReconciliationResult(
                    'target-not-discovered'
                );
                continue;
            }

            try {
                $this->SendDataToChildren($this->encodeResult([
                    'DataID' => self::DATA_INTERFACE,
                    'SchemaVersion' => self::MESSAGE_SCHEMA_VERSION,
                    'Function' => 'PollStatus',
                    'DeviceId' => $deviceId,
                    'Reason' => 'mqtt-shadow-reconciliation',
                ]));
                $this->recordMqttRestWake($deviceKey, $now);
                $this->recordMqttReconciliationResult('rest-wake-sent');
            } catch (Throwable) {
                $this->appendMqttError('reconciliation-handoff-failed');
                $this->recordMqttReconciliationResult('handoff-failed');
            }
            unset($entries[$deviceKey]);
        }

        ksort($entries);
        $this->WriteAttributeString(
            'MqttPendingReconciliation',
            $this->encodeResult([
                'formatVersion' => 1,
                'entries' => $entries,
            ])
        );
        $this->scheduleMqttReconciliation();
    }

    protected function createApiClient(): Navimow\ApiClient
    {
        return new Navimow\ApiClient($this->ReadPropertyString('BaseUrl'));
    }

    protected function currentTimestamp(): int
    {
        return time();
    }

    protected function currentKernelStartTime(): int
    {
        $getKernelStartTime = $this->runtimeFunctionName(
            'IPS',
            'GetKernelStartTime'
        );
        if (!is_callable($getKernelStartTime)) {
            return 0;
        }
        $timestamp = $getKernelStartTime();

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : 0;
    }

    private function hasValidConfiguration(): bool
    {
        try {
            new Navimow\ApiClient($this->ReadPropertyString('BaseUrl'));
        } catch (Throwable) {
            return false;
        }

        return $this->ReadPropertyString('ClientId') !== ''
            && $this->ReadPropertyString('ClientSecret') !== ''
            && filter_var(
                $this->ReadPropertyString('RedirectUri'),
                FILTER_VALIDATE_URL
            ) !== false;
    }

    private function storeTokenResponse(array $tokens, bool $isRefresh): void
    {
        $refreshToken = $tokens['refreshToken'];
        if (($refreshToken === null || $refreshToken === '') && $isRefresh) {
            $refreshToken = $this->ReadAttributeString('RefreshToken');
        }

        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new UnexpectedValueException(
                'Token response does not contain a usable refresh token.'
            );
        }

        $expiresAt = $this->currentTimestamp() + $tokens['expiresIn'];

        $this->WriteAttributeString('AccessToken', $tokens['accessToken']);
        $this->WriteAttributeString('RefreshToken', $refreshToken);
        $this->WriteAttributeInteger('TokenExpiresAtInternal', $expiresAt);
        $this->WriteAttributeInteger('RefreshRetryCount', 0);
        $this->SetValue('TokenExpiresAt', $expiresAt);
        $this->scheduleTokenRefresh();
        $this->scheduleMqttReconciliation();
    }

    private function scheduleTokenRefresh(): void
    {
        $retryCount = $this->ReadAttributeInteger('RefreshRetryCount');
        if ($retryCount > 0) {
            $this->SetTimerInterval(
                'RefreshToken',
                $retryCount < self::TOKEN_REFRESH_RETRY_MAX_ATTEMPTS
                    ? self::TOKEN_REFRESH_RETRY_DELAY_SECONDS * 1000
                    : 0
            );
            return;
        }

        $expiresAt = $this->ReadAttributeInteger('TokenExpiresAtInternal');
        if ($expiresAt <= 0 || $this->ReadAttributeString('RefreshToken') === '') {
            $this->SetTimerInterval('RefreshToken', 0);
            return;
        }

        $remaining = $expiresAt - $this->currentTimestamp();
        $delay = $remaining > (self::TOKEN_REFRESH_MARGIN_SECONDS * 2)
            ? $remaining - self::TOKEN_REFRESH_MARGIN_SECONDS
            : (int) floor($remaining / 2);

        $delay = max(self::MINIMUM_REFRESH_DELAY_SECONDS, $delay);
        $this->SetTimerInterval('RefreshToken', $delay * 1000);
    }

    private function schedulePolling(): void
    {
        if (!$this->hasUsableAccessToken()) {
            $this->SetTimerInterval('PollStatus', 0);
            return;
        }

        $normalInterval = max(60, $this->ReadPropertyInteger('PollInterval'));
        $activeInterval = min(
            $normalInterval,
            max(60, $this->ReadPropertyInteger('ActivePollInterval'))
        );
        $interval = $this->isAdaptivePollingActive()
            ? $activeInterval
            : $normalInterval;
        $this->SetTimerInterval('PollStatus', $interval * 1000);
    }

    private function performDiscovery(): array
    {
        return $this->withAccountLock(function (): array {
            $response = $this->createApiClient()->getAuthorizedDevices(
                $this->requireAccessToken()
            );
            $this->assertApiSuccess($response);
            $devices = Navimow\PayloadMapper::mapDiscovery($response);

            $encoded = json_encode($devices, JSON_THROW_ON_ERROR);
            if (strlen($encoded) > 65536) {
                throw new UnexpectedValueException(
                    'Discovery result exceeded the size limit.'
                );
            }

            $now = $this->currentTimestamp();
            $this->WriteAttributeString('DiscoveryCache', $encoded);
            $this->SetValue('LastDiscovery', $now);
            $this->SetValue('LastRestSuccess', $now);
            $this->setAuthenticationState(self::STATE_CONNECTED, false);

            return [
                'status' => 'ok',
                'devices' => $devices,
                'receivedAt' => $now,
            ];
        });
    }

    private function performStatus(string $deviceId): array
    {
        return $this->withAccountLock(function () use ($deviceId): array {
            $response = $this->createApiClient()->getVehicleStatus(
                $this->requireAccessToken(),
                $deviceId
            );
            $this->assertApiSuccess($response);
            $status = Navimow\PayloadMapper::mapStatus($response, $deviceId);

            if ($status['vehicleStateSource'] === null) {
                throw new UnexpectedValueException(
                    'Status response did not contain the requested device.'
                );
            }

            $now = $this->currentTimestamp();
            $this->SetValue('LastRestSuccess', $now);
            $this->setAuthenticationState(self::STATE_CONNECTED, false);
            $this->recordDevicePollingState(
                $deviceId,
                (int) $status['vehicleState'],
                $now
            );
            $this->compareMqttShadowWithRest(
                $deviceId,
                $status,
                $now
            );

            return [
                'status' => 'ok',
                'deviceId' => $deviceId,
                'data' => $status,
                'receivedAt' => $now,
                'staleAfter' => $this->staleAfterSeconds(),
            ];
        });
    }

    private function performCommand(
        string $deviceId,
        string $command
    ): array {
        $payload = Navimow\CommandContract::createPayload(
            $command,
            $deviceId
        );

        return $this->withAccountLock(function () use (
            $deviceId,
            $payload
        ): array {
            $response = $this->createApiClient()->sendCommands(
                $this->requireAccessToken(),
                $payload
            );
            $this->assertApiSuccess($response);
            $result = Navimow\PayloadMapper::mapCommandResult(
                $response,
                $deviceId
            );

            $now = $this->currentTimestamp();
            $this->SetValue('LastRestSuccess', $now);
            $this->setAuthenticationState(self::STATE_CONNECTED, false);

            return [
                'status' => 'ok',
                'result' => $result['result'],
                'receivedAt' => $now,
            ];
        });
    }

    private function withAccountLock(callable $operation): array
    {
        $lockName = $this->lockName();
        if (!IPS_SemaphoreEnter($lockName, self::SEMAPHORE_TIMEOUT_MILLISECONDS)) {
            throw new Navimow\ApiException(
                'concurrency',
                'Another account operation is still running.'
            );
        }

        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function requireAccessToken(): string
    {
        if (!$this->hasUsableAccessToken()) {
            throw new Navimow\ApiException(
                'authentication',
                'A usable access token is not available.'
            );
        }

        return $this->ReadAttributeString('AccessToken');
    }

    private function hasUsableAccessToken(): bool
    {
        return $this->ReadAttributeString('AccessToken') !== ''
            && $this->ReadAttributeInteger('TokenExpiresAtInternal')
                > $this->currentTimestamp();
    }

    private function assertApiSuccess(array $response): void
    {
        $error = Navimow\PayloadMapper::mapApiError($response);
        if ($error['reauthRequired']) {
            throw new Navimow\ApiException(
                'authentication',
                'Navimow rejected the OAuth information.',
                200,
                $error['code']
            );
        }

        try {
            Navimow\PayloadMapper::assertApiSuccess($response);
        } catch (UnexpectedValueException $exception) {
            throw new Navimow\ApiException(
                'api',
                $this->sanitizedErrorMessage($exception),
                200,
                $error['code'],
                null,
                $exception
            );
        }
    }

    private function recordReadFailure(Throwable $exception): void
    {
        $this->SetValue(
            'RestErrorCount',
            $this->GetValue('RestErrorCount') + 1
        );

        $state = self::STATE_API_WARNING;
        $reauthRequired = false;
        if ($exception instanceof Navimow\ApiException) {
            if ($exception->getKind() === 'transport') {
                $state = self::STATE_OFFLINE;
            } elseif ($exception->getKind() === 'authentication') {
                $state = self::STATE_REAUTH_REQUIRED;
                $reauthRequired = true;
                $this->SetTimerInterval('PollStatus', 0);
            } elseif ($exception->getKind() === 'configuration') {
                $state = self::STATE_CONFIGURATION_ERROR;
            }
        }

        $this->setAuthenticationState($state, $reauthRequired);
        $this->SendDebug(
            'ReadFailure',
            sprintf(
                '%s: %s',
                get_class($exception),
                $this->sanitizedErrorMessage($exception)
            ),
            0
        );
    }

    private function validateDeviceId(mixed $deviceId): string
    {
        if (
            !is_string($deviceId)
            || $deviceId === ''
            || strlen($deviceId) > 128
        ) {
            throw new InvalidArgumentException('Device ID is invalid.');
        }

        return $deviceId;
    }

    private function staleAfterSeconds(): int
    {
        return max(300, max(60, $this->ReadPropertyInteger('PollInterval')) * 2);
    }

    private function recordDevicePollingState(
        string $deviceId,
        int $vehicleState,
        int $observedAt
    ): void {
        $observations = $this->activeDeviceObservations();
        $key = hash('sha256', $deviceId);
        $wasActive = array_key_exists($key, $observations);

        if ($this->isKnownActiveVehicleState($vehicleState)) {
            $observations[$key] = $observedAt;
        } else {
            unset($observations[$key]);
        }
        $observations = $this->limitActiveDeviceObservations($observations);

        if ($vehicleState === self::VEHICLE_STATE_DOCKED && $wasActive) {
            $this->WriteAttributeInteger('WakePollingUntil', 0);
        }

        $this->WriteAttributeString(
            'ActiveDeviceObservations',
            json_encode($observations, JSON_THROW_ON_ERROR)
        );
        $this->schedulePolling();
    }

    private function isAdaptivePollingActive(): bool
    {
        if ($this->ReadAttributeInteger('WakePollingUntil') > $this->currentTimestamp()) {
            return true;
        }

        return $this->activeDeviceObservations() !== [];
    }

    private function activeDeviceObservations(): array
    {
        $encoded = $this->ReadAttributeString('ActiveDeviceObservations');

        try {
            $decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $minimumTimestamp = $this->currentTimestamp()
            - max(
                self::MINIMUM_ACTIVITY_EVIDENCE_TTL_SECONDS,
                max(60, $this->ReadPropertyInteger('PollInterval')) * 4
            );
        $observations = [];
        foreach ($decoded as $key => $timestamp) {
            if (
                !is_string($key)
                || preg_match('/^[a-f0-9]{64}$/', $key) !== 1
                || !is_int($timestamp)
                || $timestamp < $minimumTimestamp
                || $timestamp > $this->currentTimestamp()
            ) {
                continue;
            }

            $observations[$key] = $timestamp;
        }

        $observations = $this->limitActiveDeviceObservations($observations);

        $normalized = json_encode($observations, JSON_THROW_ON_ERROR);
        if ($normalized !== $encoded) {
            $this->WriteAttributeString('ActiveDeviceObservations', $normalized);
        }

        return $observations;
    }

    private function limitActiveDeviceObservations(array $observations): array
    {
        uksort(
            $observations,
            static function (string $left, string $right) use ($observations): int {
                $timestampOrder = $observations[$right] <=> $observations[$left];

                return $timestampOrder !== 0
                    ? $timestampOrder
                    : strcmp($left, $right);
            }
        );
        $observations = array_slice(
            $observations,
            0,
            self::MAXIMUM_TRACKED_ACTIVE_DEVICES,
            true
        );
        ksort($observations);

        return $observations;
    }

    private function isKnownActiveVehicleState(int $vehicleState): bool
    {
        return in_array($vehicleState, [
            self::VEHICLE_STATE_RUNNING,
            self::VEHICLE_STATE_IDLE,
            self::VEHICLE_STATE_PAUSED,
            self::VEHICLE_STATE_DOCKING,
            self::VEHICLE_STATE_MAPPING,
            self::VEHICLE_STATE_LIFTED,
            self::VEHICLE_STATE_ERROR,
            self::VEHICLE_STATE_SOFTWARE_UPDATE,
            self::VEHICLE_STATE_SELF_CHECKING,
        ], true);
    }

    private function clearAdaptivePollingState(): void
    {
        $this->WriteAttributeInteger('WakePollingUntil', 0);
        $this->WriteAttributeString('ActiveDeviceObservations', '[]');
    }

    private function clearMqttEphemeralState(): void
    {
        $this->WriteAttributeString(
            'MqttShadowState',
            $this->encodeResult([
                'formatVersion' => 1,
                'devices' => [],
            ])
        );
        $this->WriteAttributeString(
            'MqttPendingReconciliation',
            $this->encodeResult([
                'formatVersion' => 1,
                'entries' => [],
            ])
        );
    }

    private function inspectMqttShadowConfiguration(): array
    {
        if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
            return [
                'enabled' => false,
                'valid' => true,
                'status' => 'disabled',
                'receiverInstanceId' => 0,
            ];
        }

        $receiverId = $this->ReadPropertyInteger(
            'MqttReceiverInstanceId'
        );
        try {
            $topology = $this->mqttTopology();
            $this->assertMqttOwnership($topology);

            return [
                'enabled' => true,
                'valid' => true,
                'status' => 'ready',
                'receiverInstanceId' => $receiverId,
            ];
        } catch (Throwable) {
            return $this->mqttValidationFailure(
                'configuration-invalid',
                $receiverId
            );
        }
    }

    private function inspectMqttAdoptionCandidate(): array
    {
        if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
            return [
                'enabled' => false,
                'valid' => false,
                'status' => 'disabled',
                'receiverInstanceId' => 0,
            ];
        }

        $receiverId = $this->ReadPropertyInteger(
            'MqttReceiverInstanceId'
        );
        try {
            $topology = $this->mqttTopology();
            Navimow\MqttTransportConfiguration::assertAdoptionCandidate(
                $topology['mqttConfiguration'],
                $topology['webSocketConfiguration']
            );

            return [
                'enabled' => true,
                'valid' => true,
                'status' => 'candidate-ready',
                'receiverInstanceId' => $receiverId,
            ];
        } catch (Throwable) {
            return $this->mqttValidationFailure(
                'candidate-invalid',
                $receiverId
            );
        }
    }

    private function mqttValidationFailure(string $status, int $receiverId): array
    {
        return [
            'enabled' => true,
            'valid' => false,
            'status' => $status,
            'receiverInstanceId' => max(0, $receiverId),
        ];
    }

    private function mqttTopology(): array
    {
        $receiverId = $this->ReadPropertyInteger(
            'MqttReceiverInstanceId'
        );
        $getProperty = $this->runtimeFunctionName(
            'IPS',
            'GetProperty'
        );
        if (
            !is_callable($getProperty)
            || !$this->instanceHasModule(
                $receiverId,
                self::MQTT_RECEIVER_MODULE_ID
            )
            || $getProperty($receiverId, 'AccountInstanceId')
                !== $this->InstanceID
        ) {
            throw new UnexpectedValueException(
                'MQTT receiver pairing is invalid.'
            );
        }

        $receiver = IPS_GetInstance($receiverId);
        $mqttId = (int) ($receiver['ConnectionID'] ?? 0);
        if (!$this->instanceHasModule($mqttId, self::MQTT_CLIENT_MODULE_ID)) {
            throw new UnexpectedValueException(
                'MQTT parent is invalid.'
            );
        }

        $mqtt = IPS_GetInstance($mqttId);
        $webSocketId = (int) ($mqtt['ConnectionID'] ?? 0);
        if (
            !$this->instanceHasModule(
                $webSocketId,
                self::WEB_SOCKET_CLIENT_MODULE_ID
            )
        ) {
            throw new UnexpectedValueException(
                'WebSocket parent is invalid.'
            );
        }

        $mqttConfiguration = $this->coreConfiguration($mqttId);
        $webSocketConfiguration = $this->coreConfiguration($webSocketId);
        $configuredSubscriptions =
            Navimow\MqttTransportConfiguration::configuredSubscriptions(
                $mqttConfiguration
            );
        $expectedSubscriptions =
            Navimow\MqttTransportConfiguration::createSubscriptions(
                $this->decodeMqttAttribute('DiscoveryCache', [])
            );
        Navimow\MqttTransportConfiguration::assertSubscriptionsMatch(
            $configuredSubscriptions,
            $expectedSubscriptions
        );

        return [
            'receiverInstanceId' => $receiverId,
            'mqttInstanceId' => $mqttId,
            'webSocketInstanceId' => $webSocketId,
            'mqttConfiguration' => $mqttConfiguration,
            'webSocketConfiguration' => $webSocketConfiguration,
            'expectedSubscriptions' => $expectedSubscriptions,
        ];
    }

    private function instanceHasModule(
        int $instanceId,
        string $moduleId
    ): bool {
        if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
            return false;
        }

        $instance = IPS_GetInstance($instanceId);

        return ($instance['ModuleInfo']['ModuleID'] ?? null) === $moduleId;
    }

    private function coreConfiguration(int $instanceId): array
    {
        $configuration = json_decode(
            IPS_GetConfiguration($instanceId),
            true,
            32
        );
        if (!is_array($configuration)) {
            throw new UnexpectedValueException(
                'Core configuration is malformed.'
            );
        }

        return $configuration;
    }

    private function assertMqttOwnership(array $topology): void
    {
        $identity = $this->ReadAttributeString('MqttClientIdentity');
        $clientId = Navimow\MqttTransportConfiguration::clientId(
            $identity
        );
        $ownership = $this->decodeMqttAttribute(
            'MqttOwnershipRegistry',
            []
        );
        $expectedModules = [
            'receiver' => self::MQTT_RECEIVER_MODULE_ID,
            'mqtt' => self::MQTT_CLIENT_MODULE_ID,
            'webSocket' => self::WEB_SOCKET_CLIENT_MODULE_ID,
        ];
        if (
            ($ownership['formatVersion'] ?? null)
                !== self::MQTT_OWNERSHIP_FORMAT_VERSION
            || ($ownership['receiverInstanceId'] ?? null)
                !== $topology['receiverInstanceId']
            || ($ownership['mqttInstanceId'] ?? null)
                !== $topology['mqttInstanceId']
            || ($ownership['webSocketInstanceId'] ?? null)
                !== $topology['webSocketInstanceId']
            || ($ownership['moduleGuids'] ?? null) !== $expectedModules
            || ($ownership['connectionOrder'] ?? null)
                !== ['receiver', 'mqtt', 'webSocket']
            || ($ownership['accountBinding'] ?? null)
                !== $this->mqttAccountBinding()
            || ($ownership['subscriptionConfigurationHash'] ?? null)
                !== Navimow\MqttTransportConfiguration::
                    subscriptionShapeHash(
                        $topology['expectedSubscriptions']
                    )
            || ($ownership['transportConfigurationHash'] ?? null)
                !== Navimow\MqttTransportConfiguration::
                    transportShapeHash(
                        $topology['mqttConfiguration'],
                        $topology['webSocketConfiguration'],
                        $topology['expectedSubscriptions'],
                        $clientId
                    )
            || ($ownership['clientIdentityHash'] ?? null)
                !== hash('sha256', $identity)
            || !is_int($ownership['adoptedAt'] ?? null)
            || $ownership['adoptedAt'] <= 0
        ) {
            throw new UnexpectedValueException(
                'MQTT ownership is invalid.'
            );
        }
    }

    private function writeMqttOwnership(
        array $topology,
        string $identity,
        ?int $adoptedAt
    ): void {
        $clientId = Navimow\MqttTransportConfiguration::clientId(
            $identity
        );
        $this->WriteAttributeString(
            'MqttOwnershipRegistry',
            $this->encodeResult([
                'formatVersion' => self::MQTT_OWNERSHIP_FORMAT_VERSION,
                'receiverInstanceId' => $topology['receiverInstanceId'],
                'mqttInstanceId' => $topology['mqttInstanceId'],
                'webSocketInstanceId' =>
                    $topology['webSocketInstanceId'],
                'moduleGuids' => [
                    'receiver' => self::MQTT_RECEIVER_MODULE_ID,
                    'mqtt' => self::MQTT_CLIENT_MODULE_ID,
                    'webSocket' => self::WEB_SOCKET_CLIENT_MODULE_ID,
                ],
                'connectionOrder' => [
                    'receiver',
                    'mqtt',
                    'webSocket',
                ],
                'accountBinding' => $this->mqttAccountBinding(),
                'subscriptionConfigurationHash' =>
                    Navimow\MqttTransportConfiguration::
                        subscriptionShapeHash(
                            $topology['expectedSubscriptions']
                        ),
                'transportConfigurationHash' =>
                    Navimow\MqttTransportConfiguration::
                        transportShapeHash(
                            $topology['mqttConfiguration'],
                            $topology['webSocketConfiguration'],
                            $topology['expectedSubscriptions'],
                            $clientId
                        ),
                'clientIdentityHash' => hash('sha256', $identity),
                'adoptedAt' => $adoptedAt ?? $this->currentTimestamp(),
            ])
        );
    }

    private function mqttClientIdentity(): string
    {
        $identity = $this->ReadAttributeString('MqttClientIdentity');
        if ($identity === '') {
            $identity = bin2hex(random_bytes(16));
            $this->WriteAttributeString('MqttClientIdentity', $identity);
        }
        Navimow\MqttTransportConfiguration::clientId($identity);

        return $identity;
    }

    private function mqttAdoptedAt(): int
    {
        $ownership = $this->decodeMqttAttribute(
            'MqttOwnershipRegistry',
            []
        );
        $adoptedAt = $ownership['adoptedAt'] ?? null;
        if (!is_int($adoptedAt) || $adoptedAt <= 0) {
            throw new UnexpectedValueException(
                'MQTT adoption timestamp is invalid.'
            );
        }

        return $adoptedAt;
    }

    private function mqttAccountBinding(): string
    {
        return hash(
            'sha256',
            'navimow-account:' . $this->InstanceID
        );
    }

    private function setCoreProperty(
        int $instanceId,
        string $name,
        mixed $value
    ): void {
        $setProperty = $this->runtimeFunctionName(
            'IPS',
            'SetProperty'
        );
        if (!is_callable($setProperty)) {
            throw new RuntimeException(
                'Core property mutation is unavailable.'
            );
        }
        $setProperty($instanceId, $name, $value);
    }

    private function applyCoreChanges(int $instanceId): void
    {
        $applyChanges = $this->runtimeFunctionName(
            'IPS',
            'ApplyChanges'
        );
        if (!is_callable($applyChanges)) {
            throw new RuntimeException(
                'Core ApplyChanges is unavailable.'
            );
        }
        $applyChanges($instanceId);
    }

    private function withMqttLifecycleLock(Closure $operation): string
    {
        $lockName = 'NAVIMOW.MQTT.LIFECYCLE.' . $this->InstanceID;
        if (
            !IPS_SemaphoreEnter(
                $lockName,
                self::SEMAPHORE_TIMEOUT_MILLISECONDS
            )
        ) {
            return 'Another MQTT lifecycle operation is running.';
        }

        try {
            return $operation();
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    private function disconnectOwnedMqttTransport(): void
    {
        $topology = $this->mqttTopology();
        $this->assertMqttOwnership($topology);
        $webSocketId = $topology['webSocketInstanceId'];
        $mqttId = $topology['mqttInstanceId'];

        $this->setCoreProperty($webSocketId, 'Active', false);
        $this->applyCoreChanges($webSocketId);
        $this->setCoreProperty($webSocketId, 'Headers', '[]');
        $this->setCoreProperty($mqttId, 'UserName', '');
        $this->setCoreProperty($mqttId, 'Password', '');
        $this->applyCoreChanges($mqttId);
        $this->applyCoreChanges($webSocketId);

        $identity = $this->ReadAttributeString('MqttClientIdentity');
        $this->writeMqttOwnership(
            $this->mqttTopology(),
            $identity,
            $this->mqttAdoptedAt()
        );
        $this->clearMqttEphemeralState();
        $this->SetTimerInterval('MqttReconcile', 0);
        $this->SetTimerInterval('MqttLifecycle', 0);
        $this->clearMqttLifecycleSchedule();
        $this->setMqttLifecycleState(
            $this->ReadPropertyBoolean('EnableMqttShadow')
                ? self::MQTT_LIFECYCLE_DISCONNECTED
                : self::MQTT_LIFECYCLE_DISABLED,
            $this->ReadPropertyBoolean('EnableMqttShadow')
                ? null
                : 'disabled'
        );
    }

    private function disconnectOwnedMqttTransportSafely(): void
    {
        if ($this->ReadAttributeString('MqttOwnershipRegistry') === '{}') {
            return;
        }
        try {
            $this->disconnectOwnedMqttTransport();
        } catch (Throwable) {
            $this->appendMqttError('credential-cleanup-skipped');
        }
    }

    private function rollbackMqttConnection(array $topology): void
    {
        try {
            $webSocketId = $topology['webSocketInstanceId'];
            $mqttId = $topology['mqttInstanceId'];
            $this->setCoreProperty($webSocketId, 'Active', false);
            $this->applyCoreChanges($webSocketId);
            $this->setCoreProperty($webSocketId, 'Headers', '[]');
            $this->setCoreProperty($mqttId, 'UserName', '');
            $this->setCoreProperty($mqttId, 'Password', '');
            $this->applyCoreChanges($mqttId);
            $this->applyCoreChanges($webSocketId);
            $this->writeMqttOwnership(
                $this->mqttTopology(),
                $this->ReadAttributeString('MqttClientIdentity'),
                $this->mqttAdoptedAt()
            );
        } catch (Throwable) {
            $this->appendMqttError('connection-rollback-failed');
        }
    }

    private function initializeMqttLifecycle(): void
    {
        if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_DISABLED
            );
            return;
        }
        if (!$this->hasUsableAccessToken()) {
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_WAITING_FOR_AUTHENTICATION
            );
            return;
        }
        if ($this->ReadAttributeString('MqttOwnershipRegistry') === '{}') {
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_WAITING_FOR_PAIRING
            );
            return;
        }
        if ($this->inspectMqttShadowConfiguration()['valid'] ?? false) {
            $state = $this->mqttLifecycle()['state'] ?? null;
            if (
                !in_array(
                    $state,
                    [
                        self::MQTT_LIFECYCLE_CONNECTING,
                        self::MQTT_LIFECYCLE_SHADOW_ACTIVE,
                        self::MQTT_LIFECYCLE_RECONNECT_SCHEDULED,
                        self::MQTT_LIFECYCLE_DISCONNECTED,
                    ],
                    true
                )
            ) {
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_READY
                );
            }
            return;
        }
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
        );
    }

    private function mqttLifecycle(): array
    {
        return $this->decodeMqttAttribute(
            'MqttLifecycleRegistry',
            ['formatVersion' => 1]
        );
    }

    private function mqttKernelReconciliationMustTakePrecedence(): bool
    {
        if (
            !$this->ReadPropertyBoolean('EnableMqttShadow')
            || $this->ReadAttributeString('MqttOwnershipRegistry') === '{}'
        ) {
            return false;
        }

        $currentKernelStartTime = $this->currentKernelStartTime();
        if ($currentKernelStartTime <= 0) {
            return false;
        }
        $lifecycle = $this->mqttLifecycle();
        $recordedKernelStartTime = $this->mqttDiagnosticInteger(
            $lifecycle['kernelStartTime'] ?? null
        );
        if (
            $recordedKernelStartTime === $currentKernelStartTime
            && in_array(
                $lifecycle['scheduledKind'] ?? null,
                ['kernel-await-message', 'kernel-reconcile'],
                true
            )
            && $this->mqttDiagnosticInteger(
                $lifecycle['kernelStartReconciledAt'] ?? null
            ) === 0
        ) {
            return true;
        }

        return $recordedKernelStartTime > 0
            && $recordedKernelStartTime !== $currentKernelStartTime;
    }

    private function continueMqttKernelReconciliation(): void
    {
        if ($this->mqttKernelReconciliationIsPending()) {
            $this->scheduleMqttKernelReconciliation();
            return;
        }
        $this->markMqttKernelReconciliationAwaitingMessage();
    }

    private function mqttKernelReconciliationIsPending(): bool
    {
        $kernelStartTime = $this->currentKernelStartTime();
        if ($kernelStartTime <= 0) {
            return false;
        }
        $lifecycle = $this->mqttLifecycle();

        return ($lifecycle['kernelStartTime'] ?? null) === $kernelStartTime
            && ($lifecycle['scheduledKind'] ?? null) === 'kernel-reconcile'
            && $this->mqttDiagnosticInteger(
                $lifecycle['kernelStartReconciledAt'] ?? null
            ) === 0;
    }

    private function markCurrentKernelEpochReconciled(): void
    {
        if (
            !$this->ReadPropertyBoolean('EnableMqttShadow')
            || $this->ReadAttributeString('MqttOwnershipRegistry') === '{}'
        ) {
            return;
        }
        $kernelStartTime = $this->currentKernelStartTime();
        if ($kernelStartTime <= 0) {
            return;
        }
        $lifecycle = $this->mqttLifecycle();
        if (
            ($lifecycle['kernelStartTime'] ?? null) === $kernelStartTime
            && $this->mqttDiagnosticInteger(
                $lifecycle['kernelStartReconciledAt'] ?? null
            ) > 0
        ) {
            return;
        }
        $lifecycle['formatVersion'] = 1;
        $lifecycle['kernelStartTime'] = $kernelStartTime;
        $lifecycle['kernelStartObservedAt'] = $this->currentTimestamp();
        $lifecycle['kernelStartReconciledAt'] = $this->currentTimestamp();
        $this->writeMqttLifecycle($lifecycle);
    }

    private function markMqttKernelReconciliationAwaitingMessage(): void
    {
        $kernelStartTime = $this->currentKernelStartTime();
        if ($kernelStartTime <= 0) {
            return;
        }
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['kernelStartTime'] = $kernelStartTime;
        $lifecycle['kernelStartObservedAt'] = 0;
        $lifecycle['kernelStartReconciledAt'] = 0;
        $lifecycle['scheduledKind'] = 'kernel-await-message';
        $lifecycle['scheduledTrigger'] = '';
        $lifecycle['nextAttemptAt'] = 0;
        $lifecycle['lastTransitionReason'] =
            'kernel-start-awaiting-ready';
        $this->writeMqttLifecycle($lifecycle);
        $this->SetTimerInterval('MqttLifecycle', 0);
    }

    private function scheduleMqttStartupIfReady(): void
    {
        if (
            !$this->ReadPropertyBoolean('EnableMqttShadow')
            || !$this->hasUsableAccessToken()
            || $this->ReadAttributeString('MqttOwnershipRegistry') === '{}'
            || !($this->inspectMqttShadowConfiguration()['valid'] ?? false)
        ) {
            return;
        }
        if ($this->mqttKernelReconciliationIsPending()) {
            return;
        }
        if ($this->mqttKernelReconciliationMustTakePrecedence()) {
            if (
                ($this->mqttLifecycle()['scheduledKind'] ?? null)
                    !== 'kernel-await-message'
            ) {
                $this->markMqttKernelReconciliationAwaitingMessage();
            }
            return;
        }
        $topology = $this->mqttTopology();
        if (!$this->mqttTransportIsCredentialFree($topology)) {
            $this->appendMqttError('credential-cleanup-skipped');
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
            );
            return;
        }
        $this->resetMqttReconnectEpisode();
        $this->scheduleMqttLifecycleAttempt(
            'initial',
            self::MQTT_LIFECYCLE_INITIAL_DELAY_SECONDS,
            'restart-scheduled',
            'initial'
        );
    }

    private function registerKernelStartMessage(): void
    {
        $registerMessage = [$this, 'Register' . 'Message'];
        if (!is_callable($registerMessage)) {
            return;
        }
        $registerMessage(0, $this->kernelStartedMessageId());
    }

    private function kernelStartedMessageId(): int
    {
        if (!defined('IPS_KERNELSTARTED')) {
            return 10001;
        }
        $messageId = constant('IPS_KERNELSTARTED');

        return is_int($messageId) ? $messageId : 10001;
    }

    private function scheduleMqttKernelReconciliation(): void
    {
        $kernelStartTime = $this->currentKernelStartTime();
        if ($kernelStartTime <= 0) {
            return;
        }

        $lifecycle = $this->mqttLifecycle();
        if (
            ($lifecycle['kernelStartTime'] ?? null) === $kernelStartTime
        ) {
            if (
                ($lifecycle['scheduledKind'] ?? null)
                    === 'kernel-reconcile'
            ) {
                $nextAttemptAt = $this->mqttDiagnosticInteger(
                    $lifecycle['nextAttemptAt'] ?? null
                );
                $this->SetTimerInterval(
                    'MqttLifecycle',
                    max(
                        1,
                        $nextAttemptAt - $this->currentTimestamp()
                    ) * 1000
                );
                return;
            }
            if (
                $this->mqttDiagnosticInteger(
                    $lifecycle['kernelStartReconciledAt'] ?? null
                ) > 0
            ) {
                return;
            }
        }

        $now = $this->currentTimestamp();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['kernelStartTime'] = $kernelStartTime;
        $lifecycle['kernelStartObservedAt'] = $now;
        $lifecycle['kernelStartReconciledAt'] = 0;
        $lifecycle['scheduledKind'] = 'kernel-reconcile';
        $lifecycle['scheduledTrigger'] = '';
        $lifecycle['nextAttemptAt'] =
            $now + self::MQTT_KERNEL_RECONCILIATION_DELAY_SECONDS;
        $lifecycle['lastTransitionReason'] = 'kernel-start-observed';
        $this->writeMqttLifecycle($lifecycle);
        $this->SetTimerInterval(
            'MqttLifecycle',
            self::MQTT_KERNEL_RECONCILIATION_DELAY_SECONDS * 1000
        );
    }

    private function processMqttKernelReconciliationLocked(int $now): void
    {
        if (!$this->ReadPropertyBoolean('EnableMqttShadow')) {
            $this->recordMqttKernelCoreClassification('disabled', $now);
            $this->clearMqttCredentialRotationPending();
            $this->reconcileDisabledMqttAfterKernelStart($now);
            return;
        }
        if (!$this->hasValidConfiguration()) {
            $this->recordMqttKernelCoreClassification(
                'configuration-invalid',
                $now
            );
            $this->clearMqttCredentialRotationPending();
            if (
                $this->ReadAttributeString('MqttOwnershipRegistry') !== '{}'
            ) {
                try {
                    $this->disconnectOwnedMqttTransport();
                } catch (Throwable) {
                    $this->recordMqttKernelCoreClassification(
                        'ownership-invalid',
                        $now
                    );
                    $this->appendMqttError(
                        'credential-cleanup-skipped'
                    );
                    $this->markMqttKernelReconciled($now);
                    $this->setMqttLifecycleState(
                        self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                    );
                    return;
                }
            }
            $this->markMqttKernelReconciled($now);
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
            );
            return;
        }
        if (!$this->hasUsableAccessToken()) {
            $this->recordMqttKernelCoreClassification(
                'authentication-unavailable',
                $now
            );
            $this->clearMqttCredentialRotationPending();
            if (
                $this->ReadAttributeString('MqttOwnershipRegistry') !== '{}'
            ) {
                try {
                    $this->disconnectOwnedMqttTransport();
                } catch (Throwable) {
                    $this->recordMqttKernelCoreClassification(
                        'ownership-invalid',
                        $now
                    );
                    $this->appendMqttError(
                        'credential-cleanup-skipped'
                    );
                    $this->markMqttKernelReconciled($now);
                    $this->setMqttLifecycleState(
                        self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                    );
                    return;
                }
            }
            $this->markMqttKernelReconciled($now);
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_WAITING_FOR_AUTHENTICATION
            );
            return;
        }

        $validation = $this->inspectMqttShadowConfiguration();
        if (!($validation['valid'] ?? false)) {
            $this->recordMqttKernelCoreClassification(
                $this->mqttKernelConfigurationFailureClassification(),
                $now
            );
            $this->clearMqttCredentialRotationPending();
            $this->markMqttKernelReconciled($now);
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
            );
            return;
        }

        $topology = $this->mqttTopology();
        if ($this->mqttCoreIsHealthy($topology)) {
            $this->recordMqttKernelCoreClassification('healthy', $now);
            $this->adoptMqttCoreResume($now);
            return;
        }
        if ($this->mqttTransportIsCredentialFree($topology)) {
            $this->recordMqttKernelCoreClassification(
                'credential-free',
                $now
            );
            $this->clearMqttCredentialRotationPending();
            $this->markMqttKernelReconciled($now);
            $this->resetMqttReconnectEpisode();
            $this->scheduleMqttLifecycleAttempt(
                'initial',
                self::MQTT_LIFECYCLE_INITIAL_DELAY_SECONDS,
                'restart-scheduled',
                'kernel-fallback'
            );
            return;
        }

        $this->recordMqttKernelCoreClassification(
            'unhealthy-with-credentials',
            $now
        );
        $this->clearMqttCredentialRotationPending();
        $this->recordMqttStatistic('unexpectedDisconnects');
        $this->appendMqttError('unexpected-disconnect');
        try {
            $this->disconnectOwnedMqttTransport();
        } catch (Throwable) {
            $this->appendMqttError('credential-cleanup-skipped');
            $this->markMqttKernelReconciled($now);
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
            );
            return;
        }
        $this->markMqttKernelReconciled($now);
        $this->scheduleMqttReconnect('core-disconnected');
    }

    private function reconcileDisabledMqttAfterKernelStart(int $now): void
    {
        if ($this->ReadAttributeString('MqttOwnershipRegistry') !== '{}') {
            try {
                $topology = $this->mqttTopology();
                $this->assertMqttOwnership($topology);
                if (!$this->mqttTransportIsCredentialFree($topology)) {
                    $this->disconnectOwnedMqttTransport();
                }
            } catch (Throwable) {
                $this->appendMqttError('credential-cleanup-skipped');
                $this->markMqttKernelReconciled($now);
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                );
                return;
            }
        }

        $this->markMqttKernelReconciled($now);
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_DISABLED,
            'disabled'
        );
    }

    private function markMqttKernelReconciled(int $timestamp): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['kernelStartReconciledAt'] = $timestamp;
        $lifecycle['scheduledKind'] = '';
        $lifecycle['scheduledTrigger'] = '';
        $lifecycle['nextAttemptAt'] = 0;
        $lifecycle['connectionPending'] = false;
        $this->writeMqttLifecycle($lifecycle);
        $this->SetTimerInterval('MqttLifecycle', 0);
    }

    private function adoptMqttCoreResume(int $timestamp): void
    {
        $this->markMqttKernelReconciled($timestamp);
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['healthySince'] = $timestamp;
        $this->writeMqttLifecycle($lifecycle);
        $this->recordMqttStatistic('coreResumeObservations');
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_SHADOW_ACTIVE,
            'core-resumed'
        );
        $this->scheduleMqttObservation();
    }

    private function scheduleMqttCredentialRotation(): void
    {
        if (
            !$this->ReadPropertyBoolean('EnableMqttShadow')
            || !$this->hasUsableAccessToken()
            || $this->ReadAttributeString('MqttOwnershipRegistry') === '{}'
            || !($this->inspectMqttShadowConfiguration()['valid'] ?? false)
        ) {
            return;
        }
        if (
            $this->mqttKernelReconciliationIsPending()
            || $this->mqttKernelReconciliationMustTakePrecedence()
        ) {
            $this->markMqttCredentialRotationPending();
            if (
                !$this->mqttKernelReconciliationIsPending()
                && ($this->mqttLifecycle()['scheduledKind'] ?? null)
                    !== 'kernel-await-message'
            ) {
                $this->markMqttKernelReconciliationAwaitingMessage();
            }
            return;
        }
        $this->performMqttCredentialRotation();
    }

    private function performMqttCredentialRotation(): void
    {
        try {
            $this->clearMqttCredentialRotationPending();
            $this->disconnectOwnedMqttTransport();
            $this->recordMqttStatistic('credentialRotations');
            $this->resetMqttReconnectEpisode();
            $this->scheduleMqttLifecycleAttempt(
                'rotation',
                self::MQTT_LIFECYCLE_INITIAL_DELAY_SECONDS,
                'token-rotation',
                'rotation'
            );
        } catch (Throwable) {
            $this->appendMqttError('credential-cleanup-skipped');
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
            );
        }
    }

    private function suspendMqttAfterAuthenticationFailure(): void
    {
        if (
            !$this->ReadPropertyBoolean('EnableMqttShadow')
            || $this->ReadAttributeString('MqttOwnershipRegistry') === '{}'
        ) {
            return;
        }
        $this->disconnectOwnedMqttTransportSafely();
        $this->SetTimerInterval('MqttLifecycle', 0);
        $this->setMqttLifecycleState(
            $this->GetValue('ReauthRequired')
                ? self::MQTT_LIFECYCLE_REAUTHENTICATION_REQUIRED
                : self::MQTT_LIFECYCLE_WAITING_FOR_AUTHENTICATION
        );
    }

    private function scheduleMqttObservation(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['scheduledKind'] = 'observe';
        $lifecycle['scheduledTrigger'] = '';
        $lifecycle['nextAttemptAt'] = $this->currentTimestamp()
            + self::MQTT_LIFECYCLE_OBSERVATION_SECONDS;
        $this->writeMqttLifecycle($lifecycle);
        $this->SetTimerInterval(
            'MqttLifecycle',
            self::MQTT_LIFECYCLE_OBSERVATION_SECONDS * 1000
        );
    }

    private function scheduleMqttLifecycleAttempt(
        string $kind,
        int $delaySeconds,
        string $reason,
        string $connectionTrigger
    ): void {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['scheduledKind'] = $kind;
        $lifecycle['scheduledTrigger'] = $connectionTrigger;
        $lifecycle['nextAttemptAt'] =
            $this->currentTimestamp() + $delaySeconds;
        $this->writeMqttLifecycle($lifecycle);
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_RECONNECT_SCHEDULED,
            $reason
        );
        $this->SetTimerInterval(
            'MqttLifecycle',
            max(1, $delaySeconds) * 1000
        );
    }

    private function scheduleMqttReconnect(string $reason): void
    {
        $lifecycle = $this->mqttLifecycle();
        $attempt = is_int($lifecycle['reconnectAttempt'] ?? null)
            ? $lifecycle['reconnectAttempt']
            : 0;
        if ($attempt >= count(self::MQTT_RECONNECT_DELAYS_SECONDS)) {
            $this->clearMqttLifecycleSchedule();
            $this->recordMqttStatistic('reconnectExhausted');
            $this->appendMqttError('reconnect-exhausted');
            $this->setMqttLifecycleState(
                self::MQTT_LIFECYCLE_DISCONNECTED,
                'reconnect-exhausted'
            );
            return;
        }
        $this->scheduleMqttLifecycleAttempt(
            'reconnect',
            self::MQTT_RECONNECT_DELAYS_SECONDS[$attempt],
            $reason,
            'reconnect'
        );
    }

    private function beginMqttReconnectAttempt(): bool
    {
        $lifecycle = $this->mqttLifecycle();
        $attempt = is_int($lifecycle['reconnectAttempt'] ?? null)
            ? $lifecycle['reconnectAttempt'] + 1
            : 1;
        if ($attempt > count(self::MQTT_RECONNECT_DELAYS_SECONDS)) {
            $this->scheduleMqttReconnect('connection-failed');
            return false;
        }
        $lifecycle['reconnectAttempt'] = $attempt;
        $lifecycle['scheduledKind'] = '';
        $lifecycle['nextAttemptAt'] = 0;
        $this->writeMqttLifecycle($lifecycle);
        $this->recordMqttStatistic('reconnectAttempts');

        return true;
    }

    private function handleMqttConnectionFailure(Throwable $exception): void
    {
        if ($exception instanceof Navimow\ApiException) {
            if ($exception->getKind() === 'authentication') {
                $this->clearMqttLifecycleSchedule();
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_REAUTHENTICATION_REQUIRED
                );
                return;
            }
            if ($exception->getKind() === 'configuration') {
                $this->clearMqttLifecycleSchedule();
                $this->setMqttLifecycleState(
                    self::MQTT_LIFECYCLE_CONFIGURATION_ERROR
                );
                return;
            }
        }
        $this->scheduleMqttReconnect('connection-failed');
    }

    private function recordMqttHealthyObservation(int $timestamp): void
    {
        $lifecycle = $this->mqttLifecycle();
        if (($lifecycle['connectionPending'] ?? false) === true) {
            $this->recordMqttStatistic('connectionSuccesses');
            $lifecycle['connectionPending'] = false;
        }
        $healthySince = is_int($lifecycle['healthySince'] ?? null)
            ? $lifecycle['healthySince']
            : 0;
        if ($healthySince <= 0) {
            $healthySince = $timestamp;
        }
        $lifecycle['healthySince'] = $healthySince;
        $lifecycle['scheduledKind'] = '';
        $lifecycle['nextAttemptAt'] = 0;
        if (
            $timestamp - $healthySince
                >= self::MQTT_LIFECYCLE_HEALTHY_RESET_SECONDS
        ) {
            $lifecycle['reconnectAttempt'] = 0;
        }
        $this->writeMqttLifecycle($lifecycle);
        $this->setMqttLifecycleState(
            self::MQTT_LIFECYCLE_SHADOW_ACTIVE,
            'healthy'
        );
    }

    private function resetMqttReconnectEpisode(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['reconnectAttempt'] = 0;
        $lifecycle['healthySince'] = 0;
        $lifecycle['connectionPending'] = false;
        $this->writeMqttLifecycle($lifecycle);
    }

    private function markMqttConnectionPending(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['connectionPending'] = true;
        $lifecycle['healthySince'] = 0;
        $this->writeMqttLifecycle($lifecycle);
    }

    private function markMqttConnectionNotPending(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['connectionPending'] = false;
        $this->writeMqttLifecycle($lifecycle);
    }

    private function markMqttCredentialRotationPending(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['credentialRotationPending'] = true;
        $this->writeMqttLifecycle($lifecycle);
    }

    private function clearMqttCredentialRotationPending(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['credentialRotationPending'] = false;
        $this->writeMqttLifecycle($lifecycle);
    }

    private function mqttCredentialRotationIsPending(): bool
    {
        return ($this->mqttLifecycle()['credentialRotationPending'] ?? false)
            === true;
    }

    private function clearMqttLifecycleSchedule(): void
    {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['scheduledKind'] = '';
        $lifecycle['scheduledTrigger'] = '';
        $lifecycle['nextAttemptAt'] = 0;
        $lifecycle['connectionPending'] = false;
        $lifecycle['credentialRotationPending'] = false;
        $this->writeMqttLifecycle($lifecycle);
        $this->SetTimerInterval('MqttLifecycle', 0);
    }

    private function mqttTransportIsCredentialFree(array $topology): bool
    {
        $headers = $topology['webSocketConfiguration']['Headers'] ?? '';
        if (is_string($headers)) {
            $headers = json_decode($headers, true, 8);
        }

        return ($topology['webSocketConfiguration']['Active'] ?? null)
                === false
            && $headers === []
            && ($topology['mqttConfiguration']['UserName'] ?? null) === ''
            && ($topology['mqttConfiguration']['Password'] ?? null) === '';
    }

    private function mqttKernelConfigurationFailureClassification(): string
    {
        try {
            $topology = $this->mqttTopology();
        } catch (Throwable) {
            return 'configuration-invalid';
        }
        try {
            $this->assertMqttOwnership($topology);
        } catch (Throwable) {
            return 'ownership-invalid';
        }

        return 'configuration-invalid';
    }

    private function mqttCoreIsHealthy(array $topology): bool
    {
        return $this->mqttCoreStatus($topology['mqttInstanceId']) === 102
            && $this->mqttCoreStatus(
                $topology['webSocketInstanceId']
            ) === 102
            && (
                $topology['webSocketConfiguration']['Active'] ?? null
            ) === true;
    }

    private function mqttCoreStatus(int $instanceId): int
    {
        $instance = IPS_GetInstance($instanceId);

        return is_int($instance['InstanceStatus'] ?? null)
            ? $instance['InstanceStatus']
            : 0;
    }

    private function setMqttLifecycleState(
        string $state,
        ?string $reason = null
    ): void {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $changed = ($lifecycle['state'] ?? null) !== $state;
        $lifecycle['state'] = $state;
        if ($changed || !is_int($lifecycle['stateChangedAt'] ?? null)) {
            $lifecycle['stateChangedAt'] = $this->currentTimestamp();
        }
        if ($reason !== null) {
            $lifecycle['lastTransitionReason'] = $reason;
        }
        $this->writeMqttLifecycle($lifecycle);
    }

    private function recordMqttKernelCoreClassification(
        string $classification,
        int $timestamp
    ): void {
        $lifecycle = $this->mqttLifecycle();
        $lifecycle['formatVersion'] = 1;
        $lifecycle['lastKernelCoreClassification'] = $classification;
        $lifecycle['lastKernelCoreClassificationAt'] = $timestamp;
        $this->writeMqttLifecycle($lifecycle);
    }

    private function writeMqttLifecycle(array $lifecycle): void
    {
        $this->WriteAttributeString(
            'MqttLifecycleRegistry',
            $this->encodeResult($lifecycle)
        );
    }

    private function recordMqttConnectionAttempt(string $trigger): void
    {
        $statistics = $this->mqttStatistics();
        $statistics['connectionAttempts'] = $this->incrementMqttCounter(
            $statistics['connectionAttempts'] ?? null
        );
        $statistics['lastConnectionAttemptAt'] =
            $this->currentTimestamp();
        $statistics['lastConnectionTrigger'] = $trigger;
        $statistics['lastConnectionTriggerAt'] =
            $this->currentTimestamp();
        $this->writeMqttStatistics($statistics);
    }

    private function mqttConnectionTrigger(
        string $scheduledTrigger,
        string $scheduledKind
    ): string {
        $allowed = [
            'initial',
            'kernel-fallback',
            'reconnect',
            'rotation',
        ];
        if (in_array($scheduledTrigger, $allowed, true)) {
            return $scheduledTrigger;
        }

        return match ($scheduledKind) {
            'reconnect' => 'reconnect',
            'rotation' => 'rotation',
            default => 'initial',
        };
    }

    private function recordMqttStatistic(string $counter): void
    {
        $statistics = $this->mqttStatistics();
        $statistics[$counter] = $this->incrementMqttCounter(
            $statistics[$counter] ?? null
        );
        $this->writeMqttStatistics($statistics);
    }

    private function mqttStatistics(): array
    {
        $statistics = $this->decodeMqttAttribute('MqttStatistics', []);
        $statistics['formatVersion'] = 1;

        return $statistics;
    }

    private function writeMqttStatistics(array $statistics): void
    {
        $this->WriteAttributeString(
            'MqttStatistics',
            $this->encodeResult($statistics)
        );
    }

    private function incrementMqttCounter(mixed $counter): int
    {
        $value = is_int($counter) && $counter >= 0 ? $counter : 0;

        return min($value + 1, self::MQTT_MAX_DIAGNOSTIC_COUNTER);
    }

    private function runtimeFunctionName(
        string $prefix,
        string $method
    ): string {
        return $prefix . '_' . $method;
    }

    private function deviceIdFromMqttTopic(string $topic): string
    {
        if (
            preg_match(
                '~^/downlink/vehicle/([^/#+]{1,128})/'
                    . 'realtimeDate/(?:state|event|attributes|location)$~D',
                $topic,
                $matches
            ) !== 1
        ) {
            throw new Navimow\MqttPayloadException(
                'MQTT topic is outside the device allowlist.'
            );
        }

        return $matches[1];
    }

    private function reduceMqttPayload(
        array $payload,
        string $deviceId,
        int $receivedAt
    ): string {
        $deviceKey = hash('sha256', $deviceId);
        $shadow = $this->decodeMqttAttribute(
            'MqttShadowState',
            ['formatVersion' => 1, 'devices' => []]
        );
        $devices = is_array($shadow['devices'] ?? null)
            ? $shadow['devices']
            : [];
        $state = is_array($devices[$deviceKey]['state'] ?? null)
            ? $devices[$deviceKey]['state']
            : Navimow\MqttPartialStateAccumulator::initialState();

        $accepted = false;
        $reconciliation = false;
        foreach ($payload['patches'] as $patch) {
            $reduced = Navimow\MqttPartialStateAccumulator::reduce(
                $state,
                $patch,
                $receivedAt
            );
            $state = $reduced['state'];
            $accepted = $accepted || $reduced['accepted'];
            $reconciliation = $reconciliation
                || $reduced['reconciliationHint'];
        }

        if ($accepted) {
            $devices[$deviceKey] = [
                'state' => $state,
                'updatedAt' => $receivedAt,
            ];
            $devices = $this->limitMqttEntries(
                $devices,
                self::MQTT_MAX_TRACKED_DEVICES,
                'updatedAt'
            );
            $this->WriteAttributeString(
                'MqttShadowState',
                $this->encodeResult([
                    'formatVersion' => 1,
                    'devices' => $devices,
                ])
            );
        }
        if ($reconciliation) {
            $this->queueMqttReconciliation(
                $deviceKey,
                $deviceId,
                $receivedAt
            );
        }

        return $accepted ? 'accepted' : 'reconciliation-queued';
    }

    private function queueMqttReconciliation(
        string $deviceKey,
        string $deviceId,
        int $receivedAt
    ): void {
        $pending = $this->decodeMqttAttribute(
            'MqttPendingReconciliation',
            ['formatVersion' => 1, 'entries' => []]
        );
        $entries = is_array($pending['entries'] ?? null)
            ? $pending['entries']
            : [];
        $existing = is_array($entries[$deviceKey] ?? null)
            ? $entries[$deviceKey]
            : [];
        $lifecycle = $this->decodeMqttAttribute(
            'MqttLifecycleRegistry',
            []
        );
        $lastWakeByDevice = is_array(
            $lifecycle['lastRestWakeByDevice'] ?? null
        )
            ? $lifecycle['lastRestWakeByDevice']
            : [];
        $lastWake = is_int($lastWakeByDevice[$deviceKey] ?? null)
            ? $lastWakeByDevice[$deviceKey]
            : 0;
        $entries[$deviceKey] = [
            'deviceId' => $deviceId,
            'firstQueuedAt' => is_int(
                $existing['firstQueuedAt'] ?? null
            )
                ? $existing['firstQueuedAt']
                : $receivedAt,
            'lastHintAt' => $receivedAt,
            'notBefore' => is_int($existing['notBefore'] ?? null)
                ? $existing['notBefore']
                : max(
                    $receivedAt
                        + self::MQTT_RECONCILIATION_MINIMUM_SECONDS,
                    $lastWake
                        + self::MQTT_RECONCILIATION_MINIMUM_SECONDS
                ),
            'reasonCode' => 'mqtt-semantic-hint',
        ];
        $entries = $this->limitMqttEntries(
            $entries,
            self::MQTT_MAX_TRACKED_DEVICES,
            'firstQueuedAt'
        );
        $this->WriteAttributeString(
            'MqttPendingReconciliation',
            $this->encodeResult([
                'formatVersion' => 1,
                'entries' => $entries,
            ])
        );
        $this->scheduleMqttReconciliation();
    }

    private function scheduleMqttReconciliation(): void
    {
        if (
            !$this->ReadPropertyBoolean('EnableMqttShadow')
            || !$this->hasUsableAccessToken()
        ) {
            $this->SetTimerInterval('MqttReconcile', 0);
            return;
        }

        $pending = $this->decodeMqttAttribute(
            'MqttPendingReconciliation',
            ['formatVersion' => 1, 'entries' => []]
        );
        $entries = is_array($pending['entries'] ?? null)
            ? $pending['entries']
            : [];
        if ($entries === []) {
            $this->SetTimerInterval('MqttReconcile', 0);
            return;
        }

        $notBefore = [];
        foreach ($entries as $entry) {
            if (is_int($entry['notBefore'] ?? null)) {
                $notBefore[] = $entry['notBefore'];
            }
        }
        if ($notBefore === []) {
            $this->SetTimerInterval('MqttReconcile', 0);
            return;
        }

        $delay = max(
            1,
            min($notBefore) - $this->currentTimestamp()
        );
        $this->SetTimerInterval(
            'MqttReconcile',
            $delay * 1000
        );
    }

    private function dueMqttReconciliationKeys(
        array $entries,
        int $now
    ): array {
        $due = [];
        foreach ($entries as $deviceKey => $entry) {
            if (
                is_string($deviceKey)
                && is_array($entry)
                && is_int($entry['notBefore'] ?? null)
                && $entry['notBefore'] <= $now
            ) {
                $due[$deviceKey] = is_int(
                    $entry['firstQueuedAt'] ?? null
                )
                    ? $entry['firstQueuedAt']
                    : 0;
            }
        }
        uksort(
            $due,
            static function (
                string $left,
                string $right
            ) use ($due): int {
                $comparison = $due[$left] <=> $due[$right];
                return $comparison !== 0
                    ? $comparison
                    : strcmp($left, $right);
            }
        );

        return array_keys($due);
    }

    private function isDiscoveredDevice(string $deviceId): bool
    {
        $devices = $this->decodeMqttAttribute(
            'DiscoveryCache',
            []
        );
        foreach ($devices as $device) {
            if (
                is_array($device)
                && is_string($device['id'] ?? null)
                && hash_equals($device['id'], $deviceId)
            ) {
                return true;
            }
        }

        return false;
    }

    private function recordMqttRestWake(
        string $deviceKey,
        int $timestamp
    ): void {
        $lifecycle = $this->decodeMqttAttribute(
            'MqttLifecycleRegistry',
            []
        );
        $lastWakeByDevice = is_array(
            $lifecycle['lastRestWakeByDevice'] ?? null
        )
            ? $lifecycle['lastRestWakeByDevice']
            : [];
        $lastWakeByDevice[$deviceKey] = $timestamp;
        $lastWakeByDevice = $this->limitMqttTimestampMap(
            $lastWakeByDevice,
            self::MQTT_MAX_TRACKED_DEVICES
        );
        $lifecycle['formatVersion'] = 1;
        $lifecycle['lastRestWakeByDevice'] = $lastWakeByDevice;
        $this->WriteAttributeString(
            'MqttLifecycleRegistry',
            $this->encodeResult($lifecycle)
        );
    }

    private function limitMqttTimestampMap(
        array $entries,
        int $limit
    ): array {
        asort($entries);
        while (count($entries) > $limit) {
            array_shift($entries);
        }
        ksort($entries);

        return $entries;
    }

    private function recordMqttReconciliationResult(
        string $result
    ): void {
        $statistics = $this->decodeMqttAttribute(
            'MqttStatistics',
            []
        );
        $statistics['formatVersion'] = 1;
        $statistics['reconciliationAttempts'] =
            (int) ($statistics['reconciliationAttempts'] ?? 0) + 1;
        $statistics['lastReconciliationResult'] = $result;
        $statistics['lastReconciliationAt'] =
            $this->currentTimestamp();
        $this->WriteAttributeString(
            'MqttStatistics',
            $this->encodeResult($statistics)
        );
    }

    private function compareMqttShadowWithRest(
        string $deviceId,
        array $restStatus,
        int $receivedAt
    ): void {
        $shadow = $this->decodeMqttAttribute(
            'MqttShadowState',
            ['formatVersion' => 1, 'devices' => []]
        );
        $deviceKey = hash('sha256', $deviceId);
        $candidate = $shadow['devices'][$deviceKey]['state'] ?? null;
        if (!is_array($candidate)) {
            return;
        }
        $mqttReceivedAt = $candidate['lastReceivedAt'] ?? null;
        if (
            !is_int($mqttReceivedAt)
            || $mqttReceivedAt <= 0
            || ($receivedAt - $mqttReceivedAt)
                > self::MQTT_COMPARISON_MAX_AGE_SECONDS
        ) {
            $this->recordMqttComparison('stale');
            return;
        }

        $fields = $candidate['fields'] ?? null;
        if (!is_array($fields)) {
            return;
        }
        $comparisons = [];
        if (
            is_int($fields['vehicleState'] ?? null)
            && is_int($restStatus['vehicleState'] ?? null)
        ) {
            $comparisons[] = $fields['vehicleState']
                === $restStatus['vehicleState'];
        }
        if (
            is_int($fields['batteryLevel'] ?? null)
            && is_int($restStatus['batteryLevel'] ?? null)
        ) {
            $comparisons[] = abs(
                $fields['batteryLevel']
                    - $restStatus['batteryLevel']
            ) <= 1;
        }
        if ($comparisons === []) {
            return;
        }

        $this->recordMqttComparison(
            in_array(false, $comparisons, true)
                ? 'mismatch'
                : 'match'
        );
    }

    private function recordMqttComparison(string $result): void
    {
        $statistics = $this->decodeMqttAttribute(
            'MqttStatistics',
            []
        );
        $counter = match ($result) {
            'match' => 'comparisonMatches',
            'mismatch' => 'comparisonMismatches',
            default => 'comparisonStale',
        };
        $statistics['formatVersion'] = 1;
        $statistics[$counter] =
            (int) ($statistics[$counter] ?? 0) + 1;
        $statistics['lastComparisonResult'] = $result;
        $statistics['lastComparisonAt'] = $this->currentTimestamp();
        $this->WriteAttributeString(
            'MqttStatistics',
            $this->encodeResult($statistics)
        );
    }

    private function limitMqttEntries(
        array $entries,
        int $limit,
        string $timestampKey
    ): array {
        if (count($entries) <= $limit) {
            ksort($entries);
            return $entries;
        }

        uksort(
            $entries,
            static function (
                string $left,
                string $right
            ) use (
                $entries,
                $timestampKey
            ): int {
                $comparison = ($entries[$left][$timestampKey] ?? 0)
                    <=> ($entries[$right][$timestampKey] ?? 0);
                return $comparison !== 0
                    ? $comparison
                    : strcmp($left, $right);
            }
        );
        while (count($entries) > $limit) {
            array_shift($entries);
        }
        ksort($entries);

        return $entries;
    }

    private function recordMqttResult(
        string $result,
        bool $rejected
    ): void {
        $statistics = $this->decodeMqttAttribute(
            'MqttStatistics',
            []
        );
        $statistics['formatVersion'] = 1;
        $statistics['received'] =
            (int) ($statistics['received'] ?? 0) + 1;
        $statistics['accepted'] =
            (int) ($statistics['accepted'] ?? 0)
                + ($rejected ? 0 : 1);
        $statistics['rejected'] =
            (int) ($statistics['rejected'] ?? 0)
                + ($rejected ? 1 : 0);
        $statistics['lastResult'] = $result;
        $statistics['lastReceivedAt'] = $this->currentTimestamp();
        $this->WriteAttributeString(
            'MqttStatistics',
            $this->encodeResult($statistics)
        );
        $lifecycle = $this->decodeMqttAttribute(
            'MqttLifecycleRegistry',
            []
        );
        $lifecycle['formatVersion'] = 1;
        $lifecycle['lastResult'] = $result;
        $lifecycle['lastResultAt'] = $this->currentTimestamp();
        $this->WriteAttributeString(
            'MqttLifecycleRegistry',
            $this->encodeResult($lifecycle)
        );
    }

    private function appendMqttError(string $reason): void
    {
        $history = $this->decodeMqttAttribute(
            'MqttErrorHistory',
            []
        );
        if (!array_is_list($history)) {
            $history = [];
        }
        $history[] = [
            'reason' => $reason,
            'at' => $this->currentTimestamp(),
        ];
        $history = array_slice(
            $history,
            -self::MQTT_MAX_ERROR_ENTRIES
        );
        $this->WriteAttributeString(
            'MqttErrorHistory',
            $this->encodeResult($history)
        );
    }

    private function decodeMqttAttribute(
        string $ident,
        array $fallback
    ): array {
        $decoded = json_decode(
            $this->ReadAttributeString($ident),
            true,
            32
        );

        return is_array($decoded) ? $decoded : $fallback;
    }

    private function decodeMqttDiagnosticAttribute(string $ident): array
    {
        $encoded = $this->ReadAttributeString($ident);
        if (
            strlen($encoded) > self::MQTT_DIAGNOSTIC_ATTRIBUTE_MAX_BYTES
        ) {
            return [];
        }
        $decoded = json_decode($encoded, true, 16);

        return is_array($decoded) ? $decoded : [];
    }

    private function mqttDiagnosticInteger(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private function mqttDiagnosticCode(
        mixed $value,
        array $allowed
    ): string {
        if ($value === null || $value === '') {
            return 'none';
        }

        return is_string($value) && in_array($value, $allowed, true)
            ? $value
            : 'unknown';
    }

    private function mqttDiagnosticCount(mixed $value, int $limit): int
    {
        return is_array($value) ? min(count($value), $limit) : 0;
    }

    private function mqttDiagnosticErrors(): array
    {
        $history = $this->decodeMqttDiagnosticAttribute(
            'MqttErrorHistory'
        );
        if (!array_is_list($history)) {
            $history = [];
        }
        $allowed = [
            'adoption-failed',
            'connection-failed',
            'connection-rollback-failed',
            'credential-cleanup-skipped',
            'disconnect-failed',
            'invalid-input',
            'reconnect-exhausted',
            'reconciliation-handoff-failed',
            'unexpected-disconnect',
        ];
        $valid = [];
        foreach (
            array_slice($history, -self::MQTT_MAX_ERROR_ENTRIES) as $entry
        ) {
            if (!is_array($entry)) {
                continue;
            }
            $reason = $this->mqttDiagnosticCode(
                $entry['reason'] ?? null,
                $allowed
            );
            $at = $this->mqttDiagnosticInteger($entry['at'] ?? null);
            if ($reason === 'none' || $reason === 'unknown' || $at === 0) {
                continue;
            }
            $valid[] = [
                'reason' => $reason,
                'at' => $at,
            ];
        }
        $latest = $valid === [] ? null : $valid[array_key_last($valid)];

        return [
            'count' => count($valid),
            'latestReason' => $latest['reason'] ?? 'none',
            'latestAt' => $latest['at'] ?? 0,
        ];
    }

    private function encodeResult(array $result): string
    {
        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function throwForApiAuthError(array $response): void
    {
        $error = Navimow\PayloadMapper::mapApiError($response);
        if (!$error['reauthRequired']) {
            return;
        }

        throw new Navimow\ApiException(
            'authentication',
            'Navimow rejected the OAuth information.',
            200,
            $error['code']
        );
    }

    private function recordAuthenticationFailure(
        Throwable $exception,
        bool $allowTransportRetry
    ): void {
        $this->SetTimerInterval('RefreshToken', 0);
        $this->SetValue(
            'RestErrorCount',
            $this->GetValue('RestErrorCount') + 1
        );

        $state = self::STATE_API_WARNING;
        $reauthRequired = true;

        if ($exception instanceof Navimow\ApiException) {
            if ($exception->getKind() === 'transport') {
                $state = self::STATE_OFFLINE;
                $reauthRequired = false;
                if ($allowTransportRetry) {
                    $retryCount = min(
                        self::TOKEN_REFRESH_RETRY_MAX_ATTEMPTS,
                        $this->ReadAttributeInteger('RefreshRetryCount') + 1
                    );
                    $this->WriteAttributeInteger(
                        'RefreshRetryCount',
                        $retryCount
                    );
                    $this->scheduleTokenRefresh();
                } else {
                    $this->WriteAttributeInteger('RefreshRetryCount', 0);
                }
            } elseif ($exception->getKind() === 'configuration') {
                $state = self::STATE_CONFIGURATION_ERROR;
                $this->WriteAttributeInteger('RefreshRetryCount', 0);
            } elseif ($exception->getKind() === 'authentication') {
                $state = self::STATE_REAUTH_REQUIRED;
                $this->WriteAttributeInteger('RefreshRetryCount', 0);
            } else {
                $this->WriteAttributeInteger('RefreshRetryCount', 0);
            }
        } else {
            $this->WriteAttributeInteger('RefreshRetryCount', 0);
        }

        $this->setAuthenticationState($state, $reauthRequired);
        $this->SendDebug(
            'AuthenticationFailure',
            sprintf(
                '%s: %s',
                get_class($exception),
                $this->sanitizedErrorMessage($exception)
            ),
            0
        );
    }

    private function sanitizedErrorMessage(Throwable $exception): string
    {
        $message = preg_replace(
            '/[[:cntrl:]]/',
            '',
            $exception->getMessage()
        ) ?? 'Authentication failed.';

        return substr($message, 0, 200);
    }

    private function setAuthenticationState(
        int $connectionState,
        bool $reauthRequired
    ): void {
        $this->SetValue('ConnectionState', $connectionState);
        $this->SetValue('ReauthRequired', $reauthRequired);
    }

    private function lockName(): string
    {
        return 'NAVIMOW.ACCOUNT.' . $this->InstanceID;
    }
}
