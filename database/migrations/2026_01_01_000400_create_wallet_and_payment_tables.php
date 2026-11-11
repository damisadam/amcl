<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment orchestration (all external money movement) and the Digital Wallet ledger.
 * Wallet balances are only ever changed by App\Services\Wallet\WalletLedger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every external money movement (inbound or outbound) is a payment order handled by a provider adapter.
        Schema::create('payment_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->string('purpose', 30);                      // PaymentPurpose
            $t->string('direction', 10);                    // inbound|outbound
            $t->string('provider', 40);
            $t->string('mode', 10)->default('demo');
            $t->decimal('amount', 18, 2);
            $t->string('currency', 3)->default('PKR');
            $t->string('status', 20)->default('created');   // PaymentOrderStatus
            $t->nullableMorphs('payable');                  // originating business object
            $t->string('counterparty_name')->nullable();
            $t->string('counterparty_account', 40)->nullable();
            $t->string('counterparty_bank')->nullable();
            $t->string('idempotency_key', 100);
            $t->string('provider_ref', 60)->nullable();
            $t->string('scenario', 40)->nullable();         // simulated outcome selected for demo
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedSmallInteger('callback_count')->default(0);
            $t->string('failure_code', 60)->nullable();
            $t->string('failure_message', 500)->nullable();
            $t->foreignId('bank_account_id')->nullable();   // tenant settlement account (fk in accounting migration)
            $t->json('meta')->nullable();
            $t->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->string('reconciliation_status', 20)->default('unreconciled'); // unreconciled|matched|exception
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['provider', 'provider_ref']);
            $t->index(['tenant_id', 'status', 'purpose']);
        });

        // Inbox of provider callbacks/webhooks; event_id uniqueness gives duplicate-callback protection.
        Schema::create('payment_callbacks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider', 40);
            $t->string('event_id', 80);
            $t->string('provider_ref', 60)->nullable();
            $t->string('reported_status', 20)->nullable();
            $t->decimal('reported_amount', 18, 2)->nullable();
            $t->json('payload');
            $t->boolean('signature_valid')->default(true);
            $t->string('result', 20);                       // processed|duplicate|rejected|error|ignored
            $t->string('error', 500)->nullable();
            $t->timestamp('received_at');
            $t->timestamps();
            $t->unique(['tenant_id', 'provider', 'event_id']);
        });

        Schema::create('wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->morphs('owner');                            // Customer or Vendor (merchant)
            $t->string('wallet_no', 30);
            $t->string('title');
            $t->string('mobile', 20)->nullable();           // lookup key for wallet-to-wallet
            $t->string('status', 20)->default('pending');   // WalletStatus
            $t->string('currency', 3)->default('PKR');
            $t->decimal('balance', 18, 2)->default(0);      // cached; authoritative = sum(ledger entries)
            $t->decimal('held_balance', 18, 2)->default(0); // funds reserved by pending outbound txns
            $t->decimal('daily_limit', 18, 2);
            $t->decimal('per_transaction_limit', 18, 2);
            $t->decimal('monthly_limit', 18, 2);
            $t->decimal('max_balance', 18, 2);
            $t->string('pin_hash')->nullable();
            $t->unsignedTinyInteger('pin_attempts')->default(0);
            $t->boolean('biometric_enabled')->default(false);
            $t->string('status_reason')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'wallet_no']);
            $t->unique(['tenant_id', 'owner_type', 'owner_id']);
            $t->index(['tenant_id', 'mobile']);
        });

        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);                         // WalletTransactionType
            $t->string('direction', 10);                    // credit|debit
            $t->decimal('amount', 18, 2);
            $t->decimal('fee', 18, 2)->default(0);
            $t->string('currency', 3)->default('PKR');
            $t->string('status', 20)->default('pending');   // WalletTransactionStatus
            $t->boolean('is_external')->default(false);     // touches safeguarded bank account (Wallet "Key Principle")
            $t->foreignId('counterparty_wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $t->foreignId('related_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->nullableMorphs('subject');                  // e.g. LoanPayment, BillPayment, Vendor (merchant)
            $t->string('provider', 40)->nullable();
            $t->string('idempotency_key', 100);
            $t->string('description')->nullable();
            $t->string('failure_reason', 500)->nullable();
            $t->json('meta')->nullable();
            $t->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'wallet_id', 'created_at']);
            $t->index(['tenant_id', 'type', 'status']);
        });

        // Immutable ledger. A unique key per (transaction, wallet, entry_type) blocks double posting.
        Schema::create('wallet_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('wallet_transaction_id')->constrained()->cascadeOnDelete();
            $t->string('entry_type', 10);                   // credit|debit|hold|release
            $t->decimal('amount', 18, 2);
            $t->decimal('balance_before', 18, 2);
            $t->decimal('balance_after', 18, 2);
            $t->decimal('held_after', 18, 2)->default(0);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['wallet_transaction_id', 'wallet_id', 'entry_type'], 'wallet_ledger_unique_posting');
        });

        Schema::create('linked_bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('bank_name');
            $t->string('iban', 34);
            $t->string('account_title');
            $t->string('raast_alias', 30)->nullable();
            $t->boolean('is_verified')->default(false);     // via simulated title fetch
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->unique(['customer_id', 'iban']);
        });

        Schema::create('bill_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40);
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('biller_code', 30);                  // LESCO, SNGPL, PTCL...
            $t->string('biller_name');
            $t->string('bill_type', 20);                    // electricity|gas|telephone|water|internet
            $t->string('consumer_ref', 40);
            $t->string('consumer_name')->nullable();
            $t->decimal('amount', 18, 2);
            $t->date('due_date')->nullable();
            $t->string('funding_source', 20);               // wallet|raast
            $t->string('status', 20)->default('pending');   // pending|succeeded|failed|reversed
            $t->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider_ref', 60)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'reference']);
        });

        Schema::create('complaints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('ticket_no', 30);
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 30);                     // ComplaintCategory
            $t->string('channel', 20)->default('web');
            $t->string('subject');
            $t->text('description');
            $t->nullableMorphs('related');                  // WalletTransaction, LoanAccount, LoanApplication...
            $t->string('priority', 10)->default('normal');
            $t->string('status', 20)->default('open');      // ComplaintStatus
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('sla_due_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->text('resolution')->nullable();
            $t->unsignedTinyInteger('satisfaction')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'ticket_no']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('complaint_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_internal')->default(false);
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['complaint_messages', 'complaints', 'bill_payments', 'linked_bank_accounts', 'wallet_ledger_entries',
            'wallet_transactions', 'wallets', 'payment_callbacks', 'payment_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
