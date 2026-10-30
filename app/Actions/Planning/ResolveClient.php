<?php

namespace App\Actions\Planning;

use App\Models\Client;
use Illuminate\Support\Str;

class ResolveClient
{
    /**
     * The client with that name (case-insensitive), created on the fly when it does not exist yet.
     */
    public function handle(string $name): Client
    {
        $name = trim($name);

        $existing = Client::withTrashed()->where('name', $name)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $base = Str::upper(Str::limit(Str::slug($name, '_'), 30, '')) ?: 'CLIENT';
        $code = $base;

        for ($suffix = 2; Client::withTrashed()->where('code', $code)->exists(); $suffix++) {
            $code = $base.'_'.$suffix;
        }

        return Client::query()->create(['name' => $name, 'code' => $code, 'is_active' => true]);
    }
}
