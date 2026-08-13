# Website Estate Dashboard v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a staff-only, read-only Laravel dashboard that automatically discovers all primary/addon/subdomains across three WHM/cPanel servers, monitors estate health, records history, and surfaces actionable issues.

**Architecture:** Use a Laravel 13 monolith with the official Livewire starter stack for the UI. WHM/cPanel integration sits behind a dedicated client interface; discovery and monitoring are queued, idempotent jobs; Eloquent stores inventory, history, sync runs, and issues. Development runs entirely in DDEV inside WSL, with database-backed queue/cache/session so production can later evolve toward multiple application nodes without rewriting business logic.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4, Flux UI, Tailwind CSS 4, MariaDB/MySQL driver, Laravel HTTP client, database queue/cache/session, PHPUnit, DDEV, WSL, Git.

## Global Constraints

- Application is 100% read-only against WHM/cPanel and hosted websites.
- No public registration.
- Authentication is email/password with password reset.
- Roles are exactly `admin` and `staff`.
- All authenticated users can see the entire estate.
- Admin permissions apply only to dashboard configuration and users.
- v1 supports three WHM/cPanel servers but does not hard-code a maximum.
- Inventory includes primary, addon, subdomain, and alias/parked domains.
- WordPress/plugin/theme intelligence is phase 2 and must not enter v1.
- Do not add Redis, microservices, agents on remote servers, GA4, Search Console, Lighthouse, site crawling, or automatic remediation.
- Keep detailed monitoring checks for 90 days.
- Use HTTPS and WHM API tokens; never store WHM passwords.
- Verify remote TLS certificates; do not add an “ignore TLS errors” production switch.
- Automated tests must not make real WHM, DNS, TLS, or public HTTP requests.
- Use TDD: failing test → minimal implementation → passing test → commit.
- Prefer small focused services/data objects over large “manager” classes.
- Use `ddev exec ...` for PHP/Artisan/npm commands so tests run in the same environment as development.

---

## Repository target paths

Once the repository exists, keep the approved design and this plan at:

```text
docs/superpowers/specs/2026-08-13-website-estate-dashboard-v1-design.md
docs/superpowers/plans/2026-08-13-website-estate-dashboard-v1.md
```

## Planned file structure

```text
app/
├── Console/Commands/
│   └── CreateAdminUser.php
├── Data/
│   ├── Monitoring/
│   │   ├── DnsResult.php
│   │   ├── HttpResult.php
│   │   └── TlsResult.php
│   └── Whm/
│       ├── WhmAccountData.php
│       ├── WhmDomainData.php
│       ├── WhmDomainInventory.php
│       └── WhmServerHealthData.php
├── Enums/
│   ├── DomainClassification.php
│   ├── DomainClassificationSource.php
│   ├── DomainType.php
│   ├── IssueSeverity.php
│   ├── SyncRunStatus.php
│   ├── SyncRunType.php
│   └── UserRole.php
├── Jobs/
│   ├── Inventory/
│   │   ├── SyncServerInventory.php
│   │   └── SyncCpanelAccountDomains.php
│   └── Monitoring/
│       ├── CheckDomainDns.php
│       ├── CheckDomainHttp.php
│       ├── CheckDomainTls.php
│       └── CheckServerHealth.php
├── Livewire/
│   ├── Accounts/
│   │   ├── Index.php
│   │   └── Show.php
│   ├── Admin/
│   │   ├── Servers/Form.php
│   │   └── Users/Index.php
│   ├── Dashboard.php
│   ├── Domains/
│   │   ├── Index.php
│   │   └── Show.php
│   ├── Issues/Index.php
│   └── Servers/
│       ├── Index.php
│       └── Show.php
├── Models/
│   ├── CpanelAccount.php
│   ├── Domain.php
│   ├── DomainCheck.php
│   ├── Issue.php
│   ├── Server.php
│   ├── ServerCheck.php
│   ├── SyncRun.php
│   └── User.php
├── Services/
│   ├── Inventory/
│   │   ├── DomainClassifier.php
│   │   └── InventoryReconciler.php
│   ├── Monitoring/
│   │   ├── Contracts/DnsResolver.php
│   │   ├── Contracts/TlsInspector.php
│   │   ├── NativeDnsResolver.php
│   │   ├── NativeTlsInspector.php
│   │   └── IssueEvaluator.php
│   └── Whm/
│       ├── Contracts/WhmClient.php
│       ├── Exceptions/WhmApiException.php
│       └── HttpWhmClient.php
├── Support/
│   └── MonitoringThresholds.php
database/
├── factories/
├── migrations/
└── seeders/
config/
└── estate.php
resources/views/
├── livewire/...
└── components/estate/...
routes/
├── console.php
└── web.php
tests/
├── Feature/
│   ├── Admin/
│   ├── Inventory/
│   ├── Monitoring/
│   └── Ui/
└── Unit/
    ├── Inventory/
    ├── Monitoring/
    └── Whm/
```

---

### Task 1: Bootstrap the DDEV/Laravel project and lock development conventions

**Files:**
- Create: `.ddev/config.yaml`
- Create/modify through Laravel installer: `composer.json`, `package.json`, application skeleton
- Modify: `.env.example`
- Modify: `phpunit.xml`
- Create: `docs/superpowers/specs/2026-08-13-website-estate-dashboard-v1-design.md`
- Create: `docs/superpowers/plans/2026-08-13-website-estate-dashboard-v1.md`

**Interfaces:**
- Produces: Laravel 13 application on PHP 8.4 with Livewire starter kit, built-in Laravel authentication, PHPUnit, MariaDB, database queue/cache/session.
- Consumes: none.

- [ ] **Step 1: Create the repository and initial branch**

```bash
mkdir website-estate-dashboard
cd website-estate-dashboard
git init
git checkout -b main
```

Expected: empty Git repository on `main`.

- [ ] **Step 2: Configure DDEV for Laravel/PHP 8.4**

```bash
ddev config --project-type=laravel --docroot=public --php-version=8.4
ddev start
```

Expected: DDEV web/database containers start successfully.

- [ ] **Step 3: Generate Laravel 13 using the official Livewire starter kit**

Use the Laravel installer inside the DDEV web container. Select:

```text
Framework: Laravel 13
Starter kit: Livewire
Authentication: Built-in Laravel authentication
Teams: No
Testing: PHPUnit
Database: MySQL
Run npm install/build: Yes
```

