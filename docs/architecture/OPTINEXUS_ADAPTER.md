# OptiNexus adapter (wave 1C/1D, 2026-10-24)

OptiDeli runs in one of two identity modes from the same code, chosen by `OPTIDELI_IDENTITY_MODE`:

| | `local` (default) | `optinexus` |
|---|---|---|
| Tenant user sign-in | password (`POST /auth/login`) | OIDC at OptiNexus only; password login answers 403 |
| Permissions | local roles (`roles`, `membership_roles`) | `POST /authorization/effective-permissions` at OptiNexus |
| Role and member writes | Akses & role page | 409, managed in OptiNexus (the page shows them read-only) |
| Superadmin | local platform login | the same local platform login (break-glass) |

The code follows the OptiEntry adapter (`OptiAccounting/docs/architecture/OPTINEXUS_ADAPTER.md`) and lives in
`backend/app/Domain/Integration/Optinexus/`. Settings: `config/optideli.php` key `optinexus` (see `.env.example`).

## 1. Sign-in

Authorization code + PKCE S256. `state`, `nonce` and verifier stay in the server cache under the hash of `state` (single use,
10 minutes). The `id_token` is verified with `firebase/php-jwt` against the JWKS (RS256, `kid`), then `iss`, `aud`, `exp`, `iat`,
`nonce`, and cross-checked with `/userinfo`. Flow: `GET /api/v1/auth/sso/redirect` -> OptiNexus -> `GET /api/v1/auth/sso/callback`,
which opens the normal session cookie and sends the browser to the SPA. Nothing secret travels in a URL. A refusal lands on the SPA
as `/?sso_error=<code>` and the login page explains it. `GET /api/v1/auth/sso/status` tells the login page which doors exist.

Sign-out (`POST /auth/logout`) of an SSO session also returns OptiNexus' `end_session_endpoint` (with `id_token_hint`), and the SPA
goes there, so the next visit is not signed straight back in.

## 2. Linking (never from an unverified claim)

- Tenant: `tenants.optinexus_tenant_id`, set by the Superadmin (`POST /api/v1/platform/tenants/{id}/optinexus`, "Tautkan" in the
  console). A sign-in for an unlinked tenant is refused (`tenant_not_linked`); tenants are never created from a claim. In
  `standalone` deployment only the configured tenant can be used.
- The `apps`/`groups` claim must contain the application code (`access_denied` otherwise).
- User: `users.optinexus_subject` (= `sub`). Without a subject, a **verified** e-mail may claim an existing local account that has
  no subject and is not a platform operator; anything else is `account_conflict`. New users get a random, never shown password.
- Membership: created on first sign-in (`role` label `optinexus`, no local roles). Roles are not copied.

## 3. Permissions and module entitlement

One call per tenant + member: keys `optideli.<code>` are accepted only when `<code>` is in the local catalog; wildcards, other
applications' keys and unknown codes are dropped; an answer about another user, tenant, application or schema is refused. A
permission of a sellable module (`PermissionCatalog::MODULES`: `optideli.enterprise`, `optideli.vendor_portal`) is kept only when
the module is in `entitled_capabilities`, so module entitlement is enforced server-side. The answer is cached at most 300 s (never
longer than OptiNexus' `ttl_seconds`). If OptiNexus cannot answer once the cache has expired, tenant requests get 503 (fail closed,
no local fallback). A sign-in with no permission is refused (`no_permissions`).

## 4. Sessions and Back-Channel Logout

`POST /api/v1/integration/optinexus/backchannel-logout` (form `logout_token`, CSRF-exempt, authenticated by the signed token):
`typ logout+jwt`, signature, `iss`, `aud`, `iat`/`exp`, the logout event, `sub`, no `nonce`, `jti` replay protection. Answers 200
(also for an unknown user), 400 for an invalid token, 503 when the JWKS cannot be fetched (OptiNexus retries).

- Logout with `session_ids`: those OIDC sessions end (the `sid` is kept in the session; revoked sids are cached for the session
  lifetime).
