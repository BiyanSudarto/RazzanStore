<?php

namespace App\Console\Commands;

use App\Services\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=100 : Maximum number of payment attempts to check}';

    protected $description = 'Reconcile pending and unknown Midtrans payment attempts using GET Status';

    public function handle(PaymentReconciliationService $reconciliation): int
    {
        $summary = $reconciliation->run((int) $this->option('limit'));

        foreach ($summary as $key => $value) {
            $this->line($key.': '.$value);
        }

        return self::SUCCESS;
    }
}