If the container does not have the Laravel installer, add it temporarily inside the container rather than installing PHP tooling on Windows.

Expected after generation:

```bash
ddev exec php artisan --version
```

Expected prefix: `Laravel Framework 13.`

- [ ] **Step 4: Configure database-backed runtime state**

Set:

```dotenv
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

Create missing framework tables if the starter did not already create them:

```bash
ddev exec php artisan make:queue-table
ddev exec php artisan make:cache-table
ddev exec php artisan make:session-table
ddev exec php artisan migrate
```

Do not generate duplicate migrations if tables/migrations already exist.

- [ ] **Step 5: Configure `.env.example` without credentials**

Ensure it documents:

```dotenv
APP_NAME="Website Estate Dashboard"
APP_TIMEZONE=Europe/London
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
ESTATE_HTTP_TIMEOUT=10
ESTATE_HTTP_SLOW_MS=2000
ESTATE_SSL_WARNING_DAYS=30
ESTATE_SSL_CRITICAL_DAYS=7
ESTATE_DISK_WARNING_PERCENT=85
ESTATE_DISK_CRITICAL_PERCENT=95
ESTATE_CHECK_RETENTION_DAYS=90
```

Never add real WHM hostnames/tokens to `.env.example`.

- [ ] **Step 6: Add a smoke test before application work**

Create `tests/Feature/SmokeTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertOk();
    }
}
```

Run:

```bash
ddev exec php artisan test tests/Feature/SmokeTest.php
```

Expected: PASS.

- [ ] **Step 7: Build frontend and run full baseline**

```bash
ddev exec npm run build
ddev exec php artisan test
ddev exec ./vendor/bin/pint --test
```

Expected: all commands pass.

- [ ] **Step 8: Commit baseline**

```bash
git add .
git commit -m "chore: bootstrap estate dashboard"
```

---

### Task 2: Lock down authentication and implement dashboard roles/users

**Files:**
- Create: `app/Enums/UserRole.php`
- Modify: `app/Models/User.php`
- Modify: `config/fortify.php`
- Create: `app/Console/Commands/CreateAdminUser.php`
- Create: `app/Listeners/RecordSuccessfulLogin.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `app/Livewire/Admin/Users/Index.php`
- Create: `resources/views/livewire/admin/users/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auth/InternalAuthenticationTest.php`
- Test: `tests/Feature/Admin/UserManagementTest.php`

**Interfaces:**
- Produces: `UserRole::{Admin, Staff}`, `User::isAdmin(): bool`, admin Gate named `admin`.
- Produces command: `php artisan estate:create-admin`.
- Later tasks rely on `auth` and `can:admin`.

- [ ] **Step 1: Write failing role/auth tests**

Cover:

```php
public_registration_is_disabled
guest_cannot_view_dashboard
staff_can_view_dashboard
staff_cannot_view_admin_user_page
admin_can_view_admin_user_page
```

Also assert a successful login updates `last_login_at`.

Run:

```bash
ddev exec php artisan test tests/Feature/Auth/InternalAuthenticationTest.php tests/Feature/Admin/UserManagementTest.php
```

Expected: FAIL because role/route behavior is not implemented.

- [ ] **Step 2: Add `UserRole` enum and user columns**

Enum:

```php
enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
}
```

Migration adds:

```php
$table->string('role')->default('staff')->index();
$table->timestamp('last_login_at')->nullable();
$table->boolean('enabled')->default(true)->index();
```

Cast `role` to `UserRole` and `last_login_at` to datetime.

- [ ] **Step 3: Disable public registration and unnecessary v1 auth features**

In Fortify features:

- Keep password reset.
- Remove registration.
- Remove two-factor authentication for v1.
- Do not require public email verification for admin-created users.

Assert `/register` returns 404.

- [ ] **Step 4: Add admin authorization**

Define:

```php
Gate::define('admin', fn (User $user) =>
    $user->enabled && $user->role === UserRole::Admin
);
```

Add middleware that prevents disabled users from continuing authenticated requests, or enforce enabled status during authentication.

- [ ] **Step 5: Implement initial admin command**

`estate:create-admin` must:

1. Prompt interactively for name.
2. Prompt for email.
3. Prompt secretly for password and confirmation.
4. Require a strong password using Laravel password rules.
5. Create/update that email as enabled admin.

Do not accept password as a CLI argument.

Test command behavior with Laravel console tests.

- [ ] **Step 6: Implement admin user management**

Admin page supports:

- List users.
- Create staff/admin user with name/email/role.
- On creation, generate an unguessable random password and send a password-reset link.
- Change role.
- Enable/disable user.
- Prevent an admin from disabling their own current account.
- Never display/set another user’s password.

Use `Password::sendResetLink()` after creating a user.

- [ ] **Step 7: Record successful logins**

Listen to Laravel `Login` event and update `last_login_at`.

- [ ] **Step 8: Run tests**

```bash
ddev exec php artisan test tests/Feature/Auth tests/Feature/Admin/UserManagementTest.php
ddev exec ./vendor/bin/pint --test
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app config database resources routes tests
git commit -m "feat: add internal staff authentication"
```

---

### Task 3: Create the estate domain model, enums, migrations, factories, and relationships

**Files:**
- Create enums listed in planned structure
- Create models: `Server`, `CpanelAccount`, `Domain`, `DomainCheck`, `ServerCheck`, `SyncRun`, `Issue`
- Create migrations for all seven tables
- Create factories for all seven models
- Test: `tests/Feature/Database/EstateModelTest.php`

**Interfaces:**
- Produces Eloquent relationships and enum casts used by every later task.
- Unique identities:
  - server hostname unique
  - cPanel account `(server_id, username)`
  - domain `(cpanel_account_id, domain)`

- [ ] **Step 1: Write failing schema/relationship tests**

Assert:

```text
Server hasMany CpanelAccount
Server hasMany ServerCheck
Server hasMany SyncRun
CpanelAccount belongsTo Server
CpanelAccount hasMany Domain
Domain belongsTo CpanelAccount
Domain belongsTo optional parent Domain
Domain hasMany DomainCheck
Domain hasMany Issue
```

Also assert duplicate account/domain identities are rejected by database constraints.

- [ ] **Step 2: Implement enums**

Exact enum values:

```text
DomainType: primary, addon, subdomain, alias
DomainClassification: website, development, alias, service, unknown, ignored
DomainClassificationSource: auto, manual
IssueSeverity: warning, critical
SyncRunStatus: running, successful, partial, failed
SyncRunType: inventory
```

