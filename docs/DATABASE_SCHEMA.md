# Database schema

_Generated from the live MySQL schema by `scripts/generate_schema_doc.php`._ All money columns are `decimal(18,2)`. Every tenant-owned table has `tenant_id` (FK `tenants`, indexed) and is scoped by `BelongsToTenant`. Unique keys enforce idempotency (payment orders, wallet transactions, loan payments, disbursements, journal vouchers, callback inbox, wallet ledger postings).

## Platform & identity

### `tenants`

`id` _bigint unsigned_, `code` _varchar(40)_, `name` _varchar(255)_, `legal_name` _varchar(255)?_, `registration_no` _varchar(255)?_, `license_ref` _varchar(255)?_, `ntn` _varchar(255)?_, `is_demo` _tinyint(1)_, `status` _varchar(20)_, `primary_contact_name` _varchar(255)?_, `primary_contact_email` _varchar(255)?_, `primary_contact_phone` _varchar(255)?_, `address` _varchar(255)?_, `city` _varchar(255)?_, `support_phone` _varchar(255)?_, `support_email` _varchar(255)?_, `logo_path` _varchar(255)?_, `primary_color` _varchar(9)_, `tagline` _varchar(255)?_, `tagline_ur` _varchar(255)?_, `default_locale` _varchar(5)_, `base_currency` _varchar(3)_, `wallet_safeguarding_model` _varchar(30)_, `features` _json?_, `activated_at` _timestamp?_, `suspended_at` _timestamp?_, `suspension_reason` _varchar(255)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (code)

### `tenant_settings`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `group` _varchar(40)_, `key` _varchar(80)_, `value` _json?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,group,key)

### `users`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `type` _varchar(20)_, `name` _varchar(255)_, `email` _varchar(255)?_, `mobile` _varchar(20)?_, `cnic` _varchar(15)?_, `email_verified_at` _timestamp?_, `mobile_verified_at` _timestamp?_, `password` _varchar(255)_, `status` _varchar(20)_, `branch_id` → branches _bigint unsigned?_, `department_id` → departments _bigint unsigned?_, `employee_id` → employees _bigint unsigned?_, `customer_id` _bigint unsigned?_, `vendor_id` _bigint unsigned?_, `designation` _varchar(255)?_, `locale` _varchar(5)_, `failed_login_attempts` _smallint unsigned_, `locked_until` _timestamp?_, `must_change_password` _tinyint(1)_, `password_changed_at` _timestamp?_, `mfa_enabled` _tinyint(1)_, `last_login_at` _timestamp?_, `last_login_ip` _varchar(45)?_, `created_by` → users _bigint unsigned?_, `remember_token` _varchar(100)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (tenant_id,email); (tenant_id,mobile)

### `roles`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `key` _varchar(60)_, `name` _varchar(255)_, `description` _varchar(255)?_, `scope` _varchar(20)_, `is_system` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,key)

### `permissions`

`id` _bigint unsigned_, `key` _varchar(100)_, `module` _varchar(40)_, `label` _varchar(255)_, `scope` _varchar(20)_, `is_sensitive` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (key)

### `permission_role`

`role_id` → roles _bigint unsigned_, `permission_id` → permissions _bigint unsigned_

### `role_user`

`role_id` → roles _bigint unsigned_, `user_id` → users _bigint unsigned_, `assigned_by` → users _bigint unsigned?_, `assigned_at` _timestamp?_

### `user_devices`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `user_id` → users _bigint unsigned_, `fingerprint` _varchar(64)_, `label` _varchar(255)?_, `user_agent` _varchar(500)?_, `ip` _varchar(45)?_, `is_trusted` _tinyint(1)_, `last_seen_at` _timestamp?_, `revoked_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (user_id,fingerprint)

### `otp_codes`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `identifier` _varchar(100)_, `purpose` _varchar(40)_, `code_hash` _varchar(255)_, `attempts` _tinyint unsigned_, `expires_at` _timestamp_, `consumed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `support_access_grants`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `platform_user_id` → users _bigint unsigned_, `reason` _varchar(500)_, `ticket_ref` _varchar(255)?_, `duration_hours` _smallint unsigned_, `expires_at` _timestamp_, `granted_by` → users _bigint unsigned?_, `granted_at` _timestamp?_, `revoked_at` _timestamp?_, `revoked_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `platform_settings`

