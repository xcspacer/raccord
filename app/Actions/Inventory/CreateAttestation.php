<?php

namespace App\Actions\Inventory;

use App\Actions\Files\StoreUploadedFile;
use App\Enums\InventoryAttestationStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryStatus;
use App\Inventory\AttestationPdf;
use App\Inventory\InventoryException;
use App\Inventory\MovementRecorder;
use App\Models\InventoryAttestation;
use App\Models\InventoryItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Archives a certificate of handover for the equipment a person holds. Only lines they really hold are kept;
 * the certificate counts as signed only when both signature images were provided (checked here, not declared by
 * the browser), and its PDF is made and stored by the server.
 */
class CreateAttestation
{
    public function __construct(private AttestationPdf $pdf, private StoreUploadedFile $files, private MovementRecorder $movements) {}

    /**
     * @param  list<int>  $itemIds
     * @param  array{date?: string|null, observations?: string|null, representative?: string|null, technician_signature?: string|null, representative_signature?: string|null}  $details  Signatures are PNG data URLs.
     */
    public function handle(User $actor, User $holder, array $itemIds, array $details): InventoryAttestation
    {
        $items = InventoryItem::query()->whereIn('id', $itemIds)->where('holder_id', $holder->id)
            ->whereIn('status', [InventoryStatus::Assigned->value, InventoryStatus::ToConfirm->value])->orderBy('label')->get();

        if ($items->isEmpty()) {
            throw new InventoryException('no_items');
        }

        $date = isset($details['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $details['date']) === 1 ? (string) $details['date'] : CarbonImmutable::now(config('platform.timezone'))->toDateString();
        $representative = mb_substr(trim((string) ($details['representative'] ?? '')), 0, 80) ?: $actor->name;
        $observations = mb_substr(trim((string) ($details['observations'] ?? '')), 0, 800) ?: null;
        $technicianSignature = $this->signature($details['technician_signature'] ?? null);
        $representativeSignature = $this->signature($details['representative_signature'] ?? null);
        $reference = 'AT-'.strtoupper(base_convert((string) time(), 10, 36)).strtoupper(bin2hex(random_bytes(2)));

        $contents = $this->pdf->render([
            'reference' => $reference, 'holder' => $holder, 'items' => $items, 'date' => CarbonImmutable::createFromFormat('!Y-m-d', $date),
            'observations' => $observations, 'representative' => $representative,
            'technicianSignature' => $technicianSignature, 'representativeSignature' => $representativeSignature,
        ]);

        $stored = $this->files->handleContents($contents, 'application/pdf', 'pdf');

        return DB::transaction(function () use ($actor, $holder, $items, $date, $observations, $representative, $technicianSignature, $representativeSignature, $reference, $stored): InventoryAttestation {
            $attestation = InventoryAttestation::query()->create([
                'reference' => $reference, 'user_id' => $holder->id, 'delivered_on' => $date, 'observations' => $observations, 'representative_name' => $representative,
                'technician_signed' => $technicianSignature !== null, 'representative_signed' => $representativeSignature !== null,
                'status' => $technicianSignature !== null && $representativeSignature !== null ? InventoryAttestationStatus::Signed : InventoryAttestationStatus::Unsigned,
                'created_by' => $actor->id, 'created_by_name' => $actor->name, 'stored_file_id' => $stored->id, 'created_at' => now(),
            ]);

            $attestation->items()->attach($items->pluck('id')->all());

            foreach ($items as $item) {
                $this->movements->record($actor, $item, InventoryMovementType::Attest, ['quantity' => $item->quantity, 'holder' => $holder, 'details' => ['attestation' => $reference, 'status' => $attestation->status->value]]);
            }

            return $attestation;
        });
    }

    /**
     * Re-encoded PNG bytes of a signature given as a data URL, or null when there is none (or it is not an image).
     */
    private function signature(?string $dataUrl): ?string
    {
        if ($dataUrl === null || preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match) !== 1 || strlen($match[1]) > 400000) {
            return null;
        }

        $bytes = base64_decode($match[1], true);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);

        if ($image === false || imagesx($image) > 2000 || imagesy($image) > 1000) {
            return null;
        }

        $blank = true;

        for ($x = 0; $x < imagesx($image) && $blank; $x += 3) {
            for ($y = 0; $y < imagesy($image); $y += 3) {
                $pixel = imagecolorat($image, $x, $y);

                if ((($pixel >> 24) & 0x7F) < 100 && ($pixel & 0xFFFFFF) < 0xE0E0E0) {
                    $blank = false;

                    break;
                }
            }
        }

        if ($blank) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
