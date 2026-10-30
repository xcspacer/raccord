<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Enums\ExpenseLineStatus;
use App\Enums\ExpenseNature;
use App\Models\ExpenseLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads a ticket photo and fills the expense line: date, amount, nature and merchant. When the AI cannot be
 * used the line is left for the person to fill in, with the reason.
 */
class ReceiptReader
{
    public function __construct(private AnthropicClient $client, private AiQuota $quota) {}

    public function read(User $user, ExpenseLine $line): void
    {
        try {
            $file = $line->file;

            if ($file === null) {
                throw new AiException('invalid_image');
            }

            if (! $this->client->isConfigured()) {
                throw new AiException('not_configured');
            }

            $this->quota->consume($user, AiFeature::ReadReceipt);

            $contents = Storage::disk($file->disk)->get($file->path);

            if ($contents === null || strlen($contents) > 6 * 1024 * 1024) {
                throw new AiException('invalid_image');
            }

            $data = $this->client->askForJson($this->prompt(), [['data' => base64_encode($contents), 'media_type' => 'image/jpeg']], 400);
        } catch (AiException $exception) {
            $line->update(['status' => ExpenseLineStatus::Manual, 'message' => $exception->reason]);

            return;
        }

        $date = $this->date($data['date'] ?? null);
        $amount = isset($data['amount_incl_tax']) && is_numeric($data['amount_incl_tax']) && $data['amount_incl_tax'] >= 0 && $data['amount_incl_tax'] < 1000000
            ? (int) round(((float) $data['amount_incl_tax']) * 100)
            : null;
        $doubtful = ($data['confidence'] ?? 'medium') === 'low' || $date === null || $amount === null;

        $line->update([
            'spent_on' => $date,
            'amount_cents' => $amount ?? 0,
            'nature' => ExpenseNature::tryFrom((string) ($data['nature'] ?? '')) ?? ExpenseNature::Other,
            'merchant' => mb_substr(trim(is_scalar($data['merchant'] ?? null) ? (string) $data['merchant'] : ''), 0, 80) ?: null,
            'status' => $doubtful ? ExpenseLineStatus::Check : ExpenseLineStatus::Ok,
            'message' => null,
        ]);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString() === $value ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function prompt(): string
    {
        $natures = implode(' | ', array_map(fn (ExpenseNature $nature) => $nature->value, ExpenseNature::cases()));

        return <<<PROMPT
You are reading the photo of a receipt (fuel, motorway toll, restaurant, parking, etc.) for an expense report. The receipt may be in any language, most often French.
Answer ONLY with a JSON object, no text around it, of this shape:
{"date":"YYYY-MM-DD","amount_incl_tax":12.34,"nature":"...","merchant":"...","confidence":"high|medium|low"}
Rules:
- date: date of the transaction or payment (receipts write DD/MM/YY or DD/MM/YYYY). For a toll with entry and exit, use the exit or payment date. null if unreadable.
- amount_incl_tax: total amount actually paid, tax included, as a number with a decimal point (e.g. 80.79). Fuel: the total paid by card. Toll: the price paid. null if unreadable.
- nature: exactly one value among: {$natures}. A petrol station receipt is fuel; a motorway or tunnel is toll.
- merchant: shop or company that issued the receipt (e.g. TotalEnergies, VINCI Autoroutes).
- confidence: low if any field is uncertain.
Never invent a value: if you cannot read it clearly, use null.
PROMPT;
    }
}