- [ ] **Step 3: Implement migrations**

Use indexes for frequent filters:

```text
servers: enabled
cpanel_accounts: server_id + removed_at, suspended
domains: cpanel_account_id + removed_at, monitoring_enabled, classification
domain_checks: domain_id + check_type + checked_at
server_checks: server_id + checked_at
sync_runs: server_id + started_at, status
issues: resolved_at + severity, domain_id, server_id, cpanel_account_id
```

Store byte counts as unsigned big integers nullable.

- [ ] **Step 4: Protect WHM token**

In `Server`:

```php
protected $hidden = ['api_token'];

protected function casts(): array
{
    return [
        'api_token' => 'encrypted',
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
    ];
}
```

Never log `$server->api_token`.

- [ ] **Step 5: Implement factories and relationships**

Factories should be deterministic enough for tests and never contain a real WHM token.

- [ ] **Step 6: Run migration/model tests**

```bash
ddev exec php artisan migrate:fresh
ddev exec php artisan test tests/Feature/Database/EstateModelTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Enums app/Models database tests/Feature/Database
git commit -m "feat: add estate data model"
```

---

### Task 4: Add estate configuration and monitoring thresholds

**Files:**
- Create: `config/estate.php`
- Create: `app/Support/MonitoringThresholds.php`
- Test: `tests/Unit/Monitoring/MonitoringThresholdsTest.php`

**Interfaces:**
- Produces immutable threshold accessors used by monitoring/issue evaluation.

- [ ] **Step 1: Write failing threshold tests**

Assert default values:

```text
HTTP timeout: 10 seconds
Slow HTTP: 2000 ms
SSL warning: 30 days
SSL critical: 7 days
Disk warning: 85%
Disk critical: 95%
Check retention: 90 days
HTTP failure debounce: 2 checks
HTTP recovery debounce: 2 checks
Slow response debounce: 3 checks
DNS failure debounce: 2 checks
Server failure debounce: 2 checks
```

- [ ] **Step 2: Implement `config/estate.php`**

All numeric values read from environment but have the exact defaults above.

- [ ] **Step 3: Implement `MonitoringThresholds`**

Expose typed methods, e.g.:

```php
public function httpTimeoutSeconds(): int;
public function slowHttpMilliseconds(): int;
public function sslWarningDays(): int;
public function sslCriticalDays(): int;
public function diskWarningPercent(): int;
public function diskCriticalPercent(): int;
public function retentionDays(): int;
```

- [ ] **Step 4: Run tests and commit**

```bash
ddev exec php artisan test tests/Unit/Monitoring/MonitoringThresholdsTest.php
git add config app/Support tests/Unit/Monitoring
git commit -m "feat: configure estate monitoring thresholds"
```

---

### Task 5: Build the WHM API client boundary