- Logout without session ids, or access revoked: `users.session_epoch` is raised, which ends every session of the user on its next
  request (every session stores the epoch at sign-in; `SessionState::valid`).
- Access revoked, `scope=tenant`: the membership of that tenant is deactivated with `optinexus_deactivated_at`; `scope=user`: the
  account (`users.active=false`, `optinexus_deactivated_at`). The next successful sign-in undoes exactly that, nothing else.
- A password session left over from before switching to `optinexus` ends on its next request.

## 5. Manifest and registration (operator)

```
php artisan optideli:nexus:manifest --output=optideli-manifest.json          # in OptiDeli, no secret inside
php artisan ecosystem:import-manifest optideli optideli-manifest.json --create-application   # in OptiNexus
php artisan ecosystem:register-oidc-client optideli --redirect-uri=<APP>/api/v1/auth/sso/callback \
  --post-logout-uri=<SPA>/ --launch-url=<SPA> --backchannel-logout-uri=<API>/api/v1/integration/optinexus/backchannel-logout \
  --secret-file=<0600 file>
php artisan ecosystem:register-service-account optideli --secret-file=<0600 file>   # scope authorization.check
```

Then run the OptiNexus bootstrap seeder again (platform roles pick up the new permissions), assign the application to the tenant,
give users access and an OptiNexus role with `optideli.*` permissions, link the tenant in the OptiDeli Superadmin console, and set
`OPTIDELI_IDENTITY_MODE=optinexus` plus the `OPTINEXUS_*` variables. Delegable: every permission except `vendor.portal` and
`access.role.manage`/`access.user.manage`.

### Tenant created by OptiNexus (webhook)

Without this, a person whose OptiNexus tenant has no OptiDeli tenant is refused at sign-in (`tenant_not_linked`: "Organisasi Anda belum
ditautkan ke OptiDeli") until a Superadmin creates the tenant and links it. OptiNexus now publishes `cgo.tenant.application_assigned` when
a subscription assigns OptiDeli to a tenant (`OptiNexus/docs/ecosystem/PLATFORM_EVENTS.md`); OptiDeli receives it on the existing
`POST /api/v1/integration/optinexus/events` (same signature check as the OptiFleet availability events) and `TenantProvisioner` creates the
tenant already linked to the OptiNexus tenant:

* slug from the tenant's name (`Str::slug`, lower case words joined by single dashes, a numeric suffix on a clash, "tenant" when nothing is left),
  name = the OptiNexus tenant name (its code when the name is blank), active, default margin, the system roles of the tenant (like the console);
* idempotent and additive: a tenant that is already linked is left as it is (a Superadmin's edits survive), a concurrent delivery cannot create
  two (the link is unique), and nothing is deleted or suspended when OptiDeli is later removed from the tenant;
* events about other applications or other types, a tenant id that is not a UUID, and standalone deployments (one fixed tenant) are
  acknowledged with `ignored`; the audit log records `platform.tenant_provisioned_by_optinexus`.

**Needs `OPTIDELI_DEPLOYMENT_MODE=saas`.** A `standalone` deployment serves one fixed tenant (`OPTIDELI_STANDALONE_TENANT`), so nothing is
created there (a notice is logged) and any other tenant is refused at sign-in anyway.

Setup, once per installation: `OPTINEXUS_EVENT_WEBHOOK_SECRET` is the secret of the OptiNexus event subscription of `optideli` for the key
`cgo.tenant.application_assigned` (`php artisan ecosystem:register-event-subscription optideli cgo.tenant.application_assigned
--url=<deli>/api/v1/integration/optinexus/events --secret-file=<0600 file>`; https unless `EVENT_WEBHOOK_ALLOW_HTTP=true`, local trial only).
Tests: `TenantProvisioningTest`.

## 6. Not in this wave

Delegation API (tenant users and roles managed from the OptiDeli UI), product switcher / product-context, event relay to the
OptiNexus broker (wave 2), vendor-bound memberships created from OptiNexus (vendor portal stays local).
