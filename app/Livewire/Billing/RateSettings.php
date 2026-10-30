<?php

namespace App\Livewire\Billing;

use App\Billing\BillingJournal;
use App\Billing\BillingRates;
use App\Billing\ClientRateLayout;
use App\Models\BillingArticle;
use App\Models\BillingClient;
use App\Models\BillingOperator;
use App\Models\BillingRate;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * « Gérer les tarifs »: every price of every client, laid out by its form, the works price list and the operators.
 * A price is saved as soon as it changes, and kept in the history.
 */
class RateSettings extends Component
{
    /** @var array<string, string> prices by field name (« c<client>_<key> », « : » written « - ») */
    public array $rates = [];

    public string $articleSearch = '';

    public string $operatorName = '';

    public string $operatorError = '';

    public function mount(BillingRates $prices): void
    {
        $this->guard();

        foreach (BillingClient::query()->with('interventionTypes')->get() as $client) {
            foreach ($this->keysOf($client) as $key) {
                $this->rates[self::field($client->id, $key)] = (string) $prices->get($client, $key);
            }
        }

        foreach (BillingArticle::query()->get() as $article) {
            $this->rates[self::field(null, BillingRates::articleKey($article))] = (string) $prices->get(null, BillingRates::articleKey($article));
        }
    }

    /**
     * An invalid or negative price counts as 0, as before.
     *
     * @param  mixed  $value
     */
    public function updatedRates($value, string $field): void
    {
        $this->guard();
        $prices = app(BillingRates::class);

        [$clientId, $key] = self::parse($field);
        $client = $clientId === null ? null : BillingClient::query()->findOrFail($clientId);
        $amount = is_numeric(str_replace(',', '.', (string) $value)) ? max(0.0, (float) str_replace(',', '.', (string) $value)) : 0.0;

        $prices->set($client, $key, $amount, $this->user());
        $this->rates[$field] = (string) $amount;
    }

    public function addOperator(): void
    {
        $this->guard();

        $name = trim($this->operatorName);
        $this->operatorError = '';

        if ($name === '') {
            return;
        }

        if (BillingOperator::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            $this->operatorError = __('billing.settings.operator_exists');

            return;
        }

        $operator = BillingOperator::query()->create(['name' => mb_substr($name, 0, 80)]);
        app(BillingJournal::class)->record(BillingJournal::OPERATOR_ADDED, $operator, $this->user());
        $this->operatorName = '';
    }

    public function removeOperator(int $id): void
    {
        $this->guard();

        $operator = BillingOperator::query()->findOrFail($id);
        app(BillingJournal::class)->record(BillingJournal::OPERATOR_REMOVED, $operator, $this->user());
        $operator->delete();
    }

    public function render(): View
    {
        $search = mb_strtolower(trim($this->articleSearch));

        return view('livewire.billing.rate-settings', [
            'clients' => BillingClient::query()->with('interventionTypes')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'articles' => BillingArticle::query()->orderBy('sort_order')->orderBy('code')->get()
                ->filter(fn (BillingArticle $article) => $search === '' || str_contains(mb_strtolower($article->code.' '.$article->label), $search)),
            'operators' => BillingOperator::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Field name of a price in the form.
     */
    public static function field(?int $clientId, string $key): string
    {
        return 'c'.($clientId ?? 0).'_'.str_replace(':', '-', $key);
    }

    /**
     * @return array{0: int|null, 1: string}
     */
    private static function parse(string $field): array
    {
        abort_unless(preg_match('/^c(\d+)_([a-z0-9_-]+)$/', $field, $match) === 1, 422);

        return [(int) $match[1] === 0 ? null : (int) $match[1], str_replace('-', ':', $match[2])];
    }

    /**
     * Price keys a client's form uses.
     *
     * @return list<string>
     */
    private function keysOf(BillingClient $client): array
    {
        return app(ClientRateLayout::class)->keys($client);
    }

    private function guard(): void
    {
        $this->authorize('manage', BillingRate::class);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
