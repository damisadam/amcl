# Production readiness checklist

| Area | Item | Status |
|---|---|---|
| Integrations | Certified live adapters for NADRA, Tasdeeq/Data Check, Raast/sponsor bank, Easypaisa, JazzCash, 1Bill, NIFT eSign, SMS/WhatsApp/email; provider error-code mapping; webhook signature schemes | ☐ open |
| Legal / regulatory | Safeguarding model (D-001), e-money licensing, SECP digital lending conduct, DPN/affidavit e-sign clearance (D-013), official report templates (D-015) | ☐ open |
| Shariah / accounting | Profit recognition policy (D-004), Takaful (D-011), charity policy (D-010), provisioning rates (D-012), auditor sign-off on posting rules | ☐ open |
| Security | Third-party penetration test (AGR §20.1), staff MFA enforcement (D-016), APP_KEY/secret management & rotation, WAF, TLS, CSP review, dependency scanning | ☐ open |
| Data | Pakistan data residency hosting, encrypted backups, DR plan & RPO/RTO, retention policy, PII access reviews | ☐ open |
| Operations | `AMCL_DEMO_MODE=false`, demo data never seeded, Horizon supervisors sized, Reverb behind TLS, scheduler monitoring, log shipping, alerting on failed jobs and recon exceptions | ☐ open |
| Performance | Agreed scalability benchmarks (AGR §8.5), load tests, DB indexes review, report pagination/async exports | ☐ open |
| Documents | Signed PDF generation, document retention, storage on private object store | ☐ open |
| UAT | Per-sprint UAT with named reviewers; bank UAT for Raast | ☐ open |
