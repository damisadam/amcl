// UI redesign screenshot harness (local/demo only). Logs in through the real UI as demo users and
// captures each screen at desktop + mobile width into storage/app/ui-screens/<portal>/.
//
//   npm i --no-save playwright-core     (uses the system Chrome; nothing is downloaded)
//   (or point PLAYWRIGHT=/path/to/node_modules/playwright-core/index.mjs at an install elsewhere)
//   node scripts/ui_screens.mjs [portal...] [--only=substr] [--ur]
//
// Portals: public, ops, customer, vendor, platform. --ur also captures customer/public pages in Urdu.
// See docs/ui-redesign-inventory.md (Verification).
import { mkdirSync } from 'node:fs';

const BASE = process.env.APP_URL || 'http://localhost:8000';
const OUT = new URL('../storage/app/ui-screens/', import.meta.url).pathname;
const PASSWORD = 'Demo@12345';
const args = process.argv.slice(2);
const only = (args.find(a => a.startsWith('--only=')) || '').slice(7);
const urdu = args.includes('--ur');
const portals = args.filter(a => !a.startsWith('--'));

const SCREENS = {
    public: { login: null, paths: ['/', '/t/amcl', '/t/amcl/login', '/t/amcl/register', '/t/amcl/forgot-password', '/t/amcl/vendor-request', '/login', '/platform/login', '/does-not-exist'] },
    ops: {
        login: { kind: 'staff', email: 'admin@amcl.demo' },
        paths: ['/ops/dashboard', '/ops/customers', '/ops/customers/create', '/ops/customers/1', '/ops/kyc', '/ops/leads', '/ops/complaints', '/ops/complaints/1',
            '/ops/applications', '/ops/applications/create', '/ops/applications/1', '/ops/approvals', '/ops/field/visits', '/ops/field/visits/1', '/ops/disbursements',
            '/ops/loans', '/ops/loans/1', '/ops/loans/transfer', '/ops/collections', '/ops/recovery', '/ops/recovery/1', '/ops/products', '/ops/products/create', '/ops/products/1',
            '/ops/vendors', '/ops/vendors/create', '/ops/vendors/1', '/ops/staff-lending', '/ops/staff-lending/1',
            '/ops/wallets', '/ops/wallets/1', '/ops/wallet-transactions', '/ops/payments', '/ops/payments/1',
            '/ops/finance', '/ops/finance/journals', '/ops/finance/journals/create', '/ops/finance/journals/1', '/ops/finance/accounts', '/ops/finance/accounts/1/ledger',
            '/ops/finance/posting-rules', '/ops/finance/periods', '/ops/finance/treasury', '/ops/finance/reconciliation', '/ops/finance/reconciliation/1', '/ops/finance/payables',
            '/ops/finance/receivables', '/ops/finance/funds', '/ops/finance/provisioning', '/ops/finance/assets', '/ops/finance/payroll', '/ops/finance/expenses',
            '/ops/finance/budgets', '/ops/finance/islamic', '/ops/finance/tax',
            '/ops/reports/lending', '/ops/reports/wallet', '/ops/reports/financial', '/ops/reports/regulatory',
            '/ops/admin/users', '/ops/admin/roles', '/ops/admin/organization', '/ops/admin/configuration', '/ops/admin/workflows', '/ops/admin/integrations',
            '/ops/admin/content', '/ops/admin/audit', '/account/password'],
    },
    customer: {
        login: { kind: 'customer', email: 'customer21@amcl.demo' },
        paths: ['/app', '/app/products', '/app/applications', '/app/financing', '/app/wallet', '/app/wallet/top-up', '/app/wallet/transfer', '/app/wallet/withdraw',
            '/app/wallet/bills', '/app/wallet/merchant', '/app/wallet/settings', '/app/pay', '/app/transactions', '/app/documents', '/app/notifications', '/app/support', '/app/profile'],
    },
    vendor: {
        login: { kind: 'staff', email: 'vendor@amcl.demo' },
        paths: ['/vendor', '/vendor/orders', '/vendor/originate', '/vendor/payments', '/vendor/items', '/vendor/merchant', '/vendor/documents', '/vendor/profile'],
    },
    platform: {
        login: { kind: 'platform', email: 'admin@platform.demo' },
        paths: ['/platform', '/platform/tenants', '/platform/tenants/create', '/platform/tenants/1', '/platform/health', '/platform/queues', '/platform/audit',
            '/platform/support-access', '/platform/settings', '/platform/demo'],
    },
};

async function login(page, l) {
    if (!l) return;
    const url = { staff: '/login?org=amcl', customer: '/t/amcl/login', platform: '/platform/login' }[l.kind];
    await page.goto(BASE + url);
    if (l.kind === 'staff') {
        await page.fill('#org', 'amcl');
        await page.fill('#email', l.email);
    } else if (l.kind === 'customer') {
        await page.fill('#login', l.email);
    } else {
        await page.fill('#email', l.email);
    }
    await page.fill('#password', PASSWORD);
    await Promise.all([page.waitForURL(u => !u.pathname.endsWith('/login'), { timeout: 15000 }), page.click('form button[type=submit], form button:not([type])')]);
    // Demo TOTP codes are single-use per 30 s window (replay protection), so retry with the next code if needed.
    for (let attempt = 0; attempt < 3 && page.url().includes('/account/mfa'); attempt++) {
        if (attempt) { await page.waitForTimeout(31000); await page.reload(); }
        const code = (await page.locator('[role=alert] strong').first().textContent()).trim();
        await page.fill('#code', code);
        await Promise.all([page.waitForLoadState('networkidle'), page.click('form[action*="mfa"] button')]);
    }
}

async function shoot(page, portal, path, tag) {
    const name = (path.replace(/^\//, '').replace(/[/?=&]/g, '_') || 'root') + tag;
    const resp = await page.goto(BASE + path, { waitUntil: 'networkidle' }).catch(e => ({ status: () => 'ERR ' + e.message }));
    // Flag pages wider than the viewport (horizontal page scroll is a layout bug; tables scroll inside their card).
    const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth).catch(() => 0);
    await page.screenshot({ path: `${OUT}${portal}/${name}.png`, fullPage: true });
    console.log(`${portal}\t${resp.status()}\t${path}${tag}${over > 1 ? `\tOVERFLOW +${over}px` : ''}`);
}

const { chromium } = await import(process.env.PLAYWRIGHT || 'playwright-core');
const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome' });
for (const portal of portals.length ? portals : Object.keys(SCREENS)) {
    const def = SCREENS[portal];
    mkdirSync(OUT + portal, { recursive: true });
    // One login per portal (TOTP codes are single-use), then re-shoot at each width.
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page, def.login);
    for (const [vw, tag] of [[1440, ''], [390, '@m']]) {
        await page.setViewportSize({ width: vw, height: 900 });
        for (const p of def.paths.filter(p => !only || p.includes(only))) await shoot(page, portal, p, tag);
        if (urdu && ['customer', 'public'].includes(portal)) {
            await page.goto(BASE + def.paths[0]);
            await page.locator('form[action*="/locale/ur"] button:visible').first().click().catch(() => {});
            await page.waitForLoadState('networkidle');
            for (const p of def.paths.filter(p => !only || p.includes(only))) await shoot(page, portal, p, tag + '@ur');
            await page.locator('form[action*="/locale/en"] button:visible').first().click().catch(() => {});
            await page.waitForLoadState('networkidle');
        }
    }
    await ctx.close();
}
await browser.close();
