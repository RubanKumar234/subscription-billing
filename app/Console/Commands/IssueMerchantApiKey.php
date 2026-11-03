<?php

namespace App\Console\Commands;

use App\Models\MerchantApiKey;
use App\Models\Merchant;
use Illuminate\Console\Command;

class IssueMerchantApiKey extends Command
{
    protected $signature = 'merchant:issue-key {merchant_id} {name=usage}';

    protected $description = 'Issue a merchant API key for usage ingestion';

    public function handle()
    {
        $merchantId = (int) $this->argument('merchant_id');

        if (! Merchant::query()->where('id', $merchantId)->where('status', 'active')->exists()) {
            $this->error('Active merchant not found.');

            return 1;
        }

        $key = bin2hex(random_bytes(32));

        MerchantApiKey::query()->insert([
            'merchant_id' => $merchantId,
            'name' => $this->argument('name'),
            'key_hash' => hash('sha256', $key),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line('API key (shown once): '.$key);

        return 0;
    }
}
