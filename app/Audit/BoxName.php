<?php

namespace App\Audit;

use App\Enums\AuditBoxKind;

/**
 * The names of the boxes of a cabinet: one grammar (the same for the diagram, the map points and the plan files),
 * and the kind each name tells. The operator suffixes come from the installation's configuration.
 */
class BoxName
{
    public const int MAX_LENGTH = 60;

    /**
     * Name as it is kept: spaces collapsed, trimmed, upper case.
     */
    public static function normalise(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Kind of box a name designates, or null when the name does not follow the grammar. Any name holding « JD » is a
     * straight joint; otherwise « BPE » makes a BPE and the rest a PBO.
     */
    public static function classify(string $name): ?AuditBoxKind
    {
        if (preg_match(self::pattern(), $name) !== 1) {
            return null;
        }

        if (stripos($name, 'JD') !== false) {
            return AuditBoxKind::Joint;
        }

        return stripos($name, 'BPE') !== false ? AuditBoxKind::Bpe : AuditBoxKind::Pbo;
    }

    /**
     * Key of a cabinet: capitals, digits, « _ » and « - », at most 30 characters.
     */
    public static function cabinetKey(string $reference): string
    {
        return mb_substr((string) preg_replace('/[^A-Z0-9_-]/', '', mb_strtoupper($reference)), 0, 30);
    }

    /**
     * Key that identifies the same box under the slightly different names the documents give it (leading zeros,
     * commune codes, file extension): « PBO-SRO-BPI-81201-0073 », « 900-PB0073 » and « PB73 » are one box.
     * Empty when the name is not a box name.
     */
    public static function canonicalKey(string $raw): string
    {
        $name = mb_strtoupper(trim((string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $raw)));
        $suffixes = implode('|', array_unique(['JD', ...array_map(fn (string $suffix) => preg_quote(mb_strtoupper($suffix), '/'), (array) config('audit.box_suffixes'))]));

        if (preg_match('/^(JD\d*-)?(?:\d{2,3}-)?PB0*(\d+)(JD)?$/', $name, $match) === 1 || preg_match('/^(JD\d*-)?PBO-SRO-BPI-\d+-0*(\d+)(JD)?$/', $name, $match) === 1) {
            return 'PB|'.$match[1].'|'.$match[2].'|'.($match[3] ?? '');
        }

        if (preg_match('/^JD-PBO-0*(\d+)$/', $name, $match) === 1) {
            return 'JDPBO|'.$match[1];
        }

        if (preg_match('/^(JD\d*-)?BPE-0*(\d+)(?:-\d{5})?(-[A-Z0-9])?((?:-(?:'.$suffixes.'))*)$/', $name, $match) === 1) {
            return 'BPE|'.($match[1] ?? '').'|'.$match[2].'|'.($match[3] ?? '').'|'.($match[4] ?? '');
        }

        return '';
    }

    /**
     * Name of the box a plan file is about, from the file name: « PBO-SRO-BPI-<sro>-0073 » becomes « <cabinet>-PB0073 »,
     * « BPE-0001-81201-FT » becomes « BPE-0001-FT ». Other names are kept as they are.
     */
    public static function fromPlanFile(string $file, string $cabinetKey): string
    {
        $name = mb_strtoupper(trim((string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $file)));
        $number = str_pad((string) (preg_match('/\d+/', $cabinetKey, $digits) === 1 ? $digits[0] : ''), 3, '0', STR_PAD_LEFT);

        if (preg_match('/^(JD\d*-)?PBO-SRO-BPI-\d+-0*(\d+)(JD)?$/', $name, $match) === 1) {
            if ($match[1] !== '') {
                return $match[1].'PB'.str_pad($match[2], 4, '0', STR_PAD_LEFT);
            }

            return $number.'-PB'.(($match[3] ?? '') !== '' ? $match[2].$match[3] : str_pad($match[2], 4, '0', STR_PAD_LEFT));
        }

        if (preg_match('/^(.*BPE-\d+)-\d{5}((?:-[A-Z0-9]+)*)$/', $name, $match) === 1) {
            return $match[1].$match[2];
        }

        return $name;
    }

    private static function pattern(): string
    {
        $suffixes = array_map(fn (string $suffix) => preg_quote($suffix, '/'), ['JD', ...array_map('strtoupper', (array) config('audit.box_suffixes'))]);
        $alternatives = implode('|', array_unique($suffixes));

        return '/^(?:(?:JD\d*-)?BPE-\d+(?:-[A-Z0-9])?(?:-(?:'.$alternatives.'))*|JD\d*-PBO-\d+|(?:JD\d*-)?(?:\d{2,3}-)?PB\d+(?:JD)?)$/i';
    }
}
