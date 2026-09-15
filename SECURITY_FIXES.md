# BanquetDesk Security Fixes

Companion to `SECURITY_AUDIT.md`. Documents each finding, fix, and verification.

| ID | Severity | Vulnerability | Location | Status |
|----|----------|---------------|----------|--------|
| SEC-001 | Critical | Unauthenticated RestQuery / tenant data exposure | `RestQueryController` | Fixed |
| SEC-002 | Critical | Plaintext passwords + password filters | Auth + SPA | Fixed |
| SEC-003 | Critical | Company header spoofing | API auth | Fixed |
| SEC-004 | Critical | Role/subscription mass assignment | RestQuery writes | Fixed |
| SEC-005 | High | Companies table unscoped reads | `applyCompanyScope` | Fixed |
| SEC-006 | High | Company IDOR on show/update | `CompanyController` | Fixed |
| SEC-007 | High | Plaintext SU/restore password checks | SU + Backup | Fixed |
| SEC-008 | High | Missing rate limiting | `routes/api.php` | Fixed |
| SEC-009 | High | Password leakage in API rows | `decodeRow` | Fixed |
| SEC-010 | High | Non-Admin role management | RestQuery | Fixed |
| SEC-011 | Medium | Missing security headers | `SecurityHeaders` | Fixed |
| SEC-012 | Medium | Unsafe restore filenames | `BackupController` | Fixed |
| SEC-013 | Medium | Insecure `.env.example` defaults | `.env.example` | Fixed |
| SEC-014 | Medium | Unvalidated filter/order/select columns | RestQuery | Fixed |
| SEC-015 | Medium | CSRF off for `api/*` | `bootstrap/app.php` | Partially fixed |
| SEC-016 | Medium | Session cookie flags | `.env.example` | Fixed (template) |
| SEC-017 | Medium | Verbose API errors | various | Partially fixed |
| SEC-018–024 | Low/Info | See audit report | — | Remaining / Out of scope |

---

## SEC-001 — Unauthenticated RestQuery

- **Root cause:** `/api/db/query` and bootstrap accepted requests with only optional `X-Company-Id`.
- **Impact:** Cross-tenant data read/write.
- **Fix:** Require `CompanyApiSession` (`X-Api-Token`) for all RestQuery/bootstrap except public vendor insert; force company from token.
- **Verification:** Route list shows auth login; unauthorized query returns 401 (manual on deploy).

## SEC-002 — Plaintext passwords

- **Root cause:** Passwords stored and compared as plaintext; SPA filtered by password column.
- **Impact:** Credential theft from DB/API, trivial auth bypass knowledge.
- **Fix:** `Hash::make` on write (RestQuery, company register, seeder, SU password change); `Hash::check` + legacy upgrade on login; SPA uses `/api/auth/login`; password filters rejected.
- **Verification:** PHPUnit passed; login path patched (`/api/auth/login` present, `.eq("password")` removed).

## SEC-003 — Company spoofing

- **Root cause:** Tenant boundary taken from client header alone.
- **Impact:** Any party knowing a UUID could operate as that company.
- **Fix:** `CompanyApiSession` issued at login; controllers prefer token `company_id`.
- **Verification:** Code review of AuthController + RestQuery + Backup + Company controllers.

## SEC-004 / SEC-010 — Privilege / mass assignment

- **Root cause:** Generic write API accepted `role`, subscription fields, `status`.
- **Impact:** SuperAdmin creation, free subscriptions, account status changes.
- **Fix:** Block company billing/status fields; disallow `SuperAdmin` role; only Admin may set roles; companies insert/delete blocked on RestQuery.
- **Verification:** Static review of `prepareWriteRow` / handle guards.

## SEC-005 — Companies scope

- **Fix:** `applyCompanyScope` for `companies` uses `where('id', $companyId)`.

## SEC-006 — Company IDOR

- **Fix:** `show`/`update` require matching API session company id.

## SEC-007 — SU / restore hashing

- **Fix:** Hash verification with one-time plaintext upgrade; new passwords hashed.

## SEC-008 — Rate limits

- **Fix:** Throttle middleware on login (10/min), register (5/min), SU login (5/min), restore (3/min), and other API routes.

## SEC-009 — Password stripping

- **Fix:** `decodeRow` always `unset($row['password'])`; AuthController returns public user without password.

## SEC-011 — Security headers

- **Fix:** Global `SecurityHeaders` middleware (CSP tuned for CDN React/Tailwind, nosniff, frame options, referrer, permissions-policy, HSTS when HTTPS).

## SEC-012 — Upload hardening

- **Fix:** Reject dangerous substrings in filenames; store under generated safe names on `local` disk.

## SEC-013 / SEC-016 — Config template

- **Fix:** `.env.example` production-oriented: `APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`.

## SEC-014 — Column validation

- **Fix:** Filters, order, and select columns must exist on the target table; limits capped.

## SEC-015 — CSRF (partial)

- **Status:** Still exempt `api/*` for SPA JSON API. Compensating controls: API tokens, rate limits, SameSite defaults. Full cookie CSRF for SPA is future work (Sanctum).

## SEC-017 — Errors (partial)

- **Status:** Auth login no longer returns raw exceptions; other endpoints may still return `$e->getMessage()`. Prefer generic messages in production later.

---

## Deploy notes (VPS)

1. Pull/deploy this codebase  
2. `composer install --no-dev --optimize-autoloader`  
3. Ensure `APP_DEBUG=false`, strong `APP_KEY`, `SESSION_SECURE_COOKIE=true` once HTTPS is live  
4. Users must **log in again** to obtain `api_token`  
5. Existing plaintext passwords hash automatically on next successful login  
6. Re-seed only on empty/dev DBs — seeder now stores bcrypt hashes  