`id` _bigint unsigned_, `key` _varchar(80)_, `value` _json?_, `updated_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (key)

### `audit_logs`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `actor_id` → users _bigint unsigned?_, `actor_type` _varchar(20)?_, `action` _varchar(100)_, `auditable_type` _varchar(255)?_, `auditable_id` _bigint unsigned?_, `old_values` _json?_, `new_values` _json?_, `reason` _varchar(1000)?_, `correlation_id` _varchar(64)?_, `ip` _varchar(45)?_, `user_agent` _varchar(500)?_, `created_at` _timestamp_

### `number_sequences`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `key` _varchar(40)_, `next_value` _bigint unsigned_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,key)

## Organisation & configuration

### `regions`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(20)_, `name` _varchar(255)_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `areas`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `region_id` → regions _bigint unsigned_, `code` _varchar(20)_, `name` _varchar(255)_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `branches`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `area_id` → areas _bigint unsigned_, `code` _varchar(20)_, `name` _varchar(255)_, `is_head_office` _tinyint(1)_, `address` _varchar(255)?_, `city` _varchar(255)?_, `phone` _varchar(255)?_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `departments`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(20)_, `name` _varchar(255)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `employees`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `employee_code` _varchar(30)_, `name` _varchar(255)_, `cnic` _varchar(15)?_, `designation` _varchar(255)?_, `branch_id` → branches _bigint unsigned?_, `department_id` → departments _bigint unsigned?_, `reports_to_id` → employees _bigint unsigned?_, `phone` _varchar(255)?_, `email` _varchar(255)?_, `joined_on` _date?_, `basic_salary` _decimal(18,2)_, `allowances` _decimal(18,2)_, `bank_iban` _varchar(34)?_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (tenant_id,employee_code)

### `holidays`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `date` _date_, `name` _varchar(255)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,date)

### `payment_types`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(30)_, `name` _varchar(255)_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `loan_categories`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(30)_, `name` _varchar(255)_, `regulatory_category` _varchar(255)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `blacklist_entries`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `cnic` _varchar(15)?_, `name` _varchar(255)_, `reason` _varchar(30)_, `notes` _varchar(1000)?_, `is_active` _tinyint(1)_, `added_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `document_types`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(40)_, `name` _varchar(255)_, `name_ur` _varchar(255)?_, `category` _varchar(30)_, `validity_days` _smallint unsigned?_, `tracks_expiry` _tinyint(1)_, `signing_method` _varchar(20)_, `verification_step` _varchar(40)?_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `documents`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `documentable_type` _varchar(255)_, `documentable_id` _bigint unsigned_, `document_type_id` → document_types _bigint unsigned?_, `title` _varchar(255)_, `disk` _varchar(30)_, `path` _varchar(255)_, `original_name` _varchar(255)_, `mime` _varchar(100)_, `size` _bigint unsigned_, `sha256` _varchar(64)_, `version` _smallint unsigned_, `previous_id` → documents _bigint unsigned?_, `status` _varchar(20)_, `expires_on` _date?_, `is_synthetic` _tinyint(1)_, `uploaded_by` → users _bigint unsigned?_, `verified_by` → users _bigint unsigned?_, `verified_at` _timestamp?_, `rejection_reason` _varchar(255)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

### `content_items`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `type` _varchar(20)_, `title` _varchar(255)_, `title_ur` _varchar(255)?_, `body` _text?_, `body_ur` _text?_, `sort` _smallint unsigned_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `notification_templates`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `event` _varchar(60)_, `channel` _varchar(20)_, `subject` _varchar(255)?_, `subject_ur` _varchar(255)?_, `body` _text_, `body_ur` _text?_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,event,channel)

### `notification_logs`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `user_id` → users _bigint unsigned?_, `event` _varchar(60)_, `channel` _varchar(20)_, `recipient` _varchar(255)_, `subject` _varchar(255)?_, `body` _text_, `status` _varchar(20)_, `provider_ref` _varchar(255)?_, `fallback_of_id` → notification_logs _bigint unsigned?_, `simulated` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `notifications`

`id` _char(36)_, `type` _varchar(255)_, `notifiable_type` _varchar(255)_, `notifiable_id` _bigint unsigned_, `data` _text_, `read_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `integration_settings`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `provider` _varchar(40)_, `enabled` _tinyint(1)_, `mode` _varchar(10)_, `environment` _varchar(20)_, `base_url` _varchar(255)?_, `credentials` _text?_, `options` _json?_, `live_approved_by` → users _bigint unsigned?_, `live_approved_at` _timestamp?_, `updated_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,provider)

### `integration_logs`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `provider` _varchar(40)_, `operation` _varchar(60)_, `mode` _varchar(10)_, `correlation_id` _varchar(64)_, `idempotency_key` _varchar(100)?_, `scenario` _varchar(40)?_, `request` _json?_, `response` _json?_, `status` _varchar(20)_, `error_code` _varchar(60)?_, `duration_ms` _int unsigned_, `attempt` _tinyint unsigned_, `subject_type` _varchar(255)?_, `subject_id` _bigint unsigned?_, `created_at` _timestamp_

## Customers, vendors & products

### `customers`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `cif_no` _varchar(30)_, `client_type` _varchar(20)_, `full_name` _varchar(255)_, `full_name_ur` _varchar(255)?_, `father_husband_name` _varchar(255)?_, `cnic` _varchar(15)?_, `cnic_issue_date` _date?_, `cnic_expiry_date` _date?_, `date_of_birth` _date?_, `gender` _varchar(10)?_, `marital_status` _varchar(20)?_, `mobile` _varchar(20)_, `email` _varchar(255)?_, `current_address` _varchar(255)?_, `permanent_address` _varchar(255)?_, `city` _varchar(255)?_, `occupation` _varchar(255)?_, `employment_type` _varchar(20)?_, `employer_name` _varchar(255)?_, `business_name` _varchar(255)?_, `business_type` _varchar(255)?_, `income_source` _varchar(255)?_, `monthly_income` _decimal(18,2)_, `other_income` _decimal(18,2)_, `existing_obligations` _decimal(18,2)_, `kyc_status` _varchar(20)_, `aml_status` _varchar(20)_, `aml_risk` _varchar(10)?_, `risk_rating` _varchar(10)?_, `is_blacklisted` _tinyint(1)_, `blacklist_reason` _varchar(255)?_, `status` _varchar(20)_, `onboarding_channel` _varchar(20)_, `onboarding_step` _varchar(30)?_, `origin_vendor_id` → vendors _bigint unsigned?_, `corporate_client_id` → corporate_clients _bigint unsigned?_, `branch_id` → branches _bigint unsigned?_, `assigned_officer_id` → users _bigint unsigned?_, `preferred_locale` _varchar(5)_, `photo_document_id` _varchar(255)?_, `activated_at` _timestamp?_, `archived_reason` _varchar(255)?_, `created_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (tenant_id,cif_no); (tenant_id,cnic); (tenant_id,mobile)

