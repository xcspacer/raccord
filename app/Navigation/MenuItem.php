<?php

namespace App\Navigation;

/**
 * One entry of the "Fonctions" menu.
 */
final readonly class MenuItem
{
    public function __construct(
        public string $key,
        public string $route,
        public string $icon,
        public string $label,
        public string $description,
    ) {}
}
