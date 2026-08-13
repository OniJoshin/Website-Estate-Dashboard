# Website Estate Dashboard v1 — Design Specification

**Date:** 2026-08-13  
**Status:** Approved for implementation planning

## Purpose

Build a staff-only, read-only internal Laravel dashboard that automatically inventories the company’s three WHM/cPanel servers and monitors the health of the hosted website estate.

The application begins as a local DDEV project running inside WSL. Production hosting and high availability across the existing server estate are deliberately deferred until the application is ready for staff access.

## Core principles

- Read-only against WHM/cPanel and hosted websites.
- No public registration.
- Individual staff accounts using email/password.
- All authenticated staff can see the complete estate.
- Admins may manage dashboard users, server connections, and trigger dashboard-side synchronisation.
- The dashboard never performs server/site remediation.
- Inventory is automatically discovered rather than manually maintained.
- Historical monitoring and issue history are retained.
- Failed synchronisation must never be interpreted as estate deletion.
- Design background jobs to remain safe if the application is later run on multiple nodes.

## v1 scope

### Inventory

Hierarchy:

```text
Server
└── cPanel Account
    ├── Primary domain
    ├── Addon domains
    ├── Subdomains
    └── Aliases / parked domains
```

cPanel data and dashboard interpretation are deliberately separate.

Domain types:

- `primary`
- `addon`
- `subdomain`
- `alias`

Dashboard classifications:

- `website`
- `development`
- `alias`
- `service`
- `unknown`
- `ignored`

Default classification:

- Primary → website
- Addon → website
- Known development subdomain prefix → development
- Known service subdomain prefix → service
- Other subdomains → unknown
- Alias/parked → alias

Manual classification always wins over automatic classification.

### Monitoring

v1 monitors:

- Domain reachability
- HTTP status
- Response time
- Redirect destination/count
- DNS resolution
- TLS/SSL validity and expiry
- WHM server reachability
- Server load
- Server partition/disk usage
- cPanel account disk usage
- cPanel account suspension state
- Inventory freshness

Not in v1:

- WordPress plugin/theme updates
- WordPress security intelligence
- Lighthouse
- GA4
- Search Console
- Site crawling/internal broken links
- Server controls
- Automatic remediation

## Authentication and permissions

Roles:

- `admin`
- `staff`

Both roles can view all estate data.

Admin-only dashboard actions:

- Create/disable staff users
- Create/edit/disable WHM server connections
- Replace WHM API token
- Test WHM connection
- Trigger an immediate inventory sync

No role can modify WHM/cPanel or a hosted website.

Public registration is disabled. Password reset remains enabled.

## Core data model

### servers

- id
- name
- hostname
- whm_port
- api_username
- api_token (encrypted cast, hidden)
- enabled
- last_synced_at
- last_successful_sync_at
- created_at
- updated_at

### cpanel_accounts

- id
- server_id
- username
- primary_domain
- home_directory
- package
- owner
- suspended
- suspension_reason
- disk_used_bytes
- disk_limit_bytes
- metadata JSON
- discovered_at
- last_seen_at
- removed_at
- created_at
- updated_at

Unique: `(server_id, username)`

### domains

- id
- cpanel_account_id
- domain
- type
- parent_domain_id nullable
- document_root nullable
- classification
- classification_source (`auto`, `manual`)
- monitoring_enabled
- is_active
- metadata JSON
- discovered_at
- last_seen_at
- removed_at
- created_at
- updated_at

Unique: `(cpanel_account_id, domain)`

### sync_runs

- id
- server_id
- type
- status (`running`, `successful`, `partial`, `failed`)
- started_at
- completed_at
- accounts_found
- accounts_created
- accounts_updated
- accounts_removed
- domains_found
- domains_created
- domains_updated
- domains_removed
- errors_count
- error_summary
- created_at
- updated_at

### domain_checks

One table stores all domain-monitoring history. `check_type` identifies the scope so HTTP can run more often than DNS/TLS.

- id
- domain_id
- check_type (`http`, `dns`, `tls`)
- checked_at
- successful
- http_status nullable
- response_time_ms nullable
- final_url nullable
- redirect_count nullable
- resolved_ips JSON nullable
- ssl_valid nullable
- ssl_expires_at nullable
- ssl_days_remaining nullable
- error_type nullable
- error_message nullable
- created_at
- updated_at

### server_checks

- id
- server_id
- checked_at
- reachable
- load_1m nullable
- load_5m nullable
- load_15m nullable
- partitions JSON nullable
- error_message nullable
- created_at
- updated_at

