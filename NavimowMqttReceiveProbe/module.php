<?php

declare(strict_types=1);

require_once __DIR__ . '/MqttReceiveProbeReducer.php';

use Navimow\Spike\MqttReceiveProbeReducer;

class NavimowMqttReceiveProbe extends IPSModule
{
    private const MAX_EVIDENCE_DURATION_MILLISECONDS = 180000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('ExpectedDeviceId', '');
        $this->RegisterAttributeString('EvidenceState', '{}');
        $this->RegisterTimer(
            'CloseEvidence',
            0,
            'NAVMQTTPROBE_CloseEvidence($_IPS["TARGET"]);'
        );
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('CloseEvidence', 0);
    }

    public function ArmEvidence(): string
    {
        $deviceId = $this->ReadPropertyString('ExpectedDeviceId');
        if ($deviceId === '' || strpbrk($deviceId, '/#+') !== false) {
            return 'Expected device ID is invalid.';
        }

        $state = MqttReceiveProbeReducer::initialState(time());
        $this->WriteAttributeString(
            'EvidenceState',
            json_encode($state, JSON_THROW_ON_ERROR)
        );
        $this->SetTimerInterval(
            'CloseEvidence',
            self::MAX_EVIDENCE_DURATION_MILLISECONDS
        );

        return 'Receive-only evidence is armed for 180 seconds.';
    }

    public function CloseEvidence(): string
    {
        $state = $this->readEvidenceState();
        $state = MqttReceiveProbeReducer::close($state, time());
        $this->WriteAttributeString(
            'EvidenceState',
            json_encode($state, JSON_THROW_ON_ERROR)
        );
        $this->SetTimerInterval('CloseEvidence', 0);

        return 'Receive-only evidence is closed.';
    }

    public function GetEvidenceReport(): string
    {
        return json_encode(
            MqttReceiveProbeReducer::report($this->readEvidenceState()),
            JSON_THROW_ON_ERROR
        );
    }

    public function ReceiveData($jsonString)
    {
        $state = $this->readEvidenceState();
        if (($state['accepting'] ?? false) !== true) {
            return '';
        }

        $state = MqttReceiveProbeReducer::consume(
            $state,
            (string) $jsonString,
            $this->ReadPropertyString('ExpectedDeviceId'),
            time()
        );
        $this->WriteAttributeString(
            'EvidenceState',
            json_encode($state, JSON_THROW_ON_ERROR)
        );

        if (($state['accepting'] ?? false) !== true) {
            $this->SetTimerInterval('CloseEvidence', 0);
        }

        return '';
    }

    private function readEvidenceState(): array
    {
        try {
            $state = json_decode(
                $this->ReadAttributeString('EvidenceState'),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return [];
        }

        return is_array($state) ? $state : [];
    }
}
