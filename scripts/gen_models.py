"""One-off generator for simple tenant-owned Eloquent models (kept for reference; models are hand-edited after)."""
import os, sys
M = {}
def m(name, table=None, casts=None, rels=None, tenant=True, extra=''):
    M[name] = dict(table=table, casts=casts or {}, rels=rels or [], tenant=tenant, extra=extra)

D='decimal:2'
m('Region', rels=[('hasMany','areas','Area'),])
m('Area', rels=[('belongsTo','region','Region'),('hasMany','branches','Branch')])
m('Branch', casts={'is_head_office':'boolean'}, rels=[('belongsTo','area','Area'),('belongsToMany','products','Product'),('belongsToMany','funds','Fund')])
m('Department')
m('Employee', casts={'joined_on':'date','basic_salary':D,'allowances':D}, rels=[('belongsTo','branch','Branch'),('belongsTo','department','Department'),('belongsTo','reportsTo','Employee','reports_to_id'),('hasOne','user','User')], extra='use \\Illuminate\\Database\\Eloquent\\SoftDeletes;')
m('TenantSetting', casts={'value':'array'})
m('Holiday', casts={'date':'date'})
m('PaymentType', casts={'is_active':'boolean'})
m('LoanCategory')
m('BlacklistEntry', casts={'is_active':'boolean'}, rels=[('belongsTo','addedBy','User','added_by')])
m('DocumentType', casts={'tracks_expiry':'boolean','is_active':'boolean'})
m('ContentItem', casts={'is_active':'boolean'})
m('NotificationTemplate', casts={'is_active':'boolean'})
m('NotificationLog', casts={'simulated':'boolean'}, rels=[('belongsTo','user','User')], tenant=False)
m('IntegrationLog', casts={'request':'array','response':'array','created_at':'datetime'}, rels=[('morphTo','subject',None)], tenant=False, extra='public const UPDATED_AT = null;')
m('VendorItem', casts={'price':D,'is_active':'boolean'}, rels=[('belongsTo','vendor','Vendor')])
m('CorporateClient', casts={'authorized_signatories':'array'}, rels=[('hasMany','staff','CorporateStaff'),('hasMany','customers','Customer')])
m('CorporateStaff', table='corporate_staff', casts={'joined_on':'date','monthly_salary':D}, rels=[('belongsTo','corporateClient','CorporateClient'),('belongsTo','customer','Customer'),('hasMany','salaries','StaffSalary')])
m('StaffSalary', casts={'gross':D,'loan_deduction':D,'net':D}, rels=[('belongsTo','staff','CorporateStaff','corporate_staff_id')])
m('NextOfKin', table='next_of_kins', rels=[('belongsTo','customer','Customer')])
m('CustomerNote', rels=[('belongsTo','customer','Customer'),('belongsTo','user','User')])
m('CustomerChangeRequest', casts={'decided_at':'datetime'}, rels=[('belongsTo','customer','Customer'),('belongsTo','requester','User','requested_by'),('belongsTo','decider','User','decided_by')])
m('Consent', casts={'granted':'boolean','recorded_at':'datetime','withdrawn_at':'datetime','consent_type':'\\App\\Enums\\ConsentType'}, rels=[('belongsTo','customer','Customer')])
m('KycVerification', casts={'result':'array','reviewed_at':'datetime','score':'decimal:2','step':'\\App\\Enums\\KycStep','status':'\\App\\Enums\\VerificationResult'}, rels=[('belongsTo','customer','Customer'),('belongsTo','reviewer','User','reviewed_by'),('belongsTo','integrationLog','IntegrationLog')])
m('Charge', casts={'value':'decimal:3','type':'\\App\\Enums\\ChargeType'}, rels=[('belongsTo','productVersion','ProductVersion')])
m('ProductRequiredDocument', casts={'is_mandatory':'boolean','min_amount':D}, rels=[('belongsTo','productVersion','ProductVersion'),('belongsTo','documentType','DocumentType')])
m('Fund', casts={'committed_amount':D,'investor_profit_share_pct':'decimal:2','start_date':'date','end_date':'date'}, rels=[('belongsToMany','branches','Branch'),('hasMany','loanAccounts','LoanAccount')])
m('ApprovalStage', casts={'min_amount':D,'comment_required_on_approve':'boolean','comment_required_on_reject':'boolean','allow_return':'boolean'}, rels=[('belongsTo','workflow','ApprovalWorkflow','approval_workflow_id'),('belongsTo','role','Role')])
m('ApprovalAction', casts={'created_at':'datetime'}, rels=[('belongsTo','request','ApprovalRequest','approval_request_id'),('belongsTo','stage','ApprovalStage','approval_stage_id'),('belongsTo','actor','User','actor_id'),('belongsTo','delegate','User','delegated_to')], extra='public const UPDATED_AT = null;')
m('Lead', casts={'requested_amount':D}, rels=[('belongsTo','product','Product'),('belongsTo','branch','Branch'),('belongsTo','vendor','Vendor'),('belongsTo','assignee','User','assigned_to')])
m('ApplicationStatusHistory', casts={'created_at':'datetime','visible_to_customer':'boolean','from_status':'\\App\\Enums\\ApplicationStatus','to_status':'\\App\\Enums\\ApplicationStatus'}, rels=[('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','actor','User','actor_id')], extra='public const UPDATED_AT = null;')
m('Guarantor', casts={'monthly_income':D,'consent_at':'datetime','signed_at':'datetime'}, rels=[('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','customer','Customer')])
m('BureauCheck', casts={'report':'array','total_outstanding':D,'monthly_obligations':D}, rels=[('belongsTo','application','LoanApplication','loan_application_id')])
m('CreditAssessment', casts={'monthly_income':D,'other_income':D,'household_expenses':D,'existing_obligations':D,'proposed_instalment':D,'dbr':'decimal:2','dbr_limit':'decimal:2','dbr_pass':'boolean','business_cashflow':'array','score_breakdown':'array'}, rels=[('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','assessor','User','assessed_by')])
m('FieldVisit', casts={'scheduled_for':'date','checked_in_at':'datetime','submitted_at':'datetime','address_match':'boolean','checklist':'array','status':'\\App\\Enums\\FieldVisitStatus'}, rels=[('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','customer','Customer'),('belongsTo','officer','User','assigned_to'),('morphMany','documents','Document','documentable')])
m('Objection', casts={'resolved_at':'datetime','seen_at':'datetime','visible_to_customer':'boolean'}, rels=[('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','raiser','User','raised_by'),('belongsTo','assignee','User','assigned_to')])
m('KeyFactStatement', casts={'terms':'array','generated_at':'datetime','acknowledged_at':'datetime'}, rels=[('belongsTo','application','LoanApplication','loan_application_id')])
m('SignatureRequest', casts={'signed_at':'datetime'}, rels=[('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','documentType','DocumentType'),('belongsTo','document','Document')])
m('Instalment', casts={'due_date':'date','paid_at':'datetime','principal_due':D,'profit_due':D,'charges_due':D,'late_charge_due':D,'total_due':D,'principal_paid':D,'profit_paid':D,'charges_paid':D,'late_charge_paid':D,'total_paid':D,'status':'\\App\\Enums\\InstalmentStatus'}, rels=[('belongsTo','loanAccount','LoanAccount')])
m('PaymentAllocation', casts={'amount':D,'created_at':'datetime'}, rels=[('belongsTo','payment','LoanPayment','loan_payment_id'),('belongsTo','instalment','Instalment')], extra='public const UPDATED_AT = null;')
m('RecoveryContact', casts={'contacted_at':'datetime','ptp_date':'date','ptp_amount':D}, rels=[('belongsTo','loanAccount','LoanAccount'),('belongsTo','agent','User','agent_id')])
m('PortfolioTransfer', casts={'loan_account_ids':'array'}, rels=[('belongsTo','fromBranch','Branch','from_branch_id'),('belongsTo','toBranch','Branch','to_branch_id'),('belongsTo','fromOfficer','User','from_officer_id'),('belongsTo','toOfficer','User','to_officer_id'),('belongsTo','actor','User','transferred_by')])
m('PaymentCallback', casts={'payload':'array','signature_valid':'boolean','received_at':'datetime','reported_amount':D}, rels=[('belongsTo','paymentOrder','PaymentOrder')], tenant=False)
m('WalletLedgerEntry', casts={'amount':D,'balance_before':D,'balance_after':D,'held_after':D,'created_at':'datetime'}, rels=[('belongsTo','wallet','Wallet'),('belongsTo','transaction','WalletTransaction','wallet_transaction_id')], extra='public const UPDATED_AT = null;')
m('LinkedBankAccount', casts={'is_verified':'boolean','is_default':'boolean'}, rels=[('belongsTo','customer','Customer')])
m('BillPayment', casts={'amount':D,'due_date':'date'}, rels=[('belongsTo','customer','Customer'),('belongsTo','walletTransaction','WalletTransaction'),('belongsTo','paymentOrder','PaymentOrder')])
m('ComplaintMessage', casts={'is_internal':'boolean'}, rels=[('belongsTo','complaint','Complaint'),('belongsTo','user','User')])
m('CostCenter', casts={'is_active':'boolean'}, rels=[('belongsTo','branch','Branch')])
m('PostingRule', casts={'is_active':'boolean'}, rels=[('belongsTo','account','Account')])
m('FiscalYear', casts={'start_date':'date','end_date':'date'}, rels=[('hasMany','periods','AccountingPeriod')])
m('AccountingPeriod', casts={'start_date':'date','end_date':'date','closed_at':'datetime','reopened_at':'datetime'}, rels=[('belongsTo','fiscalYear','FiscalYear'),('belongsTo','closer','User','closed_by')])
m('JournalLine', casts={'debit':D,'credit':D}, rels=[('belongsTo','voucher','JournalVoucher','journal_voucher_id'),('belongsTo','account','Account'),('belongsTo','costCenter','CostCenter'),('belongsTo','branch','Branch'),('belongsTo','customer','Customer'),('belongsTo','vendor','Vendor'),('belongsTo','fund','Fund')])
m('BankAccount', casts={'minimum_balance':D,'is_simulated':'boolean','is_active':'boolean'}, rels=[('belongsTo','account','Account')])
m('SettlementRecord', casts={'value_date':'date','amount':D,'is_simulated':'boolean'}, rels=[('belongsTo','bankAccount','BankAccount')])
m('ReconciliationItem', casts={'internal_amount':D,'external_amount':D}, rels=[('belongsTo','batch','ReconciliationBatch','reconciliation_batch_id'),('belongsTo','paymentOrder','PaymentOrder'),('belongsTo','settlementRecord','SettlementRecord'),('belongsTo','resolver','User','resolved_by')])
m('SuspenseItem', casts={'amount':D}, rels=[('belongsTo','settlementRecord','SettlementRecord'),('belongsTo','journalVoucher','JournalVoucher')])
m('VendorBill', casts={'bill_date':'date','due_date':'date','amount':D,'wht_amount':D,'paid_amount':D}, rels=[('belongsTo','vendor','Vendor'),('belongsTo','application','LoanApplication','loan_application_id'),('belongsTo','expenseAccount','Account','expense_account_id'),('belongsTo','journalVoucher','JournalVoucher'),('hasMany','payments','VendorPayment')])
m('OtherReceivable', casts={'amount':D,'received_amount':D,'due_date':'date'})
m('LoanProvision', casts={'as_of':'date','rate':'decimal:2','outstanding':D,'required_provision':D,'movement':D}, rels=[('belongsTo','loanAccount','LoanAccount'),('belongsTo','journalVoucher','JournalVoucher')])
m('FixedAsset', casts={'acquired_on':'date','disposed_on':'date','cost':D,'salvage_value':D,'accumulated_depreciation':D,'disposal_proceeds':D}, rels=[('belongsTo','branch','Branch'),('hasMany','depreciations','AssetDepreciation')])
m('AssetDepreciation', casts={'amount':D}, rels=[('belongsTo','asset','FixedAsset','fixed_asset_id'),('belongsTo','period','AccountingPeriod','accounting_period_id'),('belongsTo','journalVoucher','JournalVoucher')])
m('PayrollLine', casts={'basic':D,'allowances':D,'gross':D,'income_tax':D,'provident_fund':D,'eobi':D,'staff_loan_deduction':D,'net':D}, rels=[('belongsTo','run','PayrollRun','payroll_run_id'),('belongsTo','employee','Employee')])
m('Budget', rels=[('belongsTo','fiscalYear','FiscalYear'),('hasMany','lines','BudgetLine')])
m('BudgetLine', casts={'amount':D}, rels=[('belongsTo','budget','Budget'),('belongsTo','account','Account'),('belongsTo','costCenter','CostCenter'),('belongsTo','period','AccountingPeriod','accounting_period_id')])
m('ProfitDistribution', casts={'gross_profit':D,'investor_share_pct':'decimal:2','investor_share':D,'wht_amount':D}, rels=[('belongsTo','fund','Fund'),('belongsTo','period','AccountingPeriod','accounting_period_id'),('belongsTo','journalVoucher','JournalVoucher')])

