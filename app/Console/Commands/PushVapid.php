<?php

namespace App\Console\Commands;

use App\Push\PushConfig;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushVapid extends Command
{
    protected $signature = 'push:vapid {--check : Report whether push is configured (never prints the private key)}';

    protected $description = 'Generate VAPID keys for this environment, or check the configured ones';

    public function handle(): int
    {
        if ($this->option('check')) {
            if (PushConfig::configured()) {
                $this->info('Push is configured.');
                $this->line('VAPID_PUBLIC_KEY='.config('push.public_key'));

                return 0;
            }
            $this->warn('Push is not configured: VAPID_SUBJECT, VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY must all be set.');

            return 1;
        }

        $keys = VAPID::createVapidKeys();
        $this->line('VAPID_SUBJECT=mailto:admin@example.com');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Put these lines in the server app.env of THIS environment only (set your own subject), then restart app and worker. Never commit them.');

        return 0;
    }
}