**Files:**
- Create: `app/Services/Whm/Contracts/WhmClient.php`
- Create: `app/Services/Whm/HttpWhmClient.php`
- Create: `app/Services/Whm/Exceptions/WhmApiException.php`
- Create DTOs under `app/Data/Whm/`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Whm/HttpWhmClientTest.php`

**Interfaces:**
- Produces:

```php
interface WhmClient
{
    public function listAccounts(Server $server): array;
    public function listDomains(Server $server, string $username): WhmDomainInventory;
    public function getServerHealth(Server $server): WhmServerHealthData;
    public function getAccountDiskUsage(Server $server): array;
    public function testConnection(Server $server): void;
}
```

- `listAccounts()` returns `list<WhmAccountData>`.
- `getAccountDiskUsage()` returns `array<string, array{used_bytes:?int, limit_bytes:?int}>` keyed by cPanel username.

- [ ] **Step 1: Write failing HTTP-client tests using `Http::fake()`**

Verify every request:

```text
scheme: https
port: server.whm_port (default 2087)
path: /json-api/{function}
query includes api.version=1
Authorization header: whm {api_username}:{token}
Accept: application/json
TLS verification remains enabled
```

Cover WHM metadata `result !== 1`, non-2xx response, timeout, malformed JSON.

- [ ] **Step 2: Implement a single low-level `call()` method**

Conceptual signature:

```php
private function call(Server $server, string $function, array $query = []): array
```

Requirements:

- Laravel HTTP client.
- `connectTimeout(5)`.
- timeout from estate config.
- small retry for transient connection errors only.
- `throw()` HTTP failures.
- validate WHM response metadata.
- throw `WhmApiException` with sanitized context.
- never include API token in exception/log strings.

- [ ] **Step 3: Implement account inventory calls**

`listAccounts()` calls:

```text
WHM API 1: listaccts
```

Normalize fields into `WhmAccountData` rather than letting WHM response arrays leak into application services.

- [ ] **Step 4: Implement cPanel domain inventory through `uapi_cpanel`**

Use:

```text
WHM API 1: uapi_cpanel
cpanel.module=DomainInfo
cpanel.function=list_domains
cpanel.user={username}
```

Then fetch detailed domain data using:

```text
cpanel.module=DomainInfo
cpanel.function=domains_data
```

Return a single `WhmDomainInventory` combining:

- authoritative domain lists
- detailed document-root/type metadata where available

A missing detailed record for an alias must not make the alias disappear from inventory.

- [ ] **Step 5: Implement server/account health calls**

Use read-only calls:

```text
systemloadavg
getdiskusage
get_disk_usage
```

Normalize into DTOs.

- [ ] **Step 6: Bind interface**

```php
$this->app->bind(WhmClient::class, HttpWhmClient::class);
```

Tests may substitute a fake implementation.

- [ ] **Step 7: Run tests and commit**

```bash
ddev exec php artisan test tests/Unit/Whm
ddev exec ./vendor/bin/pint --test
git add app/Services/Whm app/Data/Whm app/Providers tests/Unit/Whm
git commit -m "feat: add read-only WHM API client"
```

---

### Task 6: Build admin WHM server management and connection testing

**Files:**
- Create: `app/Livewire/Admin/Servers/Form.php`
- Create: `resources/views/livewire/admin/servers/form.blade.php`
- Modify: `app/Livewire/Servers/Index.php`
- Modify: `resources/views/livewire/servers/index.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/ServerManagementTest.php`

**Interfaces:**
- Consumes `WhmClient::testConnection()`.
- Produces persisted `Server` records.

- [ ] **Step 1: Write failing authorization/validation tests**

Cover:

- staff cannot create/edit servers
- admin can
- hostname required and unique
- port defaults 2087
- API username required
- token required on create
- token optional on edit and unchanged if left blank
- token is never returned in rendered component state
- connection test failure does not save a new server unless explicitly saved

- [ ] **Step 2: Implement admin server form**

Fields:

```text
Name
Hostname
WHM port (2087 default)
API username
API token
Enabled
```

Provide `Test connection` action.

Display sanitized success/error.

- [ ] **Step 3: Add read-only server index for all staff**

Show:

- name
- hostname
- enabled
- last successful sync
- latest health state
- active account count
- active monitored domain count

Admin gets edit link; staff does not.

- [ ] **Step 4: Run tests and commit**

```bash
ddev exec php artisan test tests/Feature/Admin/ServerManagementTest.php
git add app/Livewire resources/views/livewire routes tests/Feature/Admin
git commit -m "feat: manage WHM server connections"
```

---

### Task 7: Implement automatic domain classification

**Files:**
- Create: `app/Services/Inventory/DomainClassifier.php`
- Test: `tests/Unit/Inventory/DomainClassifierTest.php`

**Interfaces:**
- Produces:

```php
public function classify(DomainType $type, string $domain): DomainClassification;
```

- Automatic sync may apply classification only when `classification_source === auto`.

- [ ] **Step 1: Write table-driven failing tests**

Expected mapping:

```text
primary example.co.uk → website
addon other.co.uk → website
alias example.com → alias
subdomain staging.example.co.uk → development
subdomain dev.example.co.uk → development
subdomain uat.example.co.uk → development
subdomain mail.example.co.uk → service
subdomain webmail.example.co.uk → service
subdomain cpanel.example.co.uk → service
subdomain shop.example.co.uk → unknown
subdomain booking.example.co.uk → unknown
```

Match the first hostname label case-insensitively.

- [ ] **Step 2: Implement exact prefix rules**

Development:

```php
['dev', 'development', 'staging', 'stage', 'test', 'testing', 'new', 'uat']
```

Service:

```php
['mail', 'webmail', 'cpanel', 'webdisk']
```

- [ ] **Step 3: Run tests and commit**

```bash
ddev exec php artisan test tests/Unit/Inventory/DomainClassifierTest.php
git add app/Services/Inventory tests/Unit/Inventory
git commit -m "feat: classify discovered domains"
```

---

### Task 8: Implement server inventory synchronisation and safe reconciliation

**Files:**
- Create: `app/Services/Inventory/InventoryReconciler.php`
- Create: `app/Jobs/Inventory/SyncServerInventory.php`
- Create: `app/Jobs/Inventory/SyncCpanelAccountDomains.php`
- Test: `tests/Feature/Inventory/SyncServerInventoryTest.php`
- Test: `tests/Feature/Inventory/SyncCpanelAccountDomainsTest.php`

**Interfaces:**
- Consumes `WhmClient`.
- Produces/upserts `CpanelAccount`, `Domain`, `SyncRun`.
- Dispatch entrypoint: `SyncServerInventory::dispatch($serverId)`.

- [ ] **Step 1: Write failing happy-path account sync test**

Fake WHM returns two accounts.

Assert:

- `SyncRun` starts then becomes `successful`.
- Accounts are upserted by server+username.
- `discovered_at` set only on first sighting.
- `last_seen_at` updated on each successful sync.
- suspension/package/home/owner values update.
- disk usage enrichment updates byte fields.
- child domain jobs are dispatched.

Use `Queue::fake()`.

- [ ] **Step 2: Implement account reconciliation**

`InventoryReconciler` should have small operations:

```php
public function upsertAccount(Server $server, WhmAccountData $data, CarbonInterface $seenAt): CpanelAccount;
public function markMissingAccountsRemoved(Server $server, array $seenUsernames, CarbonInterface $removedAt): int;
public function upsertDomain(CpanelAccount $account, WhmDomainData $data, CarbonInterface $seenAt): Domain;
public function markMissingDomainsRemoved(CpanelAccount $account, array $seenDomains, CarbonInterface $removedAt): int;
```

Use database transactions around each reconciliation boundary.

- [ ] **Step 3: Write failing server-failure safety test**

When `listAccounts()` throws:

- SyncRun = `failed`
- existing accounts keep `removed_at = null`
- existing domains keep `removed_at = null`
- server `last_successful_sync_at` is not changed
- no child jobs dispatched

- [ ] **Step 4: Write failing domain happy-path test**

Fake domain inventory includes:

```text
primary
addon
subdomain
alias
```

Assert all are stored.

Assert default monitoring:

```text
website: true
development: true
unknown: true
service: false
alias: false
ignored: false
```

- [ ] **Step 5: Preserve manual classification**

Given an existing domain:

```text
classification=website
classification_source=manual
```

and automatic classifier now returns `unknown`, sync must leave `website/manual` unchanged.

- [ ] **Step 6: Write failing partial-sync safety test**

When one account’s domain API call fails:

- existing domains for that account are not removed
- server sync is eventually marked `partial`
- other accounts may still sync successfully
- error count/summary record which cPanel username failed
- token/API secrets are absent from summary

- [ ] **Step 7: Implement job orchestration**

`SyncServerInventory`:

1. Guard disabled/removed server.
2. Create SyncRun running.
3. Fetch account inventory + account disk usage.
4. Reconcile accounts.
5. Mark missing accounts removed only after account fetch success.
6. Dispatch one `SyncCpanelAccountDomains` job per active account with sync-run ID.
7. Finalize sync only after all account domain work completes.

Use Laravel job batching if it makes finalization reliable; if using a batch, persist the batch ID on `SyncRun` with a migration in this task.

- [ ] **Step 8: Make jobs idempotent**

Re-running jobs must not duplicate accounts/domains.

Use unique DB constraints plus upsert logic.

- [ ] **Step 9: Run tests and commit**

```bash
ddev exec php artisan test tests/Feature/Inventory
ddev exec ./vendor/bin/pint --test
git add app/Services/Inventory app/Jobs/Inventory database tests/Feature/Inventory
git commit -m "feat: synchronize WHM estate inventory"
```

---

### Task 9: Add sync history, manual sync, and inventory freshness UI

**Files:**
- Modify: `app/Livewire/Servers/Show.php`
- Modify: `resources/views/livewire/servers/show.blade.php`
- Test: `tests/Feature/Ui/ServerSyncUiTest.php`

**Interfaces:**
- Consumes `SyncServerInventory::dispatch`.
- Read-only against WHM; manual action only queues a dashboard refresh.

- [ ] **Step 1: Write failing UI tests**

All staff can see:

```text
last sync
last successful sync
sync status
accounts/domains counts
recent sync runs
```

Only admin sees `Sync now`.

- [ ] **Step 2: Implement manual sync guard**

Admin action:

```php
public function syncNow(): void
```

Rules:

- server must be enabled
- reject if a running sync exists/recent job lock exists
- dispatch job
- show queued notification
- never call WHM synchronously from browser request

- [ ] **Step 3: Add recent sync history table**

Columns:

```text
Started
Finished
Status
Accounts found/changed/removed
Domains found/changed/removed
Errors
```

- [ ] **Step 4: Run tests and commit**

```bash
ddev exec php artisan test tests/Feature/Ui/ServerSyncUiTest.php
git add app/Livewire/Servers resources/views/livewire/servers tests/Feature/Ui
git commit -m "feat: show estate sync history"
```

---

### Task 10: Implement HTTP domain monitoring

**Files:**
- Create: `app/Data/Monitoring/HttpResult.php`
- Create: `app/Jobs/Monitoring/CheckDomainHttp.php`
- Test: `tests/Feature/Monitoring/CheckDomainHttpTest.php`

**Interfaces:**
- Produces `DomainCheck` with `check_type=http`.

- [ ] **Step 1: Write failing HTTP success test using `Http::fake()`**

For monitored active domain `example.co.uk`, assert:

```text
requested URL starts https://example.co.uk/
successful=true
http_status=200
response_time_ms populated
final_url populated
redirect_count populated where client exposes it
checked_at populated
```

Do not test against the internet.

- [ ] **Step 2: Define URL selection**

For v1:

1. Attempt `https://{domain}/`.
2. Do not silently downgrade certificate failures to HTTP.
3. If HTTPS fails because the host is genuinely not serving TLS, record the failure; v1 is intended to expose that.
4. Follow redirects up to a finite limit.
5. Use a descriptive internal User-Agent such as `WebsiteEstateMonitor/1.0`.