### `next_of_kins`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `name` _varchar(255)_, `relation` _varchar(30)_, `cnic` _varchar(15)?_, `mobile` _varchar(20)?_, `address` _varchar(255)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `customer_notes`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `user_id` → users _bigint unsigned?_, `category` _varchar(30)_, `body` _text_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `customer_change_requests`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `field` _varchar(40)_, `old_value` _varchar(255)?_, `new_value` _varchar(255)?_, `reason` _varchar(500)_, `status` _varchar(20)_, `requested_by` → users _bigint unsigned?_, `decided_by` → users _bigint unsigned?_, `decided_at` _timestamp?_, `decision_comment` _varchar(500)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `consents`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `consent_type` _varchar(40)_, `version` _varchar(10)_, `granted` _tinyint(1)_, `locale` _varchar(5)_, `text_snapshot` _text_, `ip` _varchar(45)?_, `user_agent` _varchar(500)?_, `recorded_at` _timestamp_, `withdrawn_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `kyc_verifications`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `step` _varchar(30)_, `provider` _varchar(40)?_, `status` _varchar(20)_, `score` _decimal(6,2)?_, `result` _json?_, `integration_log_id` → integration_logs _bigint unsigned?_, `reviewed_by` → users _bigint unsigned?_, `reviewed_at` _timestamp?_, `review_decision` _varchar(20)?_, `review_notes` _varchar(1000)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `corporate_clients`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(30)_, `company_name` _varchar(255)_, `registration_no` _varchar(255)?_, `ntn` _varchar(20)?_, `address` _varchar(255)?_, `contact_person` _varchar(255)?_, `contact_phone` _varchar(20)?_, `authorized_signatories` _json?_, `salary_day` _tinyint unsigned_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `corporate_staff`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `corporate_client_id` → corporate_clients _bigint unsigned_, `customer_id` → customers _bigint unsigned?_, `employee_no` _varchar(30)_, `name` _varchar(255)_, `cnic` _varchar(15)?_, `designation` _varchar(255)?_, `joined_on` _date?_, `salary_band` _varchar(10)?_, `monthly_salary` _decimal(18,2)_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,corporate_client_id,employee_no)

### `staff_salaries`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `corporate_staff_id` → corporate_staff _bigint unsigned_, `period` _varchar(7)_, `gross` _decimal(18,2)_, `loan_deduction` _decimal(18,2)_, `net` _decimal(18,2)_, `source` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (corporate_staff_id,period)

### `vendors`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(30)_, `business_name` _varchar(255)_, `owner_name` _varchar(255)?_, `cnic` _varchar(15)?_, `ntn` _varchar(20)?_, `registration_no` _varchar(255)?_, `category` _varchar(40)_, `kind` _varchar(20)_, `is_merchant` _tinyint(1)_, `merchant_code` _varchar(30)?_, `phone` _varchar(20)?_, `email` _varchar(255)?_, `address` _varchar(255)?_, `city` _varchar(255)?_, `bank_name` _varchar(255)?_, `iban` _varchar(34)?_, `account_title` _varchar(255)?_, `preferred_payment_method` _varchar(20)_, `markup_share_pct` _decimal(5,2)?_, `pricing_notes` _varchar(1000)?_, `status` _varchar(20)_, `source` _varchar(20)_, `approved_by` → users _bigint unsigned?_, `approved_at` _timestamp?_, `rejection_reason` _varchar(255)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (tenant_id,code); (tenant_id,merchant_code)

### `vendor_items`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `vendor_id` → vendors _bigint unsigned_, `name` _varchar(255)_, `sku` _varchar(40)?_, `product_category` _varchar(40)?_, `price` _decimal(18,2)_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `products`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `parent_id` → products _bigint unsigned?_, `loan_category_id` → loan_categories _bigint unsigned?_, `code` _varchar(30)_, `name` _varchar(255)_, `name_ur` _varchar(255)?_, `category` _varchar(40)_, `description` _text?_, `description_ur` _text?_, `icon` _varchar(40)?_, `shariah_structure` _varchar(30)_, `shariah_board_approved` _tinyint(1)_, `shariah_approval_ref` _varchar(255)?_, `client_segment` _varchar(30)_, `is_staff_product` _tinyint(1)_, `is_active` _tinyint(1)_, `show_in_catalog` _tinyint(1)_, `current_version_id` _bigint unsigned?_, `sort` _smallint unsigned_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (tenant_id,code)

### `product_versions`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `product_id` → products _bigint unsigned_, `version` _smallint unsigned_, `status` _varchar(20)_, `min_amount` _decimal(18,2)_, `max_amount` _decimal(18,2)_, `min_tenure` _smallint unsigned_, `max_tenure` _smallint unsigned_, `frequency` _varchar(10)_, `profit_rate` _decimal(6,3)_, `profit_method` _varchar(20)_, `down_payment_pct` _decimal(5,2)_, `min_income` _decimal(18,2)_, `max_dbr` _decimal(5,2)_, `dbr_hard_gate` _tinyint(1)_, `min_age` _tinyint unsigned_, `max_age` _tinyint unsigned_, `guarantors_required` _tinyint unsigned_, `requires_vendor` _tinyint(1)_, `disbursement_mode` _varchar(20)_, `cooling_off_hours` _smallint unsigned_, `cooling_off_waivable` _tinyint(1)_, `allow_partial_payment` _tinyint(1)_, `allow_prepayment` _tinyint(1)_, `allocation_order` _json?_, `late_payment_treatment` _varchar(20)_, `late_charge_per_day` _decimal(18,2)_, `grace_days` _tinyint unsigned_, `min_credit_grade` _varchar(3)?_, `eligibility` _json?_, `effective_from` _date_, `created_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (product_id,version)

### `charges`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `product_version_id` → product_versions _bigint unsigned_, `code` _varchar(30)_, `name` _varchar(255)_, `type` _varchar(30)_, `calculation` _varchar(20)_, `value` _decimal(18,3)_, `apply_at` _varchar(30)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `product_required_documents`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `product_version_id` → product_versions _bigint unsigned_, `document_type_id` → document_types _bigint unsigned_, `applies_to` _varchar(20)_, `is_mandatory` _tinyint(1)_, `min_amount` _decimal(18,2)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `branch_product`

`branch_id` → branches _bigint unsigned_, `product_id` → products _bigint unsigned_

## Lending

