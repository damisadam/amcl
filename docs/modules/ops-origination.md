# Ops portal – Origination (LMS §2, §6, §7, §10; DFS §1)

Owner module: `app/Livewire/Ops/{Dashboard,Customers,Kyc,Leads,Applications,Approvals,FieldVisits}`, routes in
`routes/ops/ops.php` and `routes/ops/lms.php`. Module services: `App\Services\Kyc\CustomerAdminService`,
`App\Services\Lending\LeadService`. All other behaviour is delegated to core services (ApplicationService,
UnderwritingService, AgreementService, ApprovalEngine, OnboardingService, DocumentStore).

| Route | Screen | Permission (any of) |
|---|---|---|
| `ops.dashboard` | Role-aware KPIs (live DB queries) / task view for visit officers & recovery agents | `dashboard.view`, `field_visits.perform`, `recovery.work` |
| `ops.customers.index` | CIF list, filters (KYC, AML*, status, branch), CSV export (masked unless sensitive) | `customers.view` (+`customers.export`) |
| `ops.customers.create` | Branch-assisted onboarding, in-branch itemised consents, CNIC upload, optional e-KYC run | `customers.create` |
| `ops.customers.show` | 360°: profile, KYC steps, documents (upload/verify/reject, versions, expiry), applications, loans, wallet, notes, consents, change requests (SoD), blacklist, archive | `customers.view` (+ action perms) |
| `ops.kyc.index` | Identity manual review, AML/CFT matches*, expiring CNIC documents | `kyc.view`, `kyc.review`, `aml.review` |
| `ops.leads.index` | Lead pipeline new→contacted→qualified→converted/dropped, assign, convert to draft application | `leads.view` / `leads.manage` |
| `ops.applications.index` | Status-tab queues, filters (product, branch, channel, dates), CSV export | `applications.view` (+`reports.export`) |
| `ops.applications.create` | Staff/vendor-assisted capture for an existing customer, live quote + indicative DBR | `applications.create` |
| `ops.applications.show` | Case file: overview, documents, guarantors, bureau, visits, assessment, objections, approval history, KFS & signatures, timeline; inline approval decision | `applications.view` (+ action perms) |
| `ops.approvals.index` | Unified inbox for ALL approval types (loan + finance): my tasks / overdue / all pending / completed by me; approve, reject, return, delegate | `approvals.act` |
| `ops.visits.index` / `ops.visits.show` | Visit Officer portal: GPS check-in, proof photos, checklist, recommendation; supervisors reschedule/reassign/cancel | `field_visits.perform` / `field_visits.view` / `field_visits.assign` |

\* AML data is only shown with `aml.view`; full CNIC / mobile only with `customers.view_sensitive`;
CNIC/selfie documents are listed as "restricted" for staff without it (download is also blocked in `DocumentController`).

## Rules enforced
- Branch scoping: `RecordAccess::applicationsQuery` – staff without `applications.view_all` (and not at head office) see their branch or own cases only.
- Every action re-checks permission server-side (`need()`); business errors from services are shown, never stack traces.
- Rejection requires a reason code + notes; returns require a reason; reject/return in the approval hierarchy go through `ApprovalEngine` (SoD: requester/creator cannot approve; one user per stage; delegation only to holders of the same stage role).
- Applications in `pending_approval` can only be rejected/returned through the approval task, not the case action bar.
- Identity fields change only through a change request approved by a different user.
- Field visit submission requires at least one proof-of-visit upload; GPS captured from the browser when permitted.
- Approvals inbox lists only requests where `ApprovalEngine::canAct()` is true; finance subjects are summarised inline (FMS screens are owned by the finance module).
- Real-time: queues listen for the `realtime` Livewire event (Reverb) and poll every 30 s as fallback.

## Tests
`tests/Feature/Ops/Origination/OriginationTest.php` – dashboard role views, staff onboarding + e-KYC, sensitive data masking,
KYC/AML manual review, lead conversion, full origination (submit → review → bureau → visit → assessment → 5-stage approval
via inbox → KFS generated), return/reject rules, authorization denials (role, branch, tenant), HTTP rendering of all pages.