- [ ] **Step 3: Implement job guards**

Skip without creating a check when:

- domain removed
- account removed
- server disabled
- domain `monitoring_enabled=false`
- classification alias/service/ignored

- [ ] **Step 4: Implement failure recording**

Network/timeout/HTTP-client exception produces a `DomainCheck`:

```text
successful=false
error_type=<stable internal code>
error_message=<sanitized message>
```

Do not let one domain job fail the whole queue worker.

- [ ] **Step 5: Run tests and commit**

```bash
ddev exec php artisan test tests/Feature/Monitoring/CheckDomainHttpTest.php
git add app/Data/Monitoring app/Jobs/Monitoring tests/Feature/Monitoring
git commit -m "feat: monitor domain HTTP health"
```

---

### Task 11: Implement DNS monitoring behind a testable resolver interface

**Files:**
- Create: `app/Services/Monitoring/Contracts/DnsResolver.php`
- Create: `app/Services/Monitoring/NativeDnsResolver.php`
- Create: `app/Data/Monitoring/DnsResult.php`
- Create: `app/Jobs/Monitoring/CheckDomainDns.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/Monitoring/NativeDnsResolverTest.php`
- Test: `tests/Feature/Monitoring/CheckDomainDnsTest.php`

**Interfaces:**
- Produces:

```php
interface DnsResolver
{
    public function resolve(string $hostname): DnsResult;
}
```

- `DnsResult` contains normalized A/AAAA/CNAME values and success/error.

- [ ] **Step 1: Write resolver/job tests**

For job tests bind a fake `DnsResolver`; never rely on external DNS.

Assert successful result stores:

```text
check_type=dns
successful=true
resolved_ips=[...]
```

Assert failure stores stable error.

- [ ] **Step 2: Implement native resolver**

Use PHP DNS functions and normalize:

- A addresses
- AAAA addresses
- CNAME target(s)

`resolved_ips` JSON may hold a structured object rather than just IPs:

```json
{
  "a": ["192.0.2.1"],
  "aaaa": [],
  "cname": []
}
```

- [ ] **Step 3: Bind interface and run tests**

```bash
ddev exec php artisan test tests/Unit/Monitoring/NativeDnsResolverTest.php tests/Feature/Monitoring/CheckDomainDnsTest.php
git add app/Services/Monitoring app/Data/Monitoring app/Jobs/Monitoring app/Providers tests
git commit -m "feat: monitor domain DNS health"
```

---

### Task 12: Implement TLS certificate inspection behind a testable interface

**Files:**
- Create: `app/Services/Monitoring/Contracts/TlsInspector.php`
- Create: `app/Services/Monitoring/NativeTlsInspector.php`
- Create: `app/Data/Monitoring/TlsResult.php`
- Create: `app/Jobs/Monitoring/CheckDomainTls.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Monitoring/CheckDomainTlsTest.php`
- Test: `tests/Unit/Monitoring/NativeTlsInspectorTest.php`

**Interfaces:**
- Produces:

```php
interface TlsInspector
{
    public function inspect(string $hostname, int $port = 443): TlsResult;
}
```

- [ ] **Step 1: Write tests around fake inspector**

Assert storage:

```text
check_type=tls
ssl_valid=true/false
ssl_expires_at
ssl_days_remaining
error_type/error_message
```

- [ ] **Step 2: Implement native TLS inspection**

Use a TLS stream context with:

```text
verify_peer=true
verify_peer_name=true
capture_peer_cert=true
SNI enabled
peer_name={domain}
```

Parse certificate validity dates and ensure hostname verification is performed by the TLS layer.

Never add `verify_peer=false`.

- [ ] **Step 3: Treat expiry as data, not transport failure**

A valid certificate expiring in 5 days is a successful TLS inspection with `ssl_valid=true`, allowing IssueEvaluator to classify severity.

- [ ] **Step 4: Run tests and commit**

```bash
ddev exec php artisan test tests/Unit/Monitoring/NativeTlsInspectorTest.php tests/Feature/Monitoring/CheckDomainTlsTest.php
git add app/Services/Monitoring app/Data/Monitoring app/Jobs/Monitoring app/Providers tests
git commit -m "feat: monitor domain TLS certificates"
```

