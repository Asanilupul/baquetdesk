# BanquetDesk Security Audit Report

**Date:** 2026-09-15  
**Scope:** This Laravel application codebase only (defensive review + fixes)  
**Laravel:** framework ^13.17 (installed tree)  
**PHP requirement:** ^8.3 (local CLI observed: 8.4.x)  
**Status:** Hardening applied; residual risks remain (not “100% secure”)

---

## 1. Executive Summary

BanquetDesk is a multi-tenant banquet/catering ERP delivered as a React CDN SPA (`public/banquetdesk.app.js`) backed by Laravel JSON APIs (`/api/db/*`, company, backup, super-admin). The highest-risk issues were:

1. **Unauthenticated / weakly authenticated RestQuery** exposing nearly all business tables  
2. **Plaintext password storage and password equality filters** for login  
3. **Company ID spoofing** via client-controlled `X-Company-Id` without proof of login  
4. **Mass-assignment of billing/role fields** through the generic query API  

Critical and high findings above were **fixed in code**. Remaining risks are architectural (SPA sessionStorage tokens, CSRF exempt `api/*`, shared `system_settings`, no Sanctum/Passport yet) and require ongoing hardening.

| Severity | Count |
|----------|------:|
| Critical | 4 |
| High | 6 |
| Medium | 7 |
| Low | 4 |
| Informational | 3 |
| **Total** | **24** |

---

## 2. Application Architecture

| Area | Discovery |
|------|-----------|
| App type | Laravel API + static SPA (`banquetdesk.html` / `su-admin.html`) |
| Auth | Custom `/api/auth/login` + cache-backed `api_token`; Super Admin `/api/su/*` + `X-SU-Token` |
| Tenancy | `company_id` on most tables; Super Admin has no company |
| Authorization | Role strings (`Admin`, `Manager`, `Accountant`, `Vendor`, `SuperAdmin`); limited server checks |
| Data access | Generic `RestQueryController` whitelist of tables + Eloquent-less query builder |
| Storage | Local disk backups under company folders; logo may be data URLs |
| Jobs/schedule | Backup auto + subscription reminder commands (console) |
| Frontend | Minified React via CDN (Tailwind/unpkg); no Vite SPA build required for main UI |
| Payments | Not present as payment gateway integration |
| OTP/SMS | Not present |

---

## 3. Attack Surface

- Public: `/`, `/su-admin`, `/up`, `/api/auth/login`, `/api/company/register`, `/api/su/login`, public vendor insert  
- Authenticated company APIs: `/api/db/query`, `/api/db/bootstrap`, `/api/company/{id}`, `/api/backup/*`  
- Super Admin APIs: `/api/su/companies`, subscription/status/password  
- Client storage: `sessionStorage.snap_current_user` (includes `api_token`)  
- CSRF: `api/*` exempt (JSON API / SPA pattern)  
- File upload: backup restore only (zip/tar/json/gz)

---

## 4. Vulnerabilities Found

| ID | Severity | Vulnerability | Location | Status |
|----|----------|---------------|----------|--------|
| SEC-001 | Critical | Open RestQuery without login; company dump/IDOR via missing auth | `RestQueryController` | Fixed |
| SEC-002 | Critical | Plaintext passwords + `.eq("password")` login | users/vendors + SPA | Fixed |
| SEC-003 | Critical | `X-Company-Id` spoofing (tenant bypass) | API headers | Fixed |
| SEC-004 | Critical | Privilege escalation to SuperAdmin / subscription fields via RestQuery | `prepareWriteRow` | Fixed |
| SEC-005 | High | Unscoped `companies` select returned all tenants | `applyCompanyScope` | Fixed |
| SEC-006 | High | Company show/update IDOR | `CompanyController` | Fixed |
| SEC-007 | High | Super Admin / restore used plaintext password compare | `SuperAdminController`, `BackupController` | Fixed |
| SEC-008 | High | Missing rate limits on auth/register/restore | `routes/api.php` | Fixed |
| SEC-009 | High | Passwords returned in API/bootstrap payloads | `decodeRow` | Fixed |
| SEC-010 | High | Non-Admin users could manage roles | RestQuery users writes | Fixed |
| SEC-011 | Medium | No security headers / CSP | middleware | Fixed |
| SEC-012 | Medium | Backup upload used original filename | `BackupController@restore` | Fixed |
| SEC-013 | Medium | Weak production defaults in `.env.example` (`APP_DEBUG=true`) | `.env.example` | Fixed |
| SEC-014 | Medium | Filter/order/select column injection risk | RestQuery | Fixed |
| SEC-015 | Medium | CSRF disabled for all `api/*` | `bootstrap/app.php` | Partially fixed / accepted risk |
| SEC-016 | Medium | Session cookie secure flag not documented for HTTPS | `.env.example` | Fixed (example) |
| SEC-017 | Medium | Error messages may leak exception text from APIs | controllers | Partially fixed |
| SEC-018 | Low | Minimum password length 4 | register / SU change | Requires manual verification / product decision |
| SEC-019 | Low | Tokens in `sessionStorage` (XSS would steal session) | SPA | Remaining risk |
| SEC-020 | Low | CSP allows `'unsafe-inline'`/`'unsafe-eval'` (CDN SPA) | `SecurityHeaders` | Accepted / documented |
| SEC-021 | Low | Shared global `system_settings` (not company-scoped) | schema | Remaining risk |
| SEC-022 | Info | Composer not on Windows PATH; audit not re-run locally | tooling | Out of scope / Not tested here |
| SEC-023 | Info | Only 2 PHPUnit tests | `tests/` | Remaining risk |
| SEC-024 | Info | HTTPS/HSTS depends on TLS termination on VPS | deploy | Requires manual verification |

