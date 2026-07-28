<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Navimow/MqttEnvelopeException.php';
require_once __DIR__ . '/../libs/Navimow/MqttEnvelopeParser.php';

use Navimow\MqttEnvelopeException;
use Navimow\MqttEnvelopeParser;

class NavimowMqttReceiver extends IPSModule
{
    private const ACCOUNT_MODULE_ID =
        '{3C2693FC-1068-4A63-856B-8AC0376556CC}';
    private const MAX_ENVELOPE_BYTES = 65536;
    private const MAX_DIAGNOSTIC_ATTRIBUTE_BYTES = 4096;
    private const MAX_DIAGNOSTIC_COUNTER = 2147483647;
    private const ACCOUNT_RESULTS = [
        'accepted',
        'busy',
        'invalid-input',
        'oversized-envelope',
        'pairing-rejected',
        'reconciliation-queued',
        'retained-rejected',
    ];
    private const DIAGNOSTIC_RESULTS = [
        'none',
        'unknown',
        'oversized-envelope',
        'invalid-envelope',
        'retained-rejected',
        'unpaired',
        'invalid-account',
        'account-handoff-failed',
        'account-result-invalid',
        ...self::ACCOUNT_RESULTS,
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('AccountInstanceId', 0);
        $this->RegisterAttributeString('ReceiveDiagnostics', '{}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function ReceiveData($jsonString)
    {
        $this->beginReceive();

        $envelopeJson = (string) $jsonString;
        if (strlen($envelopeJson) > self::MAX_ENVELOPE_BYTES) {
            $this->completeReceive(
                'oversized-envelope',
                'oversized',
                false
            );
            $this->sendReceiveDebug('oversized-envelope', strlen($envelopeJson));
            return '';
        }

        try {
            $envelope = MqttEnvelopeParser::parse($envelopeJson);
        } catch (MqttEnvelopeException) {
            $this->completeReceive(
                'invalid-envelope',
                'invalidEnvelope',
                false
            );
            $this->sendReceiveDebug('invalid-envelope', strlen($envelopeJson));
            return '';
        }

        if ($envelope['retained']) {
            $this->completeReceive(
                'retained-rejected',
                'retainedRejected',
                false
            );
            $this->sendReceiveDebug(
                'retained-rejected',
                strlen($envelopeJson)
            );
            return '';
        }

        $accountInstanceId = $this->ReadPropertyInteger(
            'AccountInstanceId'
        );
        if ($accountInstanceId <= 0) {
            $this->completeReceive('unpaired', 'unpaired', false);
            $this->sendReceiveDebug('unpaired', strlen($envelopeJson));
            return '';
        }
        if (!$this->isExpectedAccount($accountInstanceId)) {
            $this->completeReceive(
                'invalid-account',
                'invalidAccount',
                false
            );
            $this->sendReceiveDebug(
                'invalid-account',
                strlen($envelopeJson)
            );
            return '';
        }

        try {
            $ingest = $this->runtimeFunctionName(
                'NAVAC',
                'IngestMqttEnvelope'
            );
            if (!is_callable($ingest)) {
                throw new RuntimeException(
                    'Account ingestion wrapper is unavailable.'
                );
            }
            $result = $ingest(
                $accountInstanceId,
                $this->InstanceID,
                $envelopeJson
            );
        } catch (Throwable) {
            $result = 'account-handoff-failed';
            $this->completeReceive(
                $result,
                'handoffFailed',
                false
            );
            $this->sendReceiveDebug($result, strlen($envelopeJson));
            return '';
        }
        if (
            !is_string($result)
            || !in_array($result, self::ACCOUNT_RESULTS, true)
        ) {
            $result = 'account-result-invalid';
            $this->completeReceive(
                $result,
                'accountResultInvalid',
                true
            );
            $this->sendReceiveDebug($result, strlen($envelopeJson));
            return '';
        }
        $this->completeReceive($result, null, true);
        $this->sendReceiveDebug($result, strlen($envelopeJson));

        return '';
    }

    public function GetReceiveDiagnostics(): string
    {
        return $this->encodeDiagnostics($this->readReceiveDiagnostics());
    }

    private function isExpectedAccount(int $instanceId): bool
    {
        if (!IPS_InstanceExists($instanceId)) {
            return false;
        }

        $instance = IPS_GetInstance($instanceId);

        return ($instance['ModuleInfo']['ModuleID'] ?? null)
            === self::ACCOUNT_MODULE_ID;
    }

    private function runtimeFunctionName(
        string $prefix,
        string $method
    ): string {
        return $prefix . '_' . $method;
    }

    private function beginReceive(): void
    {
        $diagnostics = $this->readReceiveDiagnostics();
        $diagnostics['receiveCalls'] = $this->incrementDiagnosticCounter(
            $diagnostics['receiveCalls']
        );
        $diagnostics['lastReceivedAt'] = time();
        $this->writeReceiveDiagnostics($diagnostics);
    }

    private function completeReceive(
        string $result,
        ?string $counter,
        bool $forwarded
    ): void {
        $diagnostics = $this->readReceiveDiagnostics();
        if ($counter !== null && array_key_exists($counter, $diagnostics)) {
            $diagnostics[$counter] = $this->incrementDiagnosticCounter(
                $diagnostics[$counter]
            );
        }
        if ($forwarded) {
            $diagnostics['forwarded'] = $this->incrementDiagnosticCounter(
                $diagnostics['forwarded']
            );
            $diagnostics['lastForwardedAt'] = time();
        }
        $diagnostics['lastResult'] = in_array(
            $result,
            self::DIAGNOSTIC_RESULTS,
            true
        ) ? $result : 'unknown';
        $this->writeReceiveDiagnostics($diagnostics);
    }

    private function readReceiveDiagnostics(): array
    {
        $stored = $this->ReadAttributeString('ReceiveDiagnostics');
        $decoded = [];
        if (strlen($stored) <= self::MAX_DIAGNOSTIC_ATTRIBUTE_BYTES) {
            try {
                $candidate = json_decode(
                    $stored,
                    true,
                    8,
                    JSON_THROW_ON_ERROR
                );
                if (is_array($candidate)) {
                    $decoded = $candidate;
                }
            } catch (Throwable) {
                $decoded = [];
            }
        }

        $diagnostics = $this->emptyReceiveDiagnostics();
        foreach (
            [
                'receiveCalls',
                'forwarded',
                'oversized',
                'invalidEnvelope',
                'retainedRejected',
                'unpaired',
                'invalidAccount',
                'handoffFailed',
                'accountResultInvalid',
                'lastReceivedAt',
                'lastForwardedAt',
            ] as $integerField
        ) {
            $value = $decoded[$integerField] ?? null;
            if (
                is_int($value)
                && $value >= 0
                && $value <= self::MAX_DIAGNOSTIC_COUNTER
            ) {
                $diagnostics[$integerField] = $value;
            }
        }

        $lastResult = $decoded['lastResult'] ?? null;
        if (
            is_string($lastResult)
            && in_array($lastResult, self::DIAGNOSTIC_RESULTS, true)
        ) {
            $diagnostics['lastResult'] = $lastResult;
        } elseif ($lastResult !== null) {
            $diagnostics['lastResult'] = 'unknown';
        }

        return $diagnostics;
    }

    private function emptyReceiveDiagnostics(): array
    {
        return [
            'formatVersion' => 1,
            'receiveCalls' => 0,
            'forwarded' => 0,
            'oversized' => 0,
            'invalidEnvelope' => 0,
            'retainedRejected' => 0,
            'unpaired' => 0,
            'invalidAccount' => 0,
            'handoffFailed' => 0,
            'accountResultInvalid' => 0,
            'lastResult' => 'none',
            'lastReceivedAt' => 0,
            'lastForwardedAt' => 0,
        ];
    }

    private function incrementDiagnosticCounter(int $counter): int
    {
        return min($counter + 1, self::MAX_DIAGNOSTIC_COUNTER);
    }

    private function writeReceiveDiagnostics(array $diagnostics): void
    {
        $this->WriteAttributeString(
            'ReceiveDiagnostics',
            $this->encodeDiagnostics($diagnostics)
        );
    }

    private function encodeDiagnostics(array $diagnostics): string
    {
        return json_encode(
            $diagnostics,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    private function sendReceiveDebug(
        string $result,
        int $envelopeBytes
    ): void {
        $metadata = json_encode(
            [
                'result' => $result,
                'envelopeBytes' => $envelopeBytes,
            ],
            JSON_THROW_ON_ERROR
        );
        $this->SendDebug('MQTT Receive', $metadata, 0);
    }
}