### `funds`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(30)_, `name` _varchar(255)_, `type` _varchar(30)_, `provider_name` _varchar(255)?_, `committed_amount` _decimal(18,2)_, `structure` _varchar(30)?_, `investor_profit_share_pct` _decimal(5,2)?_, `start_date` _date?_, `end_date` _date?_, `terms` _varchar(1000)?_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `branch_fund`

`branch_id` → branches _bigint unsigned_, `fund_id` → funds _bigint unsigned_

### `approval_workflows`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `type` _varchar(30)_, `name` _varchar(255)_, `product_id` → products _bigint unsigned?_, `branch_id` → branches _bigint unsigned?_, `min_amount` _decimal(18,2)?_, `max_amount` _decimal(18,2)?_, `priority` _smallint unsigned_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `approval_stages`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `approval_workflow_id` → approval_workflows _bigint unsigned_, `sequence` _smallint unsigned_, `name` _varchar(255)_, `role_id` → roles _bigint unsigned_, `min_amount` _decimal(18,2)?_, `comment_required_on_approve` _tinyint(1)_, `comment_required_on_reject` _tinyint(1)_, `allow_return` _tinyint(1)_, `sla_hours` _smallint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `approval_requests`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `approvable_type` _varchar(255)_, `approvable_id` _bigint unsigned_, `approval_workflow_id` → approval_workflows _bigint unsigned_, `type` _varchar(30)_, `amount` _decimal(18,2)_, `stage_plan` _json_, `current_sequence` _smallint unsigned?_, `current_stage_id` → approval_stages _bigint unsigned?_, `assigned_to` → users _bigint unsigned?_, `status` _varchar(20)_, `requested_by` → users _bigint unsigned?_, `stage_entered_at` _timestamp?_, `completed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `approval_actions`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `approval_request_id` → approval_requests _bigint unsigned_, `approval_stage_id` → approval_stages _bigint unsigned?_, `stage_name` _varchar(255)?_, `actor_id` → users _bigint unsigned_, `action` _varchar(20)_, `comment` _varchar(2000)?_, `delegated_to` → users _bigint unsigned?_, `created_at` _timestamp_

### `leads`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `lead_no` _varchar(30)_, `name` _varchar(255)_, `mobile` _varchar(20)_, `cnic` _varchar(15)?_, `channel` _varchar(20)_, `product_id` → products _bigint unsigned?_, `requested_amount` _decimal(18,2)?_, `status` _varchar(20)_, `branch_id` → branches _bigint unsigned?_, `vendor_id` → vendors _bigint unsigned?_, `assigned_to` → users _bigint unsigned?_, `notes` _varchar(1000)?_, `converted_application_id` _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,lead_no)

### `loan_applications`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `application_no` _varchar(30)_, `customer_id` → customers _bigint unsigned_, `product_id` → products _bigint unsigned_, `product_version_id` → product_versions _bigint unsigned_, `branch_id` → branches _bigint unsigned?_, `vendor_id` → vendors _bigint unsigned?_, `vendor_item_id` → vendor_items _bigint unsigned?_, `fund_id` → funds _bigint unsigned?_, `lead_id` → leads _bigint unsigned?_, `channel` _varchar(20)_, `status` _varchar(30)_, `asset_price` _decimal(18,2)?_, `down_payment` _decimal(18,2)_, `requested_amount` _decimal(18,2)_, `approved_amount` _decimal(18,2)?_, `tenure` _smallint unsigned_, `frequency` _varchar(10)_, `purpose` _varchar(500)?_, `asset_description` _varchar(500)?_, `indicative_instalment` _decimal(18,2)?_, `dbr` _decimal(6,2)?_, `credit_grade` _varchar(3)?_, `risk_rating` _varchar(10)?_, `loan_officer_id` → users _bigint unsigned?_, `created_by` → users _bigint unsigned?_, `previous_application_id` → loan_applications _bigint unsigned?_, `is_reevaluation` _tinyint(1)_, `submitted_at` _timestamp?_, `approved_at` _timestamp?_, `rejected_at` _timestamp?_, `rejection_code` _varchar(40)?_, `rejection_notes` _varchar(1000)?_, `withdrawn_at` _timestamp?_, `kfs_acknowledged_at` _timestamp?_, `signed_at` _timestamp?_, `cooling_off_ends_at` _timestamp?_, `cooling_off_waived` _tinyint(1)_, `cancelled_at` _timestamp?_, `cancellation_reason` _varchar(500)?_, `disbursed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_, `deleted_at` _timestamp?_

Unique: (tenant_id,application_no)

### `application_status_histories`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `from_status` _varchar(30)?_, `to_status` _varchar(30)_, `actor_id` → users _bigint unsigned?_, `comment` _varchar(1000)?_, `visible_to_customer` _tinyint(1)_, `created_at` _timestamp_

