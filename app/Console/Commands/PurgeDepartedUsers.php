<?php

namespace App\Console\Commands;

use App\Actions\Users\AnonymiseUser;
use App\Actions\Users\DeletePersonalDocuments;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retention of the people who left (D4): their personal documents are erased 3 months after the departure, and
 * their account is anonymised 12 months after it (the work history stays). An account deleted without a departure
 * date counts from its deletion.
 */
class PurgeDepartedUsers extends Command
{
    protected $signature = 'users:purge-departed';

    protected $description = 'Erase the personal documents and anonymise the accounts of people who left, after the retention periods';

    public function handle(DeletePersonalDocuments $documents, AnonymiseUser $anonymise): int
    {
        $documentsBefore = CarbonImmutable::now(config('platform.timezone'))->subMonths((int) config('platform.departed_user_documents_months'));
        $anonymiseBefore = CarbonImmutable::now(config('platform.timezone'))->subMonths((int) config('platform.departed_user_anonymisation_months'));

        $erased = 0;

        $this->departedBefore($documentsBefore)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('user_documents')->whereColumn('user_documents.user_id', 'users.id'))
            ->chunkById(100, function ($users) use ($documents, &$erased): void {
                foreach ($users as $user) {
                    $erased += $documents->handle($user);
                }
            });

        $anonymised = 0;

        $this->departedBefore($anonymiseBefore)->whereNull('anonymised_at')->chunkById(100, function ($users) use ($anonymise, &$anonymised): void {
            foreach ($users as $user) {
                $anonymise->handle($user);
                $anonymised++;
            }
        });

        $this->info("{$erased} personal document(s) erased, {$anonymised} account(s) anonymised.");

        return self::SUCCESS;
    }

    /**
     * People who left on or before the date (deleted accounts included).
     *
     * @return Builder<User>
     */
    private function departedBefore(CarbonImmutable $date): Builder
    {
        return User::query()->withTrashed()->where(fn (Builder $query) => $query
            ->whereDate('departure_date', '<=', $date->toDateString())
            ->orWhere(fn (Builder $query) => $query->whereNull('departure_date')->where('deleted_at', '<=', $date->utc())));
    }
}
