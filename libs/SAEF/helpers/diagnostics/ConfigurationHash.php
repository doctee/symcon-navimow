<?php
declare(strict_types=1);

/**
 * SAEF Helper: ConfigurationHash
 *
 * Creates stable hashes for configuration arrays.
 *
 * Related SAEF artifacts:
 * - RS-001 Symcon Engineering Standards
 * - EK-004 Internal State Management
 * - EK-005 Idempotent Configuration
 */

if (!defined('SAEF_HELPER_CONFIGURATION_HASH')) {
    define('SAEF_HELPER_CONFIGURATION_HASH', true);

    /**
     * Normalizes a configuration array for stable hash creation.
     *
     * The function removes ignored keys recursively and sorts all arrays by key.
     * This keeps the resulting structure stable for identical configuration data,
     * even when associative keys were inserted in a different order.
     *
     * @param array $configuration Configuration data to normalize.
     * @param array $ignoreKeys    Key names to remove recursively, for example timestamp, lastRun or runtime.
     *
     * @return array Normalized configuration data.
     */
    function SAEF_NormalizeConfigurationForHash(array $configuration, array $ignoreKeys = []): array
    {
        $ignoredKeyMap = SAEF_CreateIgnoredConfigurationKeyMap($ignoreKeys);

        return SAEF_NormalizeConfigurationHashValue($configuration, $ignoredKeyMap);
    }

    /**
     * Creates a stable SHA-256 hash for a configuration array.
     *
     * The configuration is normalized before hashing. Ignored keys are removed
     * recursively, and arrays are sorted recursively by key.
     *
     * @param array $configuration Configuration data to hash.
     * @param array $ignoreKeys    Key names to remove recursively, for example timestamp, lastRun or runtime.
     *
     * @return string Stable SHA-256 hash.
     *
     * @throws JsonException If the normalized configuration cannot be encoded as JSON.
     */
    function SAEF_CreateConfigurationHash(array $configuration, array $ignoreKeys = []): string
    {
        $normalizedConfiguration = SAEF_NormalizeConfigurationForHash($configuration, $ignoreKeys);
        $encodedConfiguration = json_encode(
            $normalizedConfiguration,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash('sha256', $encodedConfiguration);
    }

    /**
     * Builds a lookup map for ignored configuration keys.
     *
     * @internal Compatibility implementation detail; use the public hash APIs.
     *
     * @param array $ignoreKeys Key names to ignore.
     *
     * @return array<string, bool>
     */
    function SAEF_CreateIgnoredConfigurationKeyMap(array $ignoreKeys): array
    {
        $ignoredKeyMap = [];

        foreach ($ignoreKeys as $key) {
            if (!is_string($key) && !is_int($key)) {
                throw new InvalidArgumentException('ignoreKeys must contain only string or integer keys.');
            }

            $ignoredKeyMap[(string)$key] = true;
        }

        return $ignoredKeyMap;
    }

    /**
     * Normalizes a configuration value recursively.
     *
     * @internal Compatibility implementation detail; use the public hash APIs.
     *
     * @param mixed               $value         Configuration value.
     * @param array<string, bool> $ignoredKeyMap Ignored key lookup map.
     *
     * @return mixed Normalized value.
     */
    function SAEF_NormalizeConfigurationHashValue(mixed $value, array $ignoredKeyMap): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $childValue) {
            if (isset($ignoredKeyMap[(string)$key])) {
                continue;
            }

            $normalized[$key] = SAEF_NormalizeConfigurationHashValue($childValue, $ignoredKeyMap);
        }

        ksort($normalized);

        return $normalized;
    }
}