### `guarantors`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `name` _varchar(255)_, `cnic` _varchar(15)_, `mobile` _varchar(20)_, `relation` _varchar(30)?_, `address` _varchar(255)?_, `occupation` _varchar(255)?_, `monthly_income` _decimal(18,2)_, `verification_status` _varchar(20)_, `consent_at` _timestamp?_, `signed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `bureau_checks`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `subject_type` _varchar(20)_, `subject_id` _bigint unsigned_, `subject_cnic` _varchar(15)_, `provider` _varchar(40)_, `status` _varchar(20)_, `score` _smallint unsigned?_, `active_facilities` _tinyint unsigned?_, `total_outstanding` _decimal(18,2)?_, `monthly_obligations` _decimal(18,2)?_, `max_dpd_12m` _smallint unsigned?_, `report` _json?_, `consent_id` → consents _bigint unsigned?_, `integration_log_id` → integration_logs _bigint unsigned?_, `requested_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `credit_assessments`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `monthly_income` _decimal(18,2)_, `other_income` _decimal(18,2)_, `household_expenses` _decimal(18,2)_, `existing_obligations` _decimal(18,2)_, `proposed_instalment` _decimal(18,2)_, `dbr` _decimal(6,2)_, `dbr_limit` _decimal(6,2)_, `dbr_pass` _tinyint(1)_, `business_cashflow` _json?_, `bureau_score` _smallint unsigned?_, `internal_score` _smallint unsigned_, `grade` _varchar(3)_, `visit_result` _varchar(20)?_, `aml_result` _varchar(20)?_, `guarantor_result` _varchar(20)?_, `risk_rating` _varchar(10)_, `score_breakdown` _json?_, `recommendation` _varchar(20)_, `notes` _varchar(2000)?_, `assessed_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `field_visits`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned?_, `customer_id` → customers _bigint unsigned_, `visit_type` _varchar(20)_, `address` _varchar(255)_, `assigned_to` → users _bigint unsigned?_, `assigned_by` → users _bigint unsigned?_, `scheduled_for` _date_, `status` _varchar(20)_, `checked_in_at` _timestamp?_, `latitude` _decimal(10,7)?_, `longitude` _decimal(10,7)?_, `address_match` _tinyint(1)?_, `living_conditions` _varchar(30)?_, `business_conditions` _varchar(30)?_, `neighbor_verification` _varchar(500)?_, `checklist` _json?_, `recommendation` _varchar(20)?_, `remarks` _varchar(2000)?_, `submitted_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `objections`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `raised_by` → users _bigint unsigned_, `assigned_to` → users _bigint unsigned?_, `stage` _varchar(255)?_, `category` _varchar(30)_, `body` _varchar(2000)_, `visible_to_customer` _tinyint(1)_, `status` _varchar(20)_, `response` _varchar(2000)?_, `resolved_by` → users _bigint unsigned?_, `resolved_at` _timestamp?_, `seen_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `key_fact_statements`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `version` _smallint unsigned_, `terms` _json_, `generated_at` _timestamp_, `generated_by` → users _bigint unsigned?_, `acknowledged_at` _timestamp?_, `acknowledged_locale` _varchar(5)?_, `acknowledged_ip` _varchar(45)?_, `acknowledged_user_agent` _varchar(500)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `signature_requests`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_application_id` → loan_applications _bigint unsigned_, `document_type_id` → document_types _bigint unsigned_, `document_id` → documents _bigint unsigned?_, `signer_type` _varchar(20)_, `signer_id` _bigint unsigned_, `signer_name` _varchar(255)_, `signing_method` _varchar(20)_, `status` _varchar(20)_, `provider` _varchar(40)?_, `provider_ref` _varchar(255)?_, `document_hash` _varchar(64)?_, `signed_at` _timestamp?_, `signed_ip` _varchar(45)?_, `signed_user_agent` _varchar(500)?_, `integration_log_id` → integration_logs _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `loan_accounts`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `account_no` _varchar(30)_, `loan_application_id` → loan_applications _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `product_id` → products _bigint unsigned_, `product_version_id` → product_versions _bigint unsigned_, `branch_id` → branches _bigint unsigned?_, `fund_id` → funds _bigint unsigned?_, `vendor_id` → vendors _bigint unsigned?_, `shariah_structure` _varchar(30)_, `profit_rate` _decimal(6,3)_, `frequency` _varchar(10)_, `tenure` _smallint unsigned_, `principal` _decimal(18,2)_, `profit_total` _decimal(18,2)_, `charges_total` _decimal(18,2)_, `total_receivable` _decimal(18,2)_, `principal_outstanding` _decimal(18,2)_, `profit_outstanding` _decimal(18,2)_, `charges_outstanding` _decimal(18,2)_, `late_charges_outstanding` _decimal(18,2)_, `total_paid` _decimal(18,2)_, `status` _varchar(20)_, `dpd` _smallint unsigned_, `classification` _varchar(20)_, `terms_snapshot` _json_, `first_due_date` _date_, `maturity_date` _date_, `disbursed_at` _timestamp_, `closed_at` _timestamp?_, `noc_issued_at` _timestamp?_, `noc_no` _varchar(30)?_, `loan_officer_id` → users _bigint unsigned?_, `recovery_agent_id` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,account_no)

### `instalments`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_account_id` → loan_accounts _bigint unsigned_, `number` _smallint unsigned_, `due_date` _date_, `principal_due` _decimal(18,2)_, `profit_due` _decimal(18,2)_, `charges_due` _decimal(18,2)_, `late_charge_due` _decimal(18,2)_, `total_due` _decimal(18,2)_, `principal_paid` _decimal(18,2)_, `profit_paid` _decimal(18,2)_, `charges_paid` _decimal(18,2)_, `late_charge_paid` _decimal(18,2)_, `total_paid` _decimal(18,2)_, `status` _varchar(20)_, `paid_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (loan_account_id,number)

### `disbursements`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(40)_, `loan_application_id` → loan_applications _bigint unsigned_, `loan_account_id` → loan_accounts _bigint unsigned?_, `gross_amount` _decimal(18,2)_, `deductions` _decimal(18,2)_, `net_amount` _decimal(18,2)_, `recipient_type` _varchar(20)_, `recipient_id` _bigint unsigned?_, `recipient_name` _varchar(255)_, `recipient_iban` _varchar(34)?_, `bank_account_id` → bank_accounts _bigint unsigned?_, `provider` _varchar(40)_, `scenario` _varchar(40)?_, `status` _varchar(20)_, `payment_order_id` _bigint unsigned?_, `idempotency_key` _varchar(100)_, `failure_reason` _varchar(500)?_, `initiated_by` → users _bigint unsigned?_, `completed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,idempotency_key); (tenant_id,reference)

