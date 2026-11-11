# Financial ledger (wallet & payments)

## Wallet ledger
* `wallets.balance` / `held_balance` are **caches** maintained only by `App\Services\Wallet\WalletLedger`.
* Every movement writes an immutable `wallet_ledger_entries` row (`credit|debit|hold|release`) with before/after balances.
  A unique key `(wallet_transaction_id, wallet_id, entry_type)` makes double posting impossible.
* All ledger operations run inside a DB transaction with `SELECT … FOR UPDATE` on the wallet row(s); multi-wallet
  operations lock in ascending id order to avoid deadlocks.
* Outbound external transactions (withdrawal, bill, external merchant) **hold** funds, then **capture** on provider
  success or **release** on failure. Inbound top-ups credit only on a confirmed callback.
* Reversals and refunds are compensating transactions linked by `related_transaction_id`; history is never edited.
* Integrity: `ledgerBalance()` recomputes the balance from entries (shown on the ops wallet screen and asserted in tests);
  GL *Customer Wallet Balances* = sum of wallets = *Safeguarded Wallet Account* (three-way check in wallet reports).

## Transaction states
`pending → processing → succeeded → reversed|refunded`, `pending → failed`. Idempotency key per transaction
(unique per tenant); re-submitting a form with the same key returns the original transaction.

## Payment orders (all external money movement)
`created → pending → succeeded|failed|timed_out(→ succeeded|failed) → reversed`.
`PaymentOrchestrator::initiate()` creates the order idempotently, calls the provider adapter, and schedules the
simulated callbacks. `CallbackProcessor` accepts a callback only if the HMAC signature is valid, the event id is new
(inbox `payment_callbacks`), the reference belongs to the tenant, and the amount matches exactly. The order
transition and the business effect (wallet credit, loan activation, vendor settlement) commit in one transaction.
A succeeded/reversed order also writes a simulated bank statement line (`settlement_records`) for reconciliation.

## Limits
Per wallet: per-transaction, daily, monthly, maximum balance (defaults per tenant setting; editable by
`wallet.manage` with reason). PIN required for debits (hashed, 5-attempt lock).
