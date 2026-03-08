<?php

namespace App;

/**
 * Maps Slovak parliamentary clubs to their marketing colors.
 * Colors are muted for Apple Glass UI – subtle accents, not garish.
 */
class PartyColors
{
    /** @var array<string, array{name: string, color: string, order: int}> */
    private static array $map = [
        'SMER - sociálna demokracia' => ['name' => 'SMER-SD', 'color' => '#b83d4e', 'order' => 1],
        'HLAS - sociálna demokracia' => ['name' => 'HLAS-SD', 'color' => '#d64555', 'order' => 2],
        'Slovenská národná strana' => ['name' => 'SNS', 'color' => '#6b7280', 'order' => 3],
        'Progresívne Slovensko' => ['name' => 'PS', 'color' => '#58B6F2', 'order' => 4],
        'SLOVENSKO - ZA ĽUDÍ' => ['name' => 'Slovensko', 'color' => '#A7D13B', 'order' => 5],
        'KDH' => ['name' => 'KDH', 'color' => '#1565c0', 'order' => 6],
        'Sloboda a Solidarita' => ['name' => 'SaS', 'color' => '#2e7d32', 'order' => 7],
    ];

    public static function getPartyFromClub(?string $club): string
    {
        if (empty($club)) {
            return '';
        }
        $club = trim($club);
        foreach (array_keys(self::$map) as $key) {
            if (stripos($club, $key) === 0) {
                return $key;
            }
        }
        return '';
    }

    public static function getDisplayName(?string $club): string
    {
        $key = self::getPartyFromClub($club);
        if ($key !== '') {
            return self::$map[$key]['name'] ?? $key;
        }
        if (empty($club)) {
            return '-';
        }
        $short = preg_replace('/\s*\([^)]*\).*$/u', '', trim($club));
        return mb_strlen($short) > 28 ? mb_substr($short, 0, 25) . '…' : $short;
    }

    public static function getColor(?string $club): string
    {
        $key = self::getPartyFromClub($club);
        return $key !== '' ? (self::$map[$key]['color'] ?? '#6e6e73') : '#6e6e73';
    }

    public static function getOrder(?string $club): int
    {
        $key = self::getPartyFromClub($club);
        return $key !== '' ? (self::$map[$key]['order'] ?? 99) : 99;
    }

    /** @return array<string, array{name: string, color: string}> */
    public static function getAll(): array
    {
        $out = [];
        foreach (self::$map as $key => $v) {
            $out[$key] = ['name' => $v['name'], 'color' => $v['color']];
        }
        return $out;
    }
}
