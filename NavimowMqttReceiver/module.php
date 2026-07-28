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

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('AccountInstanceId', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function ReceiveData($jsonString)
    {
        $envelopeJson = (string) $jsonString;
        if (strlen($envelopeJson) > self::MAX_ENVELOPE_BYTES) {
            $this->sendReceiveDebug('oversized-envelope', strlen($envelopeJson));
            return '';
        }

        try {
            $envelope = MqttEnvelopeParser::parse($envelopeJson);
        } catch (MqttEnvelopeException) {
            $this->sendReceiveDebug('invalid-envelope', strlen($envelopeJson));
            return '';
        }

        if ($envelope['retained']) {
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
            $this->sendReceiveDebug('unpaired', strlen($envelopeJson));
            return '';
        }
        if (!$this->isExpectedAccount($accountInstanceId)) {
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
        }
        if (
            !is_string($result)
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $result) !== 1
        ) {
            $result = 'account-result-invalid';
        }
        $this->sendReceiveDebug($result, strlen($envelopeJson));

        return '';
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
