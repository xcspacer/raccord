<?php

namespace Database\Seeders\Demo;

use App\Enums\ClientRemarkSide;
use App\Enums\ClientRemarkStatus;
use App\Enums\ClientRemarkTarget;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientAccount;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Seeder;

/**
 * Invented client portal accounts (one seeing every audit, one limited to the first audit) and a few notes.
 */
class ClientPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::query()->updateOrCreate(['code' => 'FIBRENET'], ['name' => 'FibreNet Telecom', 'is_active' => true, 'portal_subtitle' => 'Demo network']);

        ClientAccount::query()->updateOrCreate(
            ['username' => 'client'],
            ['client_id' => $client->id, 'name' => 'Claire Fontaine', 'password' => DemoSeeder::PASSWORD, 'has_all_audits' => true, 'is_active' => true],
        );

        $limited = ClientAccount::query()->updateOrCreate(
            ['username' => 'client.limited'],
            ['client_id' => $client->id, 'name' => 'Paul Girard', 'password' => DemoSeeder::PASSWORD, 'has_all_audits' => false, 'is_active' => true],
        );

        $limited->audits()->sync(Audit::query()->orderBy('id')->limit(1)->pluck('id'));

        $this->notes($client);
    }

    private function notes(Client $client): void
    {
        $audit = Audit::query()->orderBy('id')->first();
        $box = $audit?->boxes()->whereNull('removed_at')->orderBy('name')->first();
        $account = ClientAccount::query()->where('username', 'client')->first();

        if ($audit === null || $box === null || $account === null || $audit->clientRemarks()->exists()) {
            return;
        }

        $author = ['client_account_id' => $account->id, 'author_name' => $account->name, 'author_company' => $client->name];

        $audit->clientRemarks()->create($author + [
            'target' => ClientRemarkTarget::Box, 'audit_box_id' => $box->id, 'target_label' => $box->name,
            'body' => 'The label photo is blurry, please take it again.', 'status' => ClientRemarkStatus::Open,
        ]);

        $general = $audit->clientRemarks()->create($author + [
            'target' => ClientRemarkTarget::Audit, 'target_label' => $audit->cabinet_key,
            'body' => 'Please send us the cabinet report by Friday.', 'status' => ClientRemarkStatus::Answered,
        ]);

        $general->replies()->create(['author_side' => ClientRemarkSide::Staff, 'author_name' => 'Camille Bernard', 'body' => 'Noted, it will be ready on Thursday.', 'created_at' => now()]);
    }
}