---

### Task 13: Implement server health monitoring

**Files:**
- Create: `app/Jobs/Monitoring/CheckServerHealth.php`
- Test: `tests/Feature/Monitoring/CheckServerHealthTest.php`

**Interfaces:**
- Consumes `WhmClient::getServerHealth()`.
- Produces `ServerCheck`.

- [ ] **Step 1: Write success test**

Fake WHM returns:

```text
load_1m
load_5m
load_15m
partition list with total/used/percentage
```

Assert `ServerCheck(reachable=true)` stores normalized data.

- [ ] **Step 2: Write failure test**

`WhmApiException` must create:

```text
reachable=false
error_message=sanitized
```

Do not throw out of the job after recording expected remote failure.

- [ ] **Step 3: Run tests and commit**

```bash
ddev exec php artisan test tests/Feature/Monitoring/CheckServerHealthTest.php
git add app/Jobs/Monitoring tests/Feature/Monitoring
git commit -m "feat: monitor WHM server health"
```

---

### Task 14: Implement issue evaluation with debounce and automatic resolution

**Files:**
- Create: `app/Services/Monitoring/IssueEvaluator.php`
- Modify monitoring jobs to invoke evaluator after writing a check
- Test: `tests/Unit/Monitoring/IssueEvaluatorTest.php`
- Test: `tests/Feature/Monitoring/IssueLifecycleTest.php`

**Interfaces:**
- Produces one active issue per `(target, type)` and retains resolved history.

- [ ] **Step 1: Write failing HTTP availability lifecycle tests**

Cases:

```text
1 failure → no issue
2 consecutive failures → critical issue open
subsequent failure → same issue last_detected_at updated
1 success → issue remains open
2 consecutive successes → issue resolved
future 2 failures → new issue row, old issue remains resolved
```

Issue type: `http_unavailable`.

- [ ] **Step 2: Write HTTP 4xx and slow-response tests**

Rules:

```text
2 consecutive root 4xx → warning `http_client_error`
3 consecutive >2000ms → warning `http_slow`
recovery below threshold for required debounce → resolve
```

5xx belongs to `http_unavailable` critical, not duplicate warning.

- [ ] **Step 3: Write DNS lifecycle tests**

Two consecutive DNS failures → critical `dns_unresolved`.

Successful DNS checks resolve after recovery debounce of two.

- [ ] **Step 4: Write TLS severity tests**

Immediate deterministic configuration problems do not need network debounce:

```text
ssl_valid=false → critical `tls_invalid`
days_remaining 29 → warning `tls_expiring`
days_remaining 6 → critical `tls_expiring`
days_remaining 31 → resolve `tls_expiring`
```

Do not create both warning and critical rows for the same `tls_expiring` issue; update severity on the active issue.

- [ ] **Step 5: Write server health tests**

```text
2 unreachable checks → critical `server_unreachable`
2 reachable checks → resolve
partition >=85% → warning `disk_usage`
partition >=95% → critical `disk_usage`
partition <85% → resolve
```

Store partition name in issue details.

- [ ] **Step 6: Implement issue upsert/resolve helpers**

Private operations should make duplicate issues impossible:

```php
openOrTouch(...)
resolveActive(...)
```

Wrap updates in transactions and use database uniqueness/locking where practical.

- [ ] **Step 7: Add inventory-derived suspended-account issue evaluation**

After successful inventory reconciliation:

```text
suspended=true → warning `account_suspended`
suspended=false → resolve
```

Do not evaluate suspension for removed accounts.

- [ ] **Step 8: Run tests and commit**

```bash
ddev exec php artisan test tests/Unit/Monitoring/IssueEvaluatorTest.php tests/Feature/Monitoring/IssueLifecycleTest.php
git add app/Services/Monitoring app/Jobs app/Services/Inventory tests
git commit -m "feat: evaluate and resolve estate issues"
```

---

### Task 15: Implement scheduler dispatch, queue overlap protection, and retention

**Files:**
- Modify: `routes/console.php`
- Create optional dispatch commands only if they improve testability:
  - `app/Console/Commands/DispatchInventorySync.php`
  - `app/Console/Commands/DispatchDomainChecks.php`
  - `app/Console/Commands/DispatchServerChecks.php`
- Add pruning support to `DomainCheck` and `ServerCheck`
- Test: `tests/Feature/Scheduling/EstateScheduleTest.php`
- Test: `tests/Feature/Database/MonitoringRetentionTest.php`

**Interfaces:**
- Produces recurring queued jobs.
- No scheduled callback performs remote network work inline.

- [ ] **Step 1: Write scheduling tests**

Verify due-event intent:

```text
server checks: every 5 minutes
HTTP checks: every 10 minutes
DNS checks: every 6 hours
TLS checks: every 6 hours
inventory: daily at 03:00 Europe/London
pruning: daily
```

- [ ] **Step 2: Implement dispatch commands**

Each command queries eligible IDs and dispatches jobs in chunks.

Example:

```php
Domain::query()
    ->whereNull('removed_at')
    ->where('monitoring_enabled', true)
    ->whereIn('classification', [
        DomainClassification::Website,
        DomainClassification::Development,
        DomainClassification::Unknown,
    ])
    ->select('id')
    ->chunkById(100, fn ($domains) => ...);
```

- [ ] **Step 3: Protect overlaps**

Scheduler dispatches use `withoutOverlapping()`.

Individual jobs use middleware/unique-job semantics where a duplicate check for the same entity/check type would otherwise overlap.

Do not use `onOneServer()` yet unless local cache semantics make its behavior testable; document that it becomes mandatory when production has multiple scheduler nodes sharing cache.

- [ ] **Step 4: Implement 90-day pruning**

Use Laravel model pruning or explicit scheduled deletion:

```text
domain_checks checked_at < now()-90 days
server_checks checked_at < now()-90 days
```

Keep `issues` and `sync_runs`.

- [ ] **Step 5: Run schedule locally**

```bash
ddev exec php artisan schedule:list
ddev exec php artisan queue:work --stop-when-empty
ddev exec php artisan test tests/Feature/Scheduling tests/Feature/Database/MonitoringRetentionTest.php
```

Expected: schedule contains all events and tests pass.

- [ ] **Step 6: Commit**

```bash
git add app routes tests
git commit -m "feat: schedule estate monitoring jobs"
```

---

### Task 16: Build the estate dashboard overview and drill-down UI

