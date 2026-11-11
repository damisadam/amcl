<?php
/**
 * Real concurrency check for wallet debits: N forked processes (separate DB connections) race to transfer
 * PKR 700 out of a wallet holding PKR 1,000. Exactly one must succeed; the balance must never go negative
 * and must equal the ledger. Uses a scratch database (default amcl_test_7) which it RESETS.
 *
 * Usage: DB_DATABASE=amcl_test_7 php scripts/concurrency_check.php
 */
if (getenv('DB_DATABASE') === false) {
    putenv('DB_DATABASE=amcl_test_7');
    $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'amcl_test_7';
}
foreach (['QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array'] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Tenant;
use App\Models\Wallet;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$db = config('database.connections.mysql.database');
if (in_array($db, ['amcl'], true) || app()->isProduction()) {
    exit("Refusing to run against [$db].\n");
}
echo "Preparing scratch database [$db]…\n";
Artisan::call('migrate:fresh', ['--force' => true]);

$fixtures = new class { use Tests\Concerns\BuildsTenants; public function __call($m, $a) { return $this->{$m}(...$a); } };
$tenant = $fixtures->makeTenant('race');
$payer = $fixtures->customer($tenant);
$payee = $fixtures->customer($tenant);
TenantContext::set($tenant);
$ws = app(WalletService::class);
$w = $payer->customer->wallet()->first();
$ws->setPin($w, '4826');
$ws->topUp($w, '1000', 'raast', 'seed', 'success', $payer);
$walletId = $w->id;
$payeeMobile = $payee->customer->wallet->mobile;
$payerId = $payer->id;
DB::disconnect();

$n = 6;
$pids = [];
for ($i = 0; $i < $n; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::reconnect();
        TenantContext::set(Tenant::where('code', 'race')->first());
        auth()->setUser(App\Models\User::find($payerId));
        try {
            app(WalletService::class)->transfer(Wallet::find($walletId), $payeeMobile, '700', 'race-'.$i, '4826');
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    }
    $pids[] = $pid;
}
$ok = 0;
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
    $ok += pcntl_wexitstatus($status) === 0 ? 1 : 0;
}
DB::reconnect();
TenantContext::set(Tenant::where('code', 'race')->first());
$w = Wallet::find($walletId);
$ledger = app(WalletLedger::class)->ledgerBalance($w);
printf("processes=%d succeeded=%d balance=%s ledger=%s\n", $n, $ok, $w->balance, $ledger);
$pass = $ok === 1 && bccomp((string) $w->balance, '300.00', 2) === 0 && bccomp($ledger, (string) $w->balance, 2) === 0;
echo $pass ? "PASS: exactly one debit succeeded; no overdraft; ledger consistent.\n" : "FAIL\n";
exit($pass ? 0 : 1);
