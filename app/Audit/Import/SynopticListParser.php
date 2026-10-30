<?php

namespace App\Audit\Import;

use App\Audit\BoxName;
use App\Enums\AuditBoxKind;
use App\Enums\AuditSupport;

/**
 * Reads the list of boxes of a cabinet from the text of its synoptic (or pasted by hand): a line starting with the
 * name of a box is a box, its support may follow in brackets, and the lines after it give its type and address.
 *
 * @phpstan-type Box array{name: string, kind: AuditBoxKind, support: AuditSupport|null, support_number: string, type: string, address: string}
 */
class SynopticListParser
{
    /**
     * @return list<Box>
     */
    public function parse(string $text): array
    {
        $lines = array_values(array_filter(array_map(fn (string $line) => trim((string) preg_replace('/\s+/u', ' ', $line)), preg_split('/\R/u', $text) ?: [])));
        $boxes = [];

        foreach ($lines as $index => $line) {
            $head = $this->head($line);

            if ($head === null) {
                continue;
            }

            [$name, $rest] = $head;
            [$support, $number] = $this->support($rest);
            $following = [];

            for ($next = $index + 1; $next < count($lines) && count($following) < 4; $next++) {
                if ($this->head($lines[$next]) !== null || $this->endsDescription($lines[$next])) {
                    break;
                }

                $following[] = $lines[$next];
            }

            $type = $following[0] ?? '';
            $address = preg_match('/^PBO/i', $type) === 1
                ? implode(', ', array_slice(array_values(array_filter(array_slice($following, 1), fn (string $part) => preg_match('/^T\d{2}\s*F\d{2}/i', $part) !== 1)), 0, 2))
                : '';

            $box = ['name' => $name, 'kind' => BoxName::classify($name) ?? AuditBoxKind::Pbo, 'support' => $support, 'support_number' => $number, 'type' => $type, 'address' => $address];

            if (! isset($boxes[$name])) {
                $boxes[$name] = $box;
            } elseif ($boxes[$name]['support'] === null && $support !== null) {
                $boxes[$name] = $box;
            }
        }

        return array_values($boxes);
    }

    /**
     * @return array{string, string}|null The box name at the start of the line, and what follows it.
     */
    private function head(string $line): ?array
    {
        foreach (range(strlen($line), 2) as $length) {
            $candidate = substr($line, 0, $length);

            if (BoxName::classify($candidate) !== null && ($length === strlen($line) || preg_match('/^[^A-Za-z0-9-]/', substr($line, $length)) === 1)) {
                return [mb_strtoupper($candidate), trim(substr($line, $length))];
            }
        }

        return null;
    }

    /**
     * @return array{AuditSupport|null, string}
     */
    private function support(string $rest): array
    {
        $words = [];

        foreach ((array) config('audit.synoptic.support_words') as $support => $list) {
            foreach ($list as $word) {
                $words[mb_strtolower($word)] = $support;
            }
        }

        if ($words === [] || preg_match('/\[\s*('.implode('|', array_map(fn (string $word) => preg_quote($word, '/'), array_keys($words))).')(?:\s+[A-Za-z]{2,8})?\s*:\s*([^\]]*?)\s*\]/iu', $rest, $match) !== 1) {
            return [null, ''];
        }

        $number = preg_match('/^-+$/', $match[2]) === 1 ? '' : trim($match[2]);

        return [AuditSupport::from($words[mb_strtolower($match[1])]), $number];
    }

    private function endsDescription(string $line): bool
    {
        if (preg_match('/^\d+ FO /i', $line) === 1) {
            return true;
        }

        foreach ((array) config('audit.synoptic.stop_prefixes') as $prefix) {
            if (stripos($line, (string) $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
