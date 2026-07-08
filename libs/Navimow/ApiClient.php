<?php

declare(strict_types=1);

namespace Navimow;

final class ApiClient
{
    public function __construct(
        private readonly string $baseUrl
    ) {
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function request(string $path, array $payload): array
    {
        throw new \LogicException('Navimow REST client is scaffolded only; live requests are not implemented yet.');
    }
}
