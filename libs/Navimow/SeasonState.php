<?php

declare(strict_types=1);

namespace Navimow;

final class SeasonState
{
    /** @return array{mode: string, since: string, applied: bool} */
    public static function read(string $reference): array
    {
        if ($reference === '') {
            return ['mode' => 'season', 'since' => '', 'applied' => true];
        }
        try {
            if (!ctype_digit($reference) || (int) $reference <= 0) {
                throw new \RuntimeException('Invalid season state reference.');
            }
            $id = (int) $reference;
            if (!\IPS_VariableExists($id) || \IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_STRING) {
                throw new \RuntimeException('Season state must be a string variable.');
            }
            return self::decode(\GetValueString($id));
        } catch (\Throwable) {
            return ['mode' => 'unknown', 'since' => '', 'applied' => false];
        }
    }

    /** @return array{mode: string, since: string, applied: bool} */
    public static function decode(string $json): array
    {
        if (strlen($json) > 4096) {
            throw new \InvalidArgumentException('Season state exceeds limit.');
        }
        $state = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (
            !is_array($state) || ($state['version'] ?? null) !== 1
            || !in_array($state['mode'] ?? null, ['season', 'winter', 'commissioning'], true)
            || !is_string($state['since'] ?? null) || !is_bool($state['applied'] ?? null)
        ) {
            throw new \InvalidArgumentException('Invalid season state.');
        }
        if ($state['since'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $state['since']);
            if ($date === false || $date->format('Y-m-d') !== $state['since']) {
                throw new \InvalidArgumentException('Invalid storage date.');
            }
        } elseif ($state['mode'] === 'winter') {
            throw new \InvalidArgumentException('Storage date required.');
        }
        return ['mode' => $state['mode'], 'since' => $state['since'], 'applied' => $state['applied']];
    }

    /** @param array{mode: string, since: string, applied: bool} $state */
    public static function allowsCommands(array $state): bool
    {
        return $state['mode'] === 'season' && $state['applied'];
    }

    /** @param array{mode: string, since: string, applied: bool} $state */
    public static function allowsRead(array $state): bool
    {
        return self::allowsCommands($state) || $state['mode'] === 'commissioning';
    }

    /** @param array{mode: string, since: string, applied: bool} $state */
    public static function label(array $state): string
    {
        if ($state['mode'] === 'winter') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $state['since']);
            return 'Winterpause - eingelagert seit ' . ($date === false ? '?' : $date->format('d.m.Y'))
                . ($state['applied'] ? '' : ' - Einrichtung unvollstaendig');
        }
        return $state['mode'] === 'commissioning' ? 'Wiederinbetriebnahme - Steuerung gesperrt'
            : (self::allowsCommands($state) ? '' : 'Betriebszustand unklar - Steuerung gesperrt');
    }
}
