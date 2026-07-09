<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/ApiClient.php';
require_once __DIR__ . '/../libs/Navimow/OAuthHelper.php';
require_once __DIR__ . '/../libs/Navimow/PayloadMapper.php';
require_once __DIR__ . '/../libs/Navimow/Profiles.php';

class NavimowAccount extends IPSModule
{
    private const LOGIN_URL = 'https://navimow-h5-fra.willand.com/smartHome/login';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 300;
    private const MINIMUM_REFRESH_DELAY_SECONDS = 60;
    private const SEMAPHORE_TIMEOUT_MILLISECONDS = 5000;

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
        $this->RegisterPropertyBoolean('DebugPayloads', false);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiresAtInternal', 0);
        $this->RegisterAttributeString('OAuthState', '');
        $this->RegisterAttributeString('DiscoveryCache', '[]');

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

        $this->SetTimerInterval('PollStatus', 0);

        if (!$this->hasValidConfiguration()) {
            $this->SetTimerInterval('RefreshToken', 0);
            $this->setAuthenticationState(self::STATE_CONFIGURATION_ERROR, true);
            return;
        }

        if ($this->ReadAttributeString('AccessToken') === '') {
            $this->SetTimerInterval('RefreshToken', 0);
            $this->setAuthenticationState(self::STATE_AUTHORIZATION_PENDING, true);
            return;
        }

        $this->scheduleTokenRefresh();
        $this->setAuthenticationState(self::STATE_CONNECTED, false);
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
            $this->SetValue('LastRestSuccess', time());
            $this->setAuthenticationState(self::STATE_CONNECTED, false);

            return 'Authentication succeeded.';
        } catch (Throwable $exception) {
            $this->recordAuthenticationFailure($exception);
            return $this->sanitizedErrorMessage($exception);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function RefreshAuthentication(): string
    {
        if (!$this->hasValidConfiguration()) {
            $this->setAuthenticationState(self::STATE_CONFIGURATION_ERROR, true);
            return 'Authentication configuration is incomplete.';
        }

        $refreshToken = $this->ReadAttributeString('RefreshToken');
        if ($refreshToken === '') {
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
            $this->SetValue('LastRestSuccess', time());
            $this->setAuthenticationState(self::STATE_CONNECTED, false);

            return 'Token refresh succeeded.';
        } catch (Throwable $exception) {
            $this->recordAuthenticationFailure($exception);
            return $this->sanitizedErrorMessage($exception);
        } finally {
            IPS_SemaphoreLeave($lockName);
        }
    }

    public function ResetAuthentication(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenExpiresAtInternal', 0);
        $this->WriteAttributeString('OAuthState', '');
        $this->SetTimerInterval('RefreshToken', 0);
        $this->SetTimerInterval('PollStatus', 0);
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
        $this->SetTimerInterval('PollStatus', 0);
        $this->setAuthenticationState(self::STATE_API_WARNING, false);
        $this->SendDebug(
            'PollReadOnlyStatus',
            'Read-only discovery and status polling are gated for the next implementation step.',
            0
        );
    }

    public function ForwardData($JSONString)
    {
        return json_encode([
            'status' => 'not_implemented',
            'message' => 'Discovery and read-only status are implemented after the authentication gate.',
        ], JSON_THROW_ON_ERROR);
    }

    private function createApiClient(): Navimow\ApiClient
    {
        return new Navimow\ApiClient($this->ReadPropertyString('BaseUrl'));
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

        $expiresAt = time() + $tokens['expiresIn'];

        $this->WriteAttributeString('AccessToken', $tokens['accessToken']);
        $this->WriteAttributeString('RefreshToken', $refreshToken);
        $this->WriteAttributeInteger('TokenExpiresAtInternal', $expiresAt);
        $this->SetValue('TokenExpiresAt', $expiresAt);
        $this->scheduleTokenRefresh();
    }

    private function scheduleTokenRefresh(): void
    {
        $expiresAt = $this->ReadAttributeInteger('TokenExpiresAtInternal');
        if ($expiresAt <= 0 || $this->ReadAttributeString('RefreshToken') === '') {
            $this->SetTimerInterval('RefreshToken', 0);
            return;
        }

        $remaining = $expiresAt - time();
        $delay = $remaining > (self::TOKEN_REFRESH_MARGIN_SECONDS * 2)
            ? $remaining - self::TOKEN_REFRESH_MARGIN_SECONDS
            : (int) floor($remaining / 2);

        $delay = max(self::MINIMUM_REFRESH_DELAY_SECONDS, $delay);
        $this->SetTimerInterval('RefreshToken', $delay * 1000);
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

    private function recordAuthenticationFailure(Throwable $exception): void
    {
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
            } elseif ($exception->getKind() === 'configuration') {
                $state = self::STATE_CONFIGURATION_ERROR;
            } elseif ($exception->getKind() === 'authentication') {
                $state = self::STATE_REAUTH_REQUIRED;
            }
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
