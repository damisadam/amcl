<?php
// Regenerates docs/ROLES_AND_PERMISSIONS.md and the posting-rule tables in docs/ACCOUNTING_RULES.md from config.
// Usage: php scripts/generate_docs.php
$base = dirname(__DIR__);
$perms = require "$base/config/permissions.php";
$roles = require "$base/config/roles.php";
$coa = require "$base/config/chart_of_accounts.php";
$rules = require "$base/config/posting_rules.php";
$match = function (string $key, array $patterns): bool {
    foreach ($patterns as $p) {
        if (fnmatch($p, $key)) return true;
    }
    return false;
};

$md = "# Roles and permissions\n\n_Generated from `config/permissions.php` and `config/roles.php` by `php scripts/generate_docs.php`._\n\n"
    ."Roles are **templates** copied into each tenant at provisioning; tenant admins can then edit permissions, create\n"
    ."custom roles and assign several roles to one user (Admin › Roles & permissions). Permissions marked 🔒 are sensitive.\n"
    ."Customers and vendor users are user *types* with fixed, record-scoped capabilities (own records only).\n\n";
$md .= "## Role descriptions\n\n| Role | Key | Purpose |\n|---|---|---|\n";
foreach ($roles['tenant'] as $k => $r) $md .= "| {$r['name']} | `$k` | {$r['description']} |\n";
foreach ($roles['platform'] as $k => $r) $md .= "| {$r['name']} (platform) | `$k` | {$r['description']} |\n";
$keys = array_keys($roles['tenant']);
$abbr = ['tenant_admin' => 'ADM', 'loan_officer' => 'LO', 'branch_manager' => 'BM', 'cro' => 'CRO', 'manager_operations' => 'MO', 'compliance_officer' => 'CMP',
    'risk_officer' => 'RSK', 'disbursement_approver' => 'DA', 'credit_analyst' => 'CA', 'approval_committee' => 'AC', 'visit_officer' => 'VO', 'recovery_agent' => 'RA',
    'audit_officer' => 'AUD', 'finance_officer' => 'FO', 'accountant' => 'ACC', 'customer_support' => 'CS'];
$md .= "\n## Permission matrix (default templates)\n\n".implode(' · ', array_map(fn ($k) => "**{$abbr[$k]}** = ".$roles['tenant'][$k]['name'], $keys))."\n\n";
foreach ($perms['tenant'] as $module => $list) {
    $md .= "### $module\n\n| Permission | ".implode(' | ', array_map(fn ($k) => $abbr[$k], $keys))." |\n|---|".str_repeat('---|', count($keys))."\n";
    foreach ($list as $key => $label) {
        $clean = rtrim($key, '*');
        $row = "| `{$clean}`".(str_ends_with($key, '*') ? ' 🔒' : '')."<br><small>$label</small>";
        foreach ($keys as $rk) $row .= ' | '.($match($clean, $roles['tenant'][$rk]['permissions']) ? '✓' : '');
        $md .= $row." |\n";
    }
    $md .= "\n";
}
$md .= "## Platform permissions\n\n| Permission | Platform Admin | Platform Support |\n|---|---|---|\n";
foreach ($perms['platform'] as $key => $label) {
    $md .= "| `$key`<br><small>$label</small> | ".($match($key, $roles['platform']['platform_admin']['permissions']) ? '✓' : '').' | '.($match($key, $roles['platform']['platform_support']['permissions']) ? '✓' : '')." |\n";
}
file_put_contents("$base/docs/ROLES_AND_PERMISSIONS.md", $md);

$names = [];
foreach ($coa as $a) if ($a[5]) $names[$a[5]] = "{$a[0]} {$a[1]}";
$t = "| Event | Side | Account (role → default code) | Amount key |\n|---|---|---|---|\n";
foreach ($rules as $event => $lines) foreach ($lines as $i => [$side, $role, $key]) $t .= '| '.($i === 0 ? "`$event`" : '')." | $side | `$role` → {$names[$role]} | `$key` |\n";
$c = "| Code | Name | Type | Level | Parent | System role |\n|---|---|---|---|---|---|\n";
foreach ($coa as [$code, $name, $type, $level, $parent, $role]) $c .= "| $code | $name | $type | $level | ".($parent ?? '')." | ".($role ? "`$role`" : '')." |\n";
$doc = file_get_contents("$base/docs/ACCOUNTING_RULES.md");
$doc = preg_replace('/<!-- RULES -->.*<!-- \/RULES -->/s', "<!-- RULES -->\n$t<!-- /RULES -->", $doc);
$doc = preg_replace('/<!-- COA -->.*<!-- \/COA -->/s', "<!-- COA -->\n$c<!-- /COA -->", $doc);
file_put_contents("$base/docs/ACCOUNTING_RULES.md", $doc);
echo "docs generated\n";
