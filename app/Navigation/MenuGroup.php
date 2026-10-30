<?php

namespace App\Navigation;

/**
 * A coloured group of the "Fonctions" menu (the colour follows the fibre tube colour code).
 */
final readonly class MenuGroup
{
    /**
     * @param  list<MenuItem>  $items
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $colour,
        public array $items,
    ) {}
}