### `loan_payments`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(40)_, `receipt_no` _varchar(40)?_, `loan_account_id` → loan_accounts _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `amount` _decimal(18,2)_, `channel` _varchar(30)_, `status` _varchar(20)_, `payment_order_id` _bigint unsigned?_, `wallet_transaction_id` _bigint unsigned?_, `idempotency_key` _varchar(100)_, `external_ref` _varchar(255)?_, `allocation` _json?_, `unallocated_amount` _decimal(18,2)_, `reversal_of_id` → loan_payments _bigint unsigned?_, `reversal_reason` _varchar(500)?_, `recorded_by` → users _bigint unsigned?_, `failure_reason` _varchar(500)?_, `received_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,idempotency_key); (tenant_id,reference)

### `payment_allocations`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_payment_id` → loan_payments _bigint unsigned_, `instalment_id` → instalments _bigint unsigned_, `component` _varchar(20)_, `amount` _decimal(18,2)_, `created_at` _timestamp_

### `recovery_contacts`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_account_id` → loan_accounts _bigint unsigned_, `agent_id` → users _bigint unsigned_, `contact_type` _varchar(20)_, `contacted_party` _varchar(20)_, `contacted_at` _timestamp_, `outcome` _varchar(30)_, `ptp_date` _date?_, `ptp_amount` _decimal(18,2)?_, `ptp_status` _varchar(20)?_, `call_recording_ref` _varchar(255)?_, `notes` _varchar(2000)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `portfolio_transfers`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `from_branch_id` → branches _bigint unsigned?_, `to_branch_id` → branches _bigint unsigned?_, `from_officer_id` → users _bigint unsigned?_, `to_officer_id` → users _bigint unsigned?_, `loan_account_ids` _json_, `reason` _varchar(500)_, `transferred_by` → users _bigint unsigned_, `created_at` _timestamp?_, `updated_at` _timestamp?_

## Wallet & payments

### `payment_orders`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(40)_, `purpose` _varchar(30)_, `direction` _varchar(10)_, `provider` _varchar(40)_, `mode` _varchar(10)_, `amount` _decimal(18,2)_, `currency` _varchar(3)_, `status` _varchar(20)_, `payable_type` _varchar(255)?_, `payable_id` _bigint unsigned?_, `counterparty_name` _varchar(255)?_, `counterparty_account` _varchar(40)?_, `counterparty_bank` _varchar(255)?_, `idempotency_key` _varchar(100)_, `provider_ref` _varchar(60)?_, `scenario` _varchar(40)?_, `attempts` _tinyint unsigned_, `callback_count` _smallint unsigned_, `failure_code` _varchar(60)?_, `failure_message` _varchar(500)?_, `bank_account_id` → bank_accounts _bigint unsigned?_, `meta` _json?_, `initiated_by` → users _bigint unsigned?_, `expires_at` _timestamp?_, `settled_at` _timestamp?_, `reconciliation_status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,idempotency_key); (tenant_id,reference)

### `payment_callbacks`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned?_, `payment_order_id` → payment_orders _bigint unsigned?_, `provider` _varchar(40)_, `event_id` _varchar(80)_, `provider_ref` _varchar(60)?_, `reported_status` _varchar(20)?_, `reported_amount` _decimal(18,2)?_, `payload` _json_, `signature_valid` _tinyint(1)_, `result` _varchar(20)_, `error` _varchar(500)?_, `received_at` _timestamp_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,provider,event_id)

### `wallets`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `owner_type` _varchar(255)_, `owner_id` _bigint unsigned_, `wallet_no` _varchar(30)_, `title` _varchar(255)_, `mobile` _varchar(20)?_, `status` _varchar(20)_, `currency` _varchar(3)_, `balance` _decimal(18,2)_, `held_balance` _decimal(18,2)_, `daily_limit` _decimal(18,2)_, `per_transaction_limit` _decimal(18,2)_, `monthly_limit` _decimal(18,2)_, `max_balance` _decimal(18,2)_, `pin_hash` _varchar(255)?_, `pin_attempts` _tinyint unsigned_, `biometric_enabled` _tinyint(1)_, `status_reason` _varchar(255)?_, `activated_at` _timestamp?_, `closed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,owner_type,owner_id); (tenant_id,wallet_no)

### `wallet_transactions`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(40)_, `wallet_id` → wallets _bigint unsigned_, `type` _varchar(30)_, `direction` _varchar(10)_, `amount` _decimal(18,2)_, `fee` _decimal(18,2)_, `currency` _varchar(3)_, `status` _varchar(20)_, `is_external` _tinyint(1)_, `counterparty_wallet_id` → wallets _bigint unsigned?_, `related_transaction_id` → wallet_transactions _bigint unsigned?_, `payment_order_id` → payment_orders _bigint unsigned?_, `subject_type` _varchar(255)?_, `subject_id` _bigint unsigned?_, `provider` _varchar(40)?_, `idempotency_key` _varchar(100)_, `description` _varchar(255)?_, `failure_reason` _varchar(500)?_, `meta` _json?_, `initiated_by` → users _bigint unsigned?_, `completed_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,idempotency_key); (tenant_id,reference)

### `wallet_ledger_entries`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `wallet_id` → wallets _bigint unsigned_, `wallet_transaction_id` → wallet_transactions _bigint unsigned_, `entry_type` _varchar(10)_, `amount` _decimal(18,2)_, `balance_before` _decimal(18,2)_, `balance_after` _decimal(18,2)_, `held_after` _decimal(18,2)_, `created_at` _timestamp_

Unique: (wallet_transaction_id,wallet_id,entry_type)

### `linked_bank_accounts`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `customer_id` → customers _bigint unsigned_, `bank_name` _varchar(255)_, `iban` _varchar(34)_, `account_title` _varchar(255)_, `raast_alias` _varchar(30)?_, `is_verified` _tinyint(1)_, `is_default` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (customer_id,iban)

### `bill_payments`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(40)_, `customer_id` → customers _bigint unsigned_, `biller_code` _varchar(30)_, `biller_name` _varchar(255)_, `bill_type` _varchar(20)_, `consumer_ref` _varchar(40)_, `consumer_name` _varchar(255)?_, `amount` _decimal(18,2)_, `due_date` _date?_, `funding_source` _varchar(20)_, `status` _varchar(20)_, `wallet_transaction_id` → wallet_transactions _bigint unsigned?_, `payment_order_id` → payment_orders _bigint unsigned?_, `provider_ref` _varchar(60)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,reference)