---

## 5. Evidence (selected)

- Pre-fix SPA login: `supabase.from("users").eq("username").eq("password")`  
- Pre-fix RestQuery allowed unscoped `users`/`companies` selects  
- Post-fix: `/api/auth/login` issues `api_token`; RestQuery requires `X-Api-Token` and binds company from token  

---

## 6. Files Changed

- `app/Http/Controllers/AuthController.php` (new/updated)  
- `app/Http/Controllers/RestQueryController.php`  
- `app/Http/Controllers/CompanyController.php`  
- `app/Http/Controllers/SuperAdminController.php`  
- `app/Http/Controllers/BackupController.php`  
- `app/Http/Middleware/SecurityHeaders.php`  
- `app/Support/CompanyApiSession.php`  
- `bootstrap/app.php`  
- `routes/api.php`  
- `database/seeders/DatabaseSeeder.php`  
- `public/banquetdesk.app.js`  
- `.env.example`  
- `SECURITY_AUDIT.md`, `SECURITY_FIXES.md`, `SECURITY_CHECKLIST.md`

---

## 7. Fixes Implemented

See `SECURITY_FIXES.md`.

---

## 8. Tests Performed

| Check | Result |
|-------|--------|
| `php artisan optimize:clear` | Passed |
| `php artisan route:list --path=api` | Passed (16 routes incl. `/api/auth/login`) |
| `php artisan config:show app.name` | Passed (`BanquetDesk`) |
| `php artisan test` | Passed (2 tests) |
| `php -l` on changed controllers | Passed |
| `composer audit` / `composer validate` | **Not tested** (Composer binary not on Windows PATH in this workspace) |
| `npm audit` | **Not tested** / inconclusive in this environment |
| Live login/CRUD on production VPS | **Requires manual verification** after deploy |

---

## 9. Remaining Risks

1. **Bearer token in sessionStorage** — any future XSS can hijack API access; migrate toward HttpOnly cookies/Sanctum when feasible.  
2. **CSRF exemption on `api/*`** — mitigated by token requirement + SameSite cookies for web session, but SPA still relies on custom headers.  
3. **Role model is coarse** — Manager/Accountant still have broad RestQuery CRUD within tenant.  
4. **Legacy plaintext passwords** upgrade only on successful login; run a one-time hash migration for dormant accounts.  
5. **Backup path setting** accepts absolute server paths (Admin-controlled) — keep OS permissions tight.  
6. **No claim of complete security** — continue monitoring, HTTPS, and dependency audits on the VPS.

---

## 10. Recommended Future Improvements

1. Introduce Laravel Sanctum SPA auth with HttpOnly cookies  
2. Replace RestQuery with explicit resource controllers/policies  
3. Enforce password policy (length ≥ 8, complexity) product-wide  
4. Company-scope `system_settings` or remove dual branding paths  
5. Add feature tests for auth, IDOR, and role escalation  
6. Enable HTTPS + HSTS on Nginx; set `SESSION_SECURE_COOKIE=true` in live `.env`  
7. Run `composer audit` / `npm audit` in CI on every deploy  
8. Rotate default seeded credentials after first login in production  

---

## Dependency Security (Phase 2)

| Package | Current | Risk | Recommended | Breaking risk | Required? |
|---------|---------|------|-------------|---------------|-----------|
| `laravel/framework` | ^13.17 | Keep current minor/patch | Latest 13.x patch | Low within 13.x | Recommended when CI audit shows CVEs |
| Dev-only (`phpunit`, `pint`, `faker`, etc.) | per lockfile | Should not ship to prod | N/A | N/A | Ensure `--no-dev` on VPS |
| NPM (Vite toolchain) | present | Frontend build tooling; main UI is CDN SPA | Patch if `npm audit` reports | Medium for Vite majors | Run on CI |

**Note:** Exact CVE versions were **not re-verified** here because Composer was unavailable on the Windows agent PATH. Re-run on the VPS: `composer audit && npm audit`.
