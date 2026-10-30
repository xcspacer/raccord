<?php

namespace App\ClientPortal;

use App\Logs\AdministratorAlerts;
use App\Logs\SecurityLog;
use App\Models\ClientAccount;
use App\Models\User;

/**
 * Trail of the client portal accounts managed by the staff: created, given every audit or other audits, switched
 * on or off, given a new password, deleted. Each step goes to the activity journal (who, when, the account's scope)
 * and to the security log, where creating an account, giving it every audit (future ones included) and switching it
 * back on are warnings for the administrators. Creating an account and giving it every audit are also e-mailed to them.
 */
class ClientAccountJournal
{
    public const string LOG = 'client_accounts';

    public function __construct(private SecurityLog $security, private AdministratorAlerts $alerts) {}

    public function created(ClientAccount $account, User $by): void
    {
        $this->record(SecurityLog::CLIENT_ACCOUNT_CREATED, $account, $by, important: true);
        $this->alert(SecurityLog::CLIENT_ACCOUNT_CREATED, $account, $by);

        if ($account->has_all_audits) {
            $this->record(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS, $account, $by, important: true);
            $this->alert(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS, $account, $by);
        }
    }

    public function auditsChanged(ClientAccount $account, User $by, bool $hadAllAudits): void
    {
        $grantedAll = $account->has_all_audits && ! $hadAllAudits;

        $this->record($grantedAll ? SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS : SecurityLog::CLIENT_ACCOUNT_AUDITS_CHANGED, $account, $by, important: $grantedAll);

        if ($grantedAll) {
            $this->alert(SecurityLog::CLIENT_ACCOUNT_ALL_AUDITS, $account, $by);
        }
    }

    public function switched(ClientAccount $account, User $by): void
    {
        $this->record($account->is_active ? SecurityLog::CLIENT_ACCOUNT_REACTIVATED : SecurityLog::CLIENT_ACCOUNT_DEACTIVATED, $account, $by, important: $account->is_active);
    }

    public function passwordReset(ClientAccount $account, User $by): void
    {
        $this->record(SecurityLog::CLIENT_ACCOUNT_PASSWORD_RESET, $account, $by, important: false);
    }

    public function deleted(ClientAccount $account, User $by): void
    {
        $this->record(SecurityLog::CLIENT_ACCOUNT_DELETED, $account, $by, important: false);
    }

    private function record(string $event, ClientAccount $account, User $by, bool $important): void
    {
        $scope = [
            'client_account_id' => $account->id,
            'username' => $account->username,
            'client_id' => $account->client_id,
            'has_all_audits' => $account->has_all_audits,
            'audit_ids' => $account->has_all_audits ? [] : $account->audits()->pluck('audits.id')->all(),
        ];

        activity(self::LOG)
            ->performedOn($account)
            ->causedBy($by)
            ->event($event)
            ->withProperties(['account' => $scope])
            ->log($event);

        $important ? $this->security->warning($event, $scope) : $this->security->info($event, $scope);
    }

    private function alert(string $event, ClientAccount $account, User $by): void
    {
        $this->alerts->send($event, ['by' => $by->name, 'account' => $account->name, 'username' => $account->username, 'company' => $account->client->name]);
    }
}