### `complaints`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `ticket_no` _varchar(30)_, `customer_id` → customers _bigint unsigned?_, `category` _varchar(30)_, `channel` _varchar(20)_, `subject` _varchar(255)_, `description` _text_, `related_type` _varchar(255)?_, `related_id` _bigint unsigned?_, `priority` _varchar(10)_, `status` _varchar(20)_, `assigned_to` → users _bigint unsigned?_, `acknowledged_at` _timestamp?_, `sla_due_at` _timestamp?_, `resolved_at` _timestamp?_, `resolution` _text?_, `satisfaction` _tinyint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,ticket_no)

### `complaint_messages`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `complaint_id` → complaints _bigint unsigned_, `user_id` → users _bigint unsigned?_, `is_internal` _tinyint(1)_, `body` _text_, `created_at` _timestamp?_, `updated_at` _timestamp?_

## Finance (FMS)

### `accounts`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(20)_, `name` _varchar(255)_, `type` _varchar(20)_, `level` _varchar(20)_, `parent_id` → accounts _bigint unsigned?_, `is_postable` _tinyint(1)_, `is_active` _tinyint(1)_, `system_role` _varchar(50)?_, `cash_flow_category` _varchar(20)?_, `description` _varchar(255)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code); (tenant_id,system_role)

### `cost_centers`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `code` _varchar(20)_, `name` _varchar(255)_, `business_line` _varchar(20)?_, `branch_id` → branches _bigint unsigned?_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,code)

### `posting_rules`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `event_type` _varchar(50)_, `line_no` _smallint unsigned_, `side` _varchar(6)_, `account_id` → accounts _bigint unsigned_, `amount_key` _varchar(40)_, `description` _varchar(255)?_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,event_type,line_no)

### `fiscal_years`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `name` _varchar(20)_, `start_date` _date_, `end_date` _date_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,name)

### `accounting_periods`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `fiscal_year_id` → fiscal_years _bigint unsigned_, `name` _varchar(20)_, `start_date` _date_, `end_date` _date_, `status` _varchar(20)_, `closed_by` → users _bigint unsigned?_, `closed_at` _timestamp?_, `reopened_by` → users _bigint unsigned?_, `reopened_at` _timestamp?_, `reopen_reason` _varchar(500)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,start_date)

### `journal_vouchers`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `voucher_no` _varchar(30)_, `voucher_date` _date_, `accounting_period_id` → accounting_periods _bigint unsigned?_, `origin` _varchar(10)_, `source_module` _varchar(20)?_, `event_type` _varchar(50)?_, `source_type` _varchar(255)?_, `source_id` _bigint unsigned?_, `narration` _varchar(1000)_, `status` _varchar(20)_, `total_debit` _decimal(18,2)_, `total_credit` _decimal(18,2)_, `branch_id` → branches _bigint unsigned?_, `reversal_of_id` → journal_vouchers _bigint unsigned?_, `idempotency_key` _varchar(120)?_, `created_by` → users _bigint unsigned?_, `submitted_at` _timestamp?_, `posted_by` → users _bigint unsigned?_, `posted_at` _timestamp?_, `rejection_reason` _varchar(500)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,idempotency_key); (tenant_id,voucher_no)

### `journal_lines`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `journal_voucher_id` → journal_vouchers _bigint unsigned_, `account_id` → accounts _bigint unsigned_, `debit` _decimal(18,2)_, `credit` _decimal(18,2)_, `description` _varchar(255)?_, `cost_center_id` → cost_centers _bigint unsigned?_, `branch_id` → branches _bigint unsigned?_, `customer_id` → customers _bigint unsigned?_, `vendor_id` → vendors _bigint unsigned?_, `fund_id` → funds _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `bank_accounts`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `name` _varchar(255)_, `bank_name` _varchar(255)_, `iban` _varchar(34)_, `purpose` _varchar(20)_, `provider` _varchar(40)?_, `account_id` → accounts _bigint unsigned_, `minimum_balance` _decimal(18,2)_, `is_simulated` _tinyint(1)_, `is_active` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `settlement_records`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `bank_account_id` → bank_accounts _bigint unsigned_, `provider` _varchar(40)_, `value_date` _date_, `provider_ref` _varchar(60)?_, `narration` _varchar(255)?_, `direction` _varchar(10)_, `amount` _decimal(18,2)_, `status` _varchar(20)_, `is_simulated` _tinyint(1)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `reconciliation_batches`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `bank_account_id` → bank_accounts _bigint unsigned_, `period_start` _date_, `period_end` _date_, `status` _varchar(20)_, `matched_count` _int unsigned_, `exception_count` _int unsigned_, `internal_total` _decimal(18,2)_, `external_total` _decimal(18,2)_, `difference` _decimal(18,2)_, `created_by` → users _bigint unsigned?_, `approved_by` → users _bigint unsigned?_, `approved_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,reference)

### `reconciliation_items`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reconciliation_batch_id` → reconciliation_batches _bigint unsigned_, `payment_order_id` → payment_orders _bigint unsigned?_, `settlement_record_id` → settlement_records _bigint unsigned?_, `internal_amount` _decimal(18,2)?_, `external_amount` _decimal(18,2)?_, `result` _varchar(30)_, `resolution` _varchar(30)?_, `resolved_by` → users _bigint unsigned?_, `notes` _varchar(1000)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `suspense_items`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `settlement_record_id` → settlement_records _bigint unsigned?_, `amount` _decimal(18,2)_, `reason` _varchar(500)_, `status` _varchar(20)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `reclass_journal_id` → journal_vouchers _bigint unsigned?_, `resolved_by` → users _bigint unsigned?_, `resolution_notes` _varchar(1000)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `vendor_bills`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `bill_no` _varchar(30)_, `vendor_id` → vendors _bigint unsigned_, `kind` _varchar(20)_, `loan_application_id` → loan_applications _bigint unsigned?_, `vendor_invoice_ref` _varchar(255)?_, `bill_date` _date_, `due_date` _date_, `amount` _decimal(18,2)_, `wht_amount` _decimal(18,2)_, `paid_amount` _decimal(18,2)_, `expense_account_id` → accounts _bigint unsigned?_, `cost_center_id` → cost_centers _bigint unsigned?_, `description` _varchar(1000)?_, `status` _varchar(20)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,bill_no)

