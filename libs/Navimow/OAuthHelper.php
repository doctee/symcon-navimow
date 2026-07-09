<?php

declare(strict_types=1);

namespace Navimow;

use InvalidArgumentException;

final class OAuthHelper
{
    public static function createState(): string
    {
        return bin2hex(random_bytes(24));
    }

    public static function buildAuthorizationUrl(
        string $loginUrl,
        string $clientId,
        string $redirectUri,
        string $state
    ): string {
        if ($clientId === '' || $redirectUri === '' || $state === '') {
            throw new InvalidArgumentException(
                'Client ID, redirect URI and OAuth state are required.'
            );
        }

        return $loginUrl . '?' . http_build_query([
            'channel' => $clientId,
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function parseAuthorizationInput(
        string $input,
        ?string $expectedState = null
    ): string {
        $input = trim($input);
        if ($input === '') {
            throw new InvalidArgumentException(
                'Authorization code or redirect URL is required.'
            );
        }

        if (filter_var($input, FILTER_VALIDATE_URL) === false) {
            return $input;
        }

        $query = parse_url($input, PHP_URL_QUERY);
        if (!is_string($query)) {
            throw new InvalidArgumentException(
                'Redirect URL does not contain an authorization code.'
            );
        }

        parse_str($query, $parameters);
        $code = $parameters['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new InvalidArgumentException(
                'Redirect URL does not contain an authorization code.'
            );
        }

        if ($expectedState !== null && $expectedState !== '') {
            $state = $parameters['state'] ?? null;
            if (!is_string($state) || !hash_equals($expectedState, $state)) {
                throw new InvalidArgumentException(
                    'OAuth callback state does not match the login request.'
                );
            }
        }

        return $code;
    }
}