**Files:**
- Create/modify Livewire components:
  - `Dashboard`
  - `Servers/Index`, `Servers/Show`
  - `Accounts/Index`, `Accounts/Show`
  - `Domains/Index`, `Domains/Show`
  - `Issues/Index`
- Create corresponding Blade views
- Create reusable components under `resources/views/components/estate/`
- Modify navigation/layout
- Test: `tests/Feature/Ui/EstateNavigationTest.php`
- Test: `tests/Feature/Ui/DashboardSummaryTest.php`
- Test: `tests/Feature/Ui/DomainClassificationTest.php`

**Interfaces:**
- Consumes all v1 models.
- No remote calls are allowed while rendering dashboard pages.

- [ ] **Step 1: Write failing navigation/authorization tests**

Authenticated staff sees:

```text
Overview
Servers
Accounts
Domains
Issues
```

Admin additionally sees:

```text
Admin / Users
Admin server edit controls
```

Guest redirected to login.

- [ ] **Step 2: Build overview summary**

Cards:

```text
Enabled servers
Active cPanel accounts
Active domains
Monitored domains
Healthy
Warnings
Critical
```

Sections:

```text
Unresolved critical issues
Recent warnings
Servers with stale/failed inventory
```

Health counts are derived from unresolved issues, not from browser-side remote calls.

- [ ] **Step 3: Build server page**

Show:

```text
current reachability
latest load
partition usage
account/domain counts
last successful inventory
recent sync runs
unresolved issues
cPanel accounts table
```

- [ ] **Step 4: Build account page**

Show:

```text
username
primary domain
server
package
owner
suspended state
disk usage/quota
last seen
all discovered domains grouped by type/classification
```

- [ ] **Step 5: Build domains index**

Filters:

```text
server
account
type
classification
monitoring enabled
health severity
removed/current
search by domain
```

Default excludes removed domains but offers “include removed”.

- [ ] **Step 6: Build domain detail page**

Show:

```text
domain
type
classification
document root
account/server
monitoring state
latest HTTP/DNS/TLS results
recent checks
active/resolved issues
```

No charting library in v1. A simple recent-history table is enough.

- [ ] **Step 7: Implement manual classification control**

All staff may view classification; choose one of these policies and test it consistently:

**Recommended:** only admins may change dashboard classification/monitoring flags because these are application configuration changes.

Admin may set:

```text
classification
monitoring_enabled
```

Changing classification sets:

```text
classification_source=manual
```

Provide “Reset to automatic” to set source `auto` and immediately re-run classifier.

This changes only dashboard metadata, never WHM.

- [ ] **Step 8: Build issues page**

Filters:

```text
open/resolved
severity
server
account
domain
type
```

Default view: unresolved, critical first, newest detection first.

- [ ] **Step 9: Run UI tests and frontend build**

