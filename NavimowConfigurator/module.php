<?php

declare(strict_types=1);

class NavimowConfigurator extends IPSModule
{
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
        return json_encode([
            'elements' => [],
            'actions' => [
                [
                    'type' => 'Label',
                    'caption' => 'Discovery is not implemented in this scaffold step.',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