### issues

An issue targets exactly one estate entity through nullable foreign keys.

- id
- server_id nullable
- cpanel_account_id nullable
- domain_id nullable
- type
- severity (`warning`, `critical`)
- title
- details
- first_detected_at
- last_detected_at
- resolved_at
- created_at
- updated_at

## WHM discovery

For each enabled server:

1. Create a `sync_runs` row.
2. Call WHM API 1 `listaccts`.
3. Upsert cPanel accounts by `(server_id, username)`.
4. Fetch account disk usage from WHM.
5. For every cPanel account call UAPI via WHM `uapi_cpanel`.
6. Use DomainInfo domain-list data as the inventory source.
7. Enrich domains with DomainInfo detailed data where available.
8. Upsert domains by `(cpanel_account_id, domain)`.
9. Automatically classify only records not manually classified.
10. Mark missing accounts/domains removed only after the relevant source completed successfully.
11. Complete the sync run as successful, partial, or failed.

If a server-level account fetch fails, do not mark any account/domain removed.

If one account’s domain fetch fails, do not mark that account’s existing domains removed.

Removed records remain in the database for history.

## Automatic classification rules

Development prefixes:

- dev
- development
- staging
- stage
- test
- testing
- new
- uat

Service prefixes:

- mail
- webmail
- cpanel
- webdisk

Unknown subdomains remain visible and can be manually classified.

Aliases/services are not monitored by default. Website, development, and unknown domains are monitored by default.

## Monitoring rules

### HTTP

Frequency: every 10 minutes.

Collect:

- Reachability
- HTTP status
- Response time
- Final URL
- Redirect count

Open an availability issue only after two consecutive failing HTTP checks.

Resolve an availability issue after two consecutive successful checks.

Rules:

- 5xx/unreachable after debounce → critical
- Root 4xx after debounce → warning
- Response > 2,000 ms for three consecutive checks → warning
- Redirect data is recorded in v1 but does not create an issue solely because the final URL changes.

### DNS

Frequency: every 6 hours.

Resolve A, AAAA and CNAME records.

Two consecutive failures to resolve → critical.

### TLS

Frequency: every 6 hours.

Rules:

- Invalid/untrusted/hostname mismatch → critical
- Expiry < 7 days → critical
- Expiry < 30 days → warning

### Server health

Frequency: every 5 minutes.

Collect:

- WHM API reachability
- 1/5/15 minute load averages
- Partition usage

Rules:

- Two consecutive server API failures → critical
- Partition usage >= 85% → warning
- Partition usage >= 95% → critical
- Load is displayed in v1 but no generic load threshold is applied because useful thresholds depend on CPU/resources.

### Inventory-derived issues

- Suspended cPanel account → warning
- Failed/partial inventory sync → visible server sync status; repeated failures may be surfaced as a server warning.

## Scheduling

Suggested local/production schedule:

- Server health: every 5 minutes
- Domain HTTP: every 10 minutes
- Domain DNS: every 6 hours
- Domain TLS: every 6 hours
- WHM inventory: nightly around 03:00
- Monitoring retention pruning: daily

Jobs are queued and staggered. Do not execute checks for every domain inline in the scheduler process.

Use overlap protection. Future production high availability must use shared state and single-scheduler semantics before multiple application nodes are enabled.

## Retention

- Keep full-resolution `domain_checks` for 90 days.
- Keep full-resolution `server_checks` for 90 days.
- Keep sync history and issues indefinitely in v1.
- Do not build aggregation/downsampling in v1.

## Dashboard

Primary navigation:

- Overview
- Servers
- Accounts
- Domains
- Issues
- Admin (admins only)

Overview shows:

- Enabled servers
- Active cPanel accounts
- Active domains
- Monitored domains
- Healthy/warning/critical counts
- Latest unresolved critical issues
- Stale/failed inventory syncs

Drill-down:

```text
Overview
→ Server
→ cPanel Account
→ Domain
→ Monitoring history / issues
```

## Production-readiness constraints

Do not solve production clustering in v1 development.

However:

- Use database-backed sessions.
- Use queue jobs for monitoring/sync work.
- Keep application state out of local filesystem.
- Make jobs idempotent.
- Prevent overlapping scheduled dispatches.
- Do not assume only one application node forever.
- Keep credentials encrypted.
- Verify TLS for WHM API calls.
- Use least-privilege WHM API tokens.
