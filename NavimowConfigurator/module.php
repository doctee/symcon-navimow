<?php

declare(strict_types=1);

class NavimowConfigurator extends IPSModule
{
    private const DATA_INTERFACE = '{54620029-127D-470D-97C7-44265496FAA0}';
    private const MESSAGE_SCHEMA_VERSION = 1;
    private const DEVICE_MODULE = '{4BD2B356-7890-4667-9B4F-35C619175B43}';

    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $values = [];
        $message = 'Discovery returned no devices.';

        try {
            $result = json_decode(
                $this->SendDataToParent(json_encode([
                    'DataID' => self::DATA_INTERFACE,
                    'SchemaVersion' => self::MESSAGE_SCHEMA_VERSION,
                    'Function' => 'GetDiscovery',
                ], JSON_THROW_ON_ERROR)),
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($result) || ($result['status'] ?? null) !== 'ok') {
                throw new RuntimeException(
                    is_string($result['message'] ?? null)
                        ? $result['message']
                        : 'Discovery failed.'
                );
            }

            $devices = $result['devices'] ?? [];
            if (!is_array($devices)) {
                throw new UnexpectedValueException(
                    'Discovery result does not contain a device list.'
                );
            }

            $instances = $this->deviceInstancesById();
            foreach ($devices as $device) {
                if (!is_array($device)) {
                    continue;
                }

                $deviceId = $device['id'] ?? null;
                if (!is_string($deviceId) || $deviceId === '') {
                    continue;
                }

                $name = is_string($device['name'] ?? null) && $device['name'] !== ''
                    ? $device['name']
                    : 'Navimow Device';

                $values[] = [
                    'instanceID' => $instances[$deviceId] ?? 0,
                    'name' => $name,
                    'deviceName' => $name,
                    'model' => is_string($device['model'] ?? null)
                        ? $device['model']
                        : '',
                    'firmware' => is_string($device['firmware'] ?? null)
                        ? $device['firmware']
                        : '',
                    'create' => [
                        'moduleID' => self::DEVICE_MODULE,
                        'configuration' => [
                            'DeviceId' => $deviceId,
                            'DisplayName' => $name,
                            'DebugPayloads' => false,
                        ],
                        'name' => $name,
                    ],
                ];
            }

            $message = sprintf(
                'Discovery succeeded with %d device(s).',
                count($values)
            );
        } catch (Throwable $exception) {
            $message = $this->limitMessage($exception->getMessage());
        }

        return json_encode([
            'elements' => [],
            'actions' => [
                [
                    'type' => 'Label',
                    'caption' => $message,
                ],
                [
                    'type' => 'Configurator',
                    'name' => 'Devices',
                    'caption' => 'Discovered Navimow Devices',
                    'delete' => true,
                    'discoveryInterval' => 60,
                    'rowCount' => 10,
                    'sort' => [
                        'column' => 'deviceName',
                        'direction' => 'ascending',
                    ],
                    'columns' => [
                        [
                            'name' => 'deviceName',
                            'caption' => 'Name',
                            'width' => 'auto',
                        ],
                        [
                            'name' => 'model',
                            'caption' => 'Model',
                            'width' => '160px',
                        ],
                        [
                            'name' => 'firmware',
                            'caption' => 'Firmware',
                            'width' => '120px',
                        ],
                    ],
                    'values' => $values,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function deviceInstancesById(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE) as $instanceID) {
            $configuration = json_decode(
                IPS_GetConfiguration($instanceID),
                true
            );
            if (!is_array($configuration)) {
                continue;
            }

            $deviceId = $configuration['DeviceId'] ?? null;
            if (is_string($deviceId) && $deviceId !== '') {
                $result[$deviceId] = $instanceID;
            }
        }

        return $result;
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
