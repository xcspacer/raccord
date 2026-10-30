<?php

namespace App\Inventory;

use App\Enums\InventoryCategory;
use App\Enums\InventoryCondition;
use Illuminate\Support\Str;

/**
 * Reads a pasted or uploaded equipment list (Markdown table or CSV with « | », « ; » or « , ») into lines to record.
 * The header row is the first row with at least two cells naming a column; without one, the columns are
 * designation, brand, model, serial number, quantity.
 */
class InventoryListParser
{
    private const array COLUMNS = [
        'label' => ['designation', 'equipement', 'materiel', 'article', 'libelle', 'nom', 'designacao', 'equipamento', 'name', 'label'],
        'brand' => ['marque', 'fabricant', 'marca', 'brand'],
        'model' => ['modele', 'reference', 'ref', 'modelo', 'model'],
        'serial' => ['serie', 's/n', 'serial', 'n de serie', 'numero de serie', 'n serie'],
        'quantity' => ['qte', 'quantite', 'nombre', 'nb', 'quantidade', 'qty', 'quantity'],
        'condition' => ['etat', 'condition', 'estado'],
        'note' => ['incertitude', 'remarque', 'commentaire', 'observation', 'observations', 'note', 'notes'],
        'category' => ['categorie', 'type', 'famille', 'categoria', 'category'],
        'vehicle' => ['vehicule', 'viatura', 'vehicle', 'veiculo'],
    ];

    /**
     * @return list<array{label: string, brand: string, model: string, serial: string, quantity: int, condition: string, needs_check: bool, uncertainty: string, category: string, vehicle: string}>
     */
    public function parse(string $text): array
    {
        $rows = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^[\s|:\-;,+=]+$/', $line) === 1) {
                continue;
            }

            $rows[] = array_map('trim', $this->cells($line));
        }

        if ($rows === []) {
            return [];
        }

        $map = null;

        foreach ($rows as $index => $cells) {
            $found = $this->header($cells);

            if ($found !== null) {
                $map = $found;
                $rows = array_slice($rows, $index + 1);

                break;
            }
        }

        $map ??= ['label' => 0, 'brand' => 1, 'model' => 2, 'serial' => 3, 'quantity' => 4];
        $lines = [];

        foreach ($rows as $cells) {
            $value = fn (string $field): string => isset($map[$field]) ? trim($cells[$map[$field]] ?? '') : '';
            $label = $value('label');

            if ($label === '') {
                continue;
            }

            $serial = $value('serial');
            $note = $value('note');
            $quantity = (int) preg_replace('/\D/', '', $value('quantity'));

            $lines[] = [
                'label' => $label,
                'brand' => $value('brand'),
                'model' => $value('model'),
                'serial' => in_array($serial, ['-', '–', '—'], true) ? '' : $serial,
                'quantity' => $quantity > 0 ? min($quantity, 9999) : 1,
                'condition' => $this->condition($value('condition')),
                'needs_check' => preg_match('/incert|verif|\?|probabl|doute|duvid|check/i', Str::ascii($note)) === 1,
                'uncertainty' => $note,
                'category' => $this->category($value('category')),
                'vehicle' => $value('vehicle'),
            ];
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function cells(string $line): array
    {
        $separator = str_contains($line, '|') ? '|' : (str_contains($line, ';') ? ';' : ',');
        $cells = str_getcsv($line, $separator, '"', '');

        if ($separator === '|') {
            if (($cells[0] ?? '') === '') {
                array_shift($cells);
            }

            if (($cells[count($cells) - 1] ?? 'x') === '') {
                array_pop($cells);
            }
        }

        return array_map(fn ($cell) => (string) $cell, $cells);
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>|null
     */
    private function header(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            $name = Str::of(Str::ascii($cell))->lower()->replaceMatches('/[^a-z0-9\/ ]/', ' ')->squish()->toString();

            foreach (self::COLUMNS as $field => $names) {
                if (! isset($map[$field]) && in_array($name, $names, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return count($map) >= 2 && isset($map['label']) ? $map : null;
    }

    private function condition(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        return match (true) {
            $value === '' => InventoryCondition::Unknown->value,
            str_contains($value, 'neuf') || str_contains($value, 'new') || str_contains($value, 'novo') || str_contains($value, 'nuevo') => InventoryCondition::New->value,
            preg_match('/defect|hs|casse|defeit|danif|broken/', $value) === 1 => InventoryCondition::Defective->value,
            preg_match('/\buse\b|usado|worn/', $value) === 1 => InventoryCondition::Worn->value,
            preg_match('/\bbon\b|\bok\b|bom|bueno|good/', $value) === 1 => InventoryCondition::Good->value,
            default => InventoryCondition::Unknown->value,
        };
    }

    private function category(string $value): string
    {
        $value = Str::lower(Str::ascii($value));

        foreach (InventoryCategory::cases() as $category) {
            foreach ([$category->value, Str::lower(Str::ascii($category->label()))] as $name) {
                if ($value !== '' && $value === $name) {
                    return $category->value;
                }
            }
        }

        return '';
    }
}