```bash
ddev exec php artisan test tests/Feature/Ui
ddev exec npm run build
ddev exec ./vendor/bin/pint --test
```

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Livewire resources routes tests/Feature/Ui
git commit -m "feat: add estate monitoring dashboard"
```

---

### Task 17: Add operational logging, failed-job visibility, and safe error presentation

**Files:**
- Create: `app/Support/EstateLogContext.php` if useful
- Modify jobs/services logging
- Create UI element/page for recent failed syncs/jobs if needed
- Test: `tests/Feature/Operations/SensitiveDataTest.php`
- Test: `tests/Feature/Operations/JobFailureTest.php`

**Interfaces:**
- Ensures operational failures can be diagnosed without leaking WHM credentials.

- [ ] **Step 1: Write sensitive-data regression tests**

Create a server with token:

```text
SUPER_SECRET_TOKEN
```

Force WHM failures.

Assert token is absent from:

```text
exception messages
sync_run.error_summary
issue.details
rendered HTML
serialized Server model
logs captured during test where practical
```

- [ ] **Step 2: Add structured context**

Log safe identifiers:

```text
server_id
server hostname
cpanel_account_id/username
domain_id/domain
sync_run_id
job class
```

Never log Authorization header or token.

- [ ] **Step 3: Ensure unexpected job failures remain visible**

Expected remote failures are converted into check/sync status.

Programming/database failures should still fail the queue job so they appear in Laravel failed jobs.

Configure database failed-job storage if not already present.

- [ ] **Step 4: Run tests and commit**

```bash
ddev exec php artisan test tests/Feature/Operations
git add app config database resources tests
git commit -m "chore: harden estate monitoring operations"
```

---

### Task 18: Exercise the first real WHM server safely from local DDEV

**Files:**
- No code required unless real API responses expose a normalization bug.
- Create: `docs/whm-readonly-token.md`
- Update fixtures/tests if real response structures reveal valid variants.

**Interfaces:**
- Validates the integration against real infrastructure without writing to it.

- [ ] **Step 1: Create a dedicated WHM API token**

Use WHM’s API token UI.

Start with least-privilege read permissions required for v1. cPanel documents `list-accts` as covering `listaccts`, `get_disk_usage`, and `uapi_cpanel`; server-information calls may additionally require server-information/status privileges depending on the WHM user/ACL configuration.

Do not use a password or legacy access hash.

- [ ] **Step 2: Prefer a dedicated WHM/reseller identity if it can see the required estate**

If a non-root WHM/reseller account cannot see all required accounts/server metrics, document the limitation before increasing privilege.

The app remains read-only regardless of the identity used.

- [ ] **Step 3: Add only Server 1 through the admin UI**

Before full sync:

```text
Test connection → success
```

Then trigger:

```text
Sync now
```

- [ ] **Step 4: Compare dashboard inventory to WHM manually**

Verify at least:

```text
account count
several primary domains
one addon domain
one subdomain
one alias/parked domain
one suspended account if available
document roots where supplied
```

Do not connect Servers 2 and 3 until Server 1 matches expectations.

- [ ] **Step 5: Capture response-shape edge cases as fixtures**

If real WHM/UAPI returns unexpected but valid nulls/strings/numeric formats:

1. Add sanitized fixture/test.
2. Make test fail.
3. Normalize in client DTO mapper.
4. Re-run tests.

Do not special-case a production account by hostname/username.

- [ ] **Step 6: Commit documentation/normalization fixes**

```bash
git add docs tests app
git commit -m "test: validate WHM inventory against real server"
```

---

### Task 19: Connect all three servers and perform v1 acceptance testing

**Files:**
- Create: `docs/v1-acceptance.md`
- Modify only code required by failing acceptance tests.

**Interfaces:**
- Produces locally usable v1 against the complete estate.

- [ ] **Step 1: Add Servers 2 and 3**

For each:

```text
Test connection
Run inventory sync
Compare account/domain totals with WHM
```

- [ ] **Step 2: Run one complete monitoring cycle**

Manually dispatch:

```bash
ddev exec php artisan estate:dispatch-server-checks
ddev exec php artisan estate:dispatch-domain-checks http
ddev exec php artisan estate:dispatch-domain-checks dns
ddev exec php artisan estate:dispatch-domain-checks tls
ddev exec php artisan queue:work --stop-when-empty
```

Use the exact command names implemented in Task 15.

- [ ] **Step 3: Verify expected failures are useful rather than noisy**

Inspect:

```text
5xx/unreachable domains
4xx homepages
slow sites
DNS failures
TLS expiry
suspended accounts
server disk warnings
```

Do not tune rules to hide genuine problems. Fix only false-positive logic.

- [ ] **Step 4: Validate removed-item safety**

Using fakes/tests rather than deleting live hosting:

```text
failed server sync does not remove anything
failed account-domain sync does not remove its domains
successful absence marks removed_at
reappearance clears removed_at and resumes monitoring
```

- [ ] **Step 5: Run complete quality gate**

```bash
ddev exec php artisan test
ddev exec ./vendor/bin/pint --test
ddev exec npm run build
ddev exec php artisan schedule:list
ddev exec php artisan route:list
```

Expected: all pass; routes contain no public registration route.

- [ ] **Step 6: Record acceptance criteria**

`docs/v1-acceptance.md` must confirm:

```text
[ ] staff login works
[ ] public registration absent
[ ] admin user management works
[ ] all 3 WHM connections work
[ ] account inventory matches WHM
[ ] primary/addon/subdomain/alias discovery verified
[ ] manual classification survives resync
[ ] monitoring jobs run from queue
[ ] HTTP/DNS/TLS/server checks are stored
[ ] issues debounce and auto-resolve
[ ] failed sync cannot mark estate removed
[ ] 90-day retention configured
[ ] no server/site mutation actions exist
[ ] full test suite passes
```

- [ ] **Step 7: Commit v1 acceptance**

```bash
git add .
git commit -m "test: complete estate dashboard v1 acceptance"
```

---

### Task 20: Prepare, but do not implement, production deployment/HA decisions

**Files:**
- Create: `docs/production-readiness.md`

**Interfaces:**
- No production changes. This is the handoff point for a separate design/spec when staff access is required.

- [ ] **Step 1: Document production runtime requirements**

Record:

```text
PHP 8.4-compatible cPanel runtime
web root points to Laravel /public
MariaDB/MySQL
HTTPS
cron invoking `php artisan schedule:run`
persistent queue worker
working SMTP for password reset
APP_KEY securely managed
WHM API tokens securely entered after deployment
```

- [ ] **Step 2: Document HA questions for the future design**

Explicitly defer:

```text
single vs multiple Laravel app nodes
load balancer / Cloudflare strategy
shared database placement/replication
shared cache/session/queue
single scheduler ownership (`onOneServer`)
queue worker topology
failure if the database node dies
external heartbeat for the dashboard itself
deployment synchronization across nodes
```

Do not “cluster the three WHM servers” as an incidental part of v1. Treat availability as a separate production architecture project.

- [ ] **Step 3: Document application assumptions that already support HA**

Confirm:

```text
database-backed sessions
queued work
no required local-file state
idempotent jobs
scheduler overlap protection
encrypted credentials
read-only external integration
```

- [ ] **Step 4: Commit**

```bash
git add docs/production-readiness.md
git commit -m "docs: capture production readiness requirements"
```

---

## Milestone checkpoints

### Milestone A — Secure shell

Tasks 1–4 complete.

You can:

- run Laravel in DDEV/WSL
- log in
- create admin/staff accounts
- see a dashboard shell
- persist the estate data model

### Milestone B — Inventory MVP

Tasks 5–9 complete.

You can:

- add a WHM server
- test the connection
- import accounts
- import primary/addon/subdomain/alias domains
- safely re-sync
- see sync history

**At this point the project is already useful.**

### Milestone C — Monitoring MVP

Tasks 10–15 complete.

You can:

- monitor HTTP
- monitor DNS
- inspect TLS
- monitor server health
- automatically open/resolve issues
- run everything on Laravel queues/scheduler

### Milestone D — Staff dashboard

Tasks 16–17 complete.

You have:

- overview
- drill-down
- filters
- issue views
- safe operational diagnostics

### Milestone E — Real-estate validation

Tasks 18–19 complete.

All three real WHM servers are connected locally and v1 acceptance passes.

### Milestone F — Production architecture

Task 20 completes documentation only.

Start a **new brainstorming/design cycle** before deploying to a clustered/HA production topology.

---

## Codex working rules

Give Codex this plan and require it to work one task at a time.

For every task:

1. Read the task and its interface contract.
2. Inspect existing files before changing them.
3. Write the specified failing test first.
4. Run only the focused test and confirm the expected failure.
5. Implement the smallest change that satisfies it.
6. Run the focused test.
7. Run relevant surrounding tests.
8. Run Pint for PHP changes.
9. Show the diff for review.
10. Commit only after the task is accepted.

Do not let Codex:

- build phase 2 features opportunistically
- add write-capable WHM endpoints
- disable TLS verification
- bypass tests because a real API works
- introduce Redis “for scalability”
- create a generic repository/service layer around every Eloquent model
- collapse WHM parsing, reconciliation, job orchestration, and UI logic into one class
- silently change the approved issue thresholds
- delete missing estate records after an unsuccessful/partial source fetch

## Recommended first Codex prompt

```text
We are implementing the Website Estate Dashboard v1 using the approved
design and implementation plan in docs/superpowers.

Use the Superpowers workflow. Start with Task 1 only.

Do not implement later tasks. Inspect the repository first, then follow TDD
where the task contains application behavior. Use DDEV commands for all
runtime/test commands. Before claiming Task 1 complete, run every verification
command listed in Task 1 and show me the results and git diff.
```

Then continue:

```text
Task 1 is approved. Implement Task 2 only, following the implementation plan.
```

This deliberately keeps Codex from trying to “help” by building half the application in one pass.
