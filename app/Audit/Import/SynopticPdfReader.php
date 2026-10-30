<?php

namespace App\Audit\Import;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Takes the text out of the synoptic PDF, line by line, for the list parser. A scanned PDF has no text: it gives
 * nothing and the list is pasted by hand.
 */
class SynopticPdfReader
{
    private const int MAX_BYTES = 15 * 1024 * 1024;

    public function text(string $contents): string
    {
        if (! str_starts_with($contents, '%PDF') || strlen($contents) > self::MAX_BYTES) {
            return '';
        }

        try {
            $document = (new Parser)->parseContent($contents);
            $pages = array_map(fn ($page) => (string) $page->getText(), $document->getPages());
        } catch (Throwable) {
            return '';
        }

        return implode("\n", $pages);
    }
}
