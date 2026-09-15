# BanquetDesk Security Checklist

Use after each deploy. Mark items only after evidence.

## Production configuration

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] Strong unique `APP_KEY`
- [ ] `APP_URL` uses `https://` when TLS is enabled
- [ ] `LOG_LEVEL` is `warning` or `error` (not `debug`)
- [ ] DB user is least-privilege (not root)
- [ ] `.env` not web-accessible; `storage/` and `vendor/` not publicly listed
- [ ] `SESSION_SECURE_COOKIE=true` (HTTPS only)
- [ ] `SESSION_HTTP_ONLY=true`
- [ ] `SESSION_SAME_SITE=lax` (or `strict` if compatible)

## Authentication / authorization

- [ ] Staff/vendor login via `/api/auth/login` works
- [ ] Invalid credentials do not reveal which field failed
- [ ] Super Admin cannot use main login (must use `/su-admin`)
- [ ] Expired/pending subscription blocks login and API
- [ ] After login, SPA stores `api_token` and sends `X-Api-Token`
- [ ] Requests without token to `/api/db/query` return 401
- [ ] Changing `X-Company-Id` to another tenant UUID does **not** grant access
- [ ] Non-Admin cannot create users with elevated roles
- [ ] RestQuery cannot set `subscription_*` or company `status`
- [ ] Passwords never appear in API JSON

## Backups

- [ ] Backup status/run/download require company session + token
- [ ] Restore requires Admin password for **same** company
- [ ] Uploaded restore files reject `.php` / script-like names
- [ ] Restore only affects the authenticated company scope

## Transport / headers

- [ ] HTTPS certificate valid (when enabled)
- [ ] Response includes `X-Content-Type-Options: nosniff`
- [ ] Response includes `Content-Security-Policy`
- [ ] HSTS present on HTTPS responses
- [ ] CSP still allows BanquetDesk CDN scripts (documented exception)

## Rate limiting

- [ ] Repeated failed `/api/auth/login` eventually 429
- [ ] Company register throttled
- [ ] SU login throttled
- [ ] Backup restore throttled

## Dependencies / ops

- [ ] `composer audit` clean (or accepted issues documented) on VPS
- [ ] `npm audit` reviewed if lockfile present
- [ ] Production uses `composer install --no-dev`
- [ ] Default seeded passwords rotated
- [ ] PHPUnit / smoke tests run after deploy
- [ ] Scheduled backup + subscription reminder cron healthy

## Residual / accept risk

- [ ] Team accepts `api/*` CSRF exemption with token model (until Sanctum)
- [ ] Team accepts CSP `unsafe-inline`/`unsafe-eval` for CDN SPA
- [ ] Team tracks migration of RestQuery → explicit policies

## Sign-off

| Role | Name | Date | Notes |
|------|------|------|-------|
| Developer | | | |
| Reviewer | | | |
| Ops / VPS | | | |