out = sys.argv[1]
for name, spec in M.items():
    p = os.path.join(out, name + '.php')
    if os.path.exists(p):
        continue
    uses = set()
    body = []
    if spec['tenant']:
        uses.add('App\\Models\\Concerns\\BelongsToTenant')
    if spec['table']:
        body.append("    protected $table = '%s';\n" % spec['table'])
    body.append("    protected $guarded = ['id', 'tenant_id'];\n")
    ex = spec['extra']
    traits = ['BelongsToTenant'] if spec['tenant'] else []
    if 'SoftDeletes' in ex:
        uses.add('Illuminate\\Database\\Eloquent\\SoftDeletes'); traits.append('SoftDeletes'); ex = ''
    if ex:
        body.insert(0, '    ' + ex + '\n')
    if spec['casts']:
        c = '\n'.join("            '%s' => %s," % (k, (v.lstrip('\\') + '::class') if v.startswith('\\') else "'%s'" % v) for k, v in spec['casts'].items())
        body.append('    protected function casts(): array\n    {\n        return [\n%s\n        ];\n    }\n' % c)
    for r in spec['rels']:
        kind, meth, target = r[0], r[1], r[2]
        fk = r[3] if len(r) > 3 else None
        rt = {'belongsTo':'BelongsTo','hasMany':'HasMany','hasOne':'HasOne','belongsToMany':'BelongsToMany','morphTo':'MorphTo','morphMany':'MorphMany'}[kind]
        uses.add('Illuminate\\Database\\Eloquent\\Relations\\' + rt)
        if kind == 'morphTo':
            call = '$this->morphTo()'
        elif kind == 'morphMany':
            call = "$this->morphMany(%s::class, '%s')" % (target, fk)
        else:
            call = '$this->%s(%s::class%s)' % (kind, target, (", '%s'" % fk) if fk else '')
        body.append('    public function %s(): %s\n    {\n        return %s;\n    }\n' % (meth, rt, call))
    u = '\n'.join('use %s;' % x for x in sorted(uses | {'Illuminate\\Database\\Eloquent\\Model'}))
    t = ('    use ' + ', '.join(traits) + ';\n\n') if traits else ''
    src = '<?php\n\nnamespace App\\Models;\n\n%s\n\nclass %s extends Model\n{\n%s%s}\n' % (u, name, t, '\n'.join(body))
    open(p, 'w').write(src)
print(len(M))