### `vendor_payments`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `payment_no` _varchar(30)_, `vendor_id` → vendors _bigint unsigned_, `vendor_bill_id` → vendor_bills _bigint unsigned?_, `disbursement_id` → disbursements _bigint unsigned?_, `is_advance` _tinyint(1)_, `amount` _decimal(18,2)_, `wht_amount` _decimal(18,2)_, `net_amount` _decimal(18,2)_, `method` _varchar(20)_, `bank_account_id` → bank_accounts _bigint unsigned?_, `status` _varchar(20)_, `scenario` _varchar(40)?_, `payment_order_id` → payment_orders _bigint unsigned?_, `idempotency_key` _varchar(100)_, `purpose` _varchar(500)?_, `failure_reason` _varchar(500)?_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_by` → users _bigint unsigned?_, `paid_at` _timestamp?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,idempotency_key); (tenant_id,payment_no)

### `other_receivables`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `party_name` _varchar(255)_, `kind` _varchar(30)_, `amount` _decimal(18,2)_, `received_amount` _decimal(18,2)_, `due_date` _date_, `status` _varchar(20)_, `notes` _varchar(500)?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `loan_provisions`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `loan_account_id` → loan_accounts _bigint unsigned_, `as_of` _date_, `dpd` _smallint unsigned_, `classification` _varchar(20)_, `rate` _decimal(5,2)_, `outstanding` _decimal(18,2)_, `required_provision` _decimal(18,2)_, `movement` _decimal(18,2)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (loan_account_id,as_of)

### `write_offs`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `loan_account_id` → loan_accounts _bigint unsigned_, `amount` _decimal(18,2)_, `reason` _varchar(1000)_, `status` _varchar(20)_, `requested_by` → users _bigint unsigned?_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `fund_transfers`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `from_bank_account_id` → bank_accounts _bigint unsigned_, `to_bank_account_id` → bank_accounts _bigint unsigned_, `amount` _decimal(18,2)_, `purpose` _varchar(500)_, `status` _varchar(20)_, `requested_by` → users _bigint unsigned?_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `fixed_assets`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `asset_code` _varchar(30)_, `name` _varchar(255)_, `category` _varchar(30)_, `branch_id` → branches _bigint unsigned?_, `acquired_on` _date_, `cost` _decimal(18,2)_, `salvage_value` _decimal(18,2)_, `useful_life_months` _smallint unsigned_, `accumulated_depreciation` _decimal(18,2)_, `status` _varchar(20)_, `disposed_on` _date?_, `disposal_proceeds` _decimal(18,2)?_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,asset_code)

### `asset_depreciations`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `fixed_asset_id` → fixed_assets _bigint unsigned_, `accounting_period_id` → accounting_periods _bigint unsigned_, `amount` _decimal(18,2)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (fixed_asset_id,accounting_period_id)

### `payroll_runs`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `period` _varchar(7)_, `status` _varchar(20)_, `total_gross` _decimal(18,2)_, `total_tax` _decimal(18,2)_, `total_deductions` _decimal(18,2)_, `total_net` _decimal(18,2)_, `created_by` → users _bigint unsigned?_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,period)

### `payroll_lines`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `payroll_run_id` → payroll_runs _bigint unsigned_, `employee_id` → employees _bigint unsigned_, `basic` _decimal(18,2)_, `allowances` _decimal(18,2)_, `gross` _decimal(18,2)_, `income_tax` _decimal(18,2)_, `provident_fund` _decimal(18,2)_, `eobi` _decimal(18,2)_, `staff_loan_deduction` _decimal(18,2)_, `net` _decimal(18,2)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `expense_claims`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `kind` _varchar(20)_, `requested_by` → users _bigint unsigned_, `branch_id` → branches _bigint unsigned?_, `cost_center_id` → cost_centers _bigint unsigned?_, `expense_account_id` → accounts _bigint unsigned_, `vendor_id` → vendors _bigint unsigned?_, `expense_date` _date_, `amount` _decimal(18,2)_, `description` _varchar(1000)_, `status` _varchar(20)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

Unique: (tenant_id,reference)

### `budgets`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `fiscal_year_id` → fiscal_years _bigint unsigned_, `name` _varchar(255)_, `status` _varchar(20)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `budget_lines`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `budget_id` → budgets _bigint unsigned_, `account_id` → accounts _bigint unsigned_, `cost_center_id` → cost_centers _bigint unsigned?_, `accounting_period_id` → accounting_periods _bigint unsigned_, `amount` _decimal(18,2)_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `profit_distributions`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `fund_id` → funds _bigint unsigned_, `accounting_period_id` → accounting_periods _bigint unsigned_, `gross_profit` _decimal(18,2)_, `investor_share_pct` _decimal(5,2)_, `investor_share` _decimal(18,2)_, `wht_amount` _decimal(18,2)_, `status` _varchar(20)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

### `charity_disbursements`

`id` _bigint unsigned_, `tenant_id` → tenants _bigint unsigned_, `reference` _varchar(30)_, `beneficiary` _varchar(255)_, `amount` _decimal(18,2)_, `purpose` _varchar(500)_, `status` _varchar(20)_, `journal_voucher_id` → journal_vouchers _bigint unsigned?_, `requested_by` → users _bigint unsigned?_, `created_at` _timestamp?_, `updated_at` _timestamp?_

