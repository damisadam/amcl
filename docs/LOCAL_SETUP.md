# Local setup

Requirements: PHP 8.2+ (ext: pdo_mysql, redis or predis, bcmath, intl, gd, pcntl), Composer 2, Node 20+, MySQL 8, Redis 6+.

```bash
cp .env.example .env            # set DB_*, REDIS_*, REVERB_*; keep AMCL_DEMO_MODE=true for demos
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan amcl:demo-reset --force   # builds platform users + 5 demo tenants (≈ 6–8 minutes), writes docs/DEMO_CREDENTIALS.md

# run (separate terminals)
php artisan serve                      # http://localhost:8000
php artisan horizon                    # queues: default, callbacks, broadcasts (Horizon UI: /horizon, platform admins only)
php artisan reverb:start               # real-time (optional – screens poll if it is down)
php artisan schedule:work              # daily servicing, month-end
```

Key `.env` values: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `BROADCAST_CONNECTION=reverb`, `SESSION_DRIVER=database`,
`APP_TIMEZONE=Asia/Karachi`, `AMCL_DEMO_MODE=true` (false in production), `DOCUMENTS_DISK=private`.

Entry points: customer portal `/` (choose institution) or `/t/amcl`; staff & vendors `/login?org=amcl`; platform
`/platform/login`. Credentials: `docs/DEMO_CREDENTIALS.md` (password `Demo@12345`, wallet PIN `4826`).

Useful commands: `php artisan amcl:daily-servicing [--tenant=amcl]`, `php artisan amcl:month-end`,
`php artisan queue:failed`, `php artisan horizon:status`.
