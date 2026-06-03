# Member Inbox — Operator Handoff

Slices 2a → 2oo shipped 2026-05 / 2026-06. This document is for the
next operator (human or AI) picking up after a context reset. Read this
top-to-bottom before touching anything in `inc/inbox-*.php`,
`assets/inbox-app.*`, or `k8s/email-mta-image/`.

## 1 · What it is

An end-to-end self-hosted email inbox built inside the existing
`email-manager` WordPress plugin. Each container site gets its own MX
domain. Members read + send through wp-admin → Email Manager → Inbox.
Zero per-message fees: outbound goes through Google Workspace SMTP
Relay (free tier, 10k/day per user); inbound terminates at a Haraka
MTA pod we run inside the cluster.

## 2 · Architecture at a glance

```
   ┌──── Internet ────┐
   │  sender@x.com    │  SMTP :25
   └────────┬─────────┘
            │ (recipient: anyone@<container-domain>)
            ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ email-mta pod (Haraka 3.0.5)                                 │
   │   - haraka plugins: webhook_forwarder.js, http_submitter.js  │
   │   - mailauth (SPF/DKIM/DMARC)                                │
   │   - GCS attachment offload (WI: email-mta-sa@gend-me)        │
   │   - Workspace SMTP Relay :587 (outbound)                     │
   └────────┬─────────────────────────────────────────────────────┘
            │ HMAC-SHA256 webhook
            ▼
   ┌──────────────────────────────────────────────────────────────┐
   │ WordPress (container site, or hub gend.me)                   │
   │   inbox-webhook.php        → wp_gdc_inbox_raw (1 row/msg)    │
   │   inbox-threading.php      → wp_gdc_inbox_threads + _messages│
   │     fires em_inbox_thread_created / em_inbox_message_inserted│
   │       priority 10  inbox-user-provisioning (stamp owner)     │
   │       priority 20  inbox-participants (default unread)       │
   │       priority 30  inbox-contacts (auto-extract)             │
   │       priority 35  inbox-filters (rules engine)              │
   │       priority 40  inbox-vacation (auto-reply)               │
   │   inbox-rest-list.php      → GET /em/v1/inbox/threads, search│
   │   inbox-send.php           → POST send + scheduled + cancel  │
   │   inbox-outbound-queue.php → cron retry/scheduled drain      │
   │   inbox-labels.php         → custom labels                   │
   │   inbox-tracking.php       → open-tracking pixel             │
   │   inbox-signature.php      → per-user HTML signature         │
   │   inbox-sanitizer.php      → wp_kses + remote-image block    │
   │   inbox-idempotency-ledger → de-dupe webhook deliveries      │
   │   inbox-diagnostics.php    → admin sanity dashboard          │
   │                                                              │
   │ wp-admin React UI: assets/inbox-app.{js,css}                 │
   └──────────────────────────────────────────────────────────────┘
```

Container sites POST their inbound webhooks to themselves (each
container is its own inbox). The hub (gend.me) runs the same plugin
identically — admins reading `/wp-admin/admin.php?page=email-manager-inbox`
see only their own inbox.

## 3 · Database tables

All in the active site's WordPress DB, prefixed with `wp_`:

| Table | Purpose | Owner module |
|---|---|---|
| `wp_gdc_inbox_raw` | Raw incoming + outgoing messages, one row each | inbox-webhook.php (inbound), inbox-send.php (outbound mirror) |
| `wp_gdc_inbox_threads` | One row per conversation, JWZ-merged | inbox-threading.php |
| `wp_gdc_inbox_messages` | Link table: thread_id ↔ raw_id with thread position | inbox-threading.php |
| `wp_gdc_inbox_participants` | Per-(thread, user) state: read/archived/trashed/starred/snoozed_until | inbox-participants.php |
| `wp_gdc_inbox_labels` | User-defined labels (name + color) | inbox-labels.php |
| `wp_gdc_inbox_thread_labels` | M:N label ↔ thread, scoped by user | inbox-labels.php |
| `wp_gdc_inbox_contacts` | Auto-extracted from inbound/outbound for autocomplete | inbox-contacts.php |
| `wp_gdc_inbox_ledger` | Webhook event_id ledger for exactly-once delivery | inbox-idempotency-ledger.php |
| `wp_gdc_inbox_opens` | Open-tracking events keyed by HMAC token | inbox-tracking.php |
| `wp_gdc_inbox_vacation_log` | Dedup for auto-reply (RFC 3834 guards) | inbox-vacation.php |
| `wp_gdc_inbox_filters` | Per-user filter rules | inbox-filters.php |
| `wp_gdc_inbox_grants` | Per-(owner, grantee) read/read_send delegation | inbox-grants.php |
| `wp_gdc_inbox_drafts` | Composer auto-saved drafts (out of threading) | inbox-drafts.php |

DB-version options (gate migrations; bump in code → next request runs ALTER):

```
em_inbox_part_db_version       = 1.3.0  (slice 2aa adds snoozed_until)
em_inbox_outq_db_version       = 1.1.0  (slice 2bb adds 'scheduled' enum)
em_inbox_filters_db_version    = 1.0.0  (slice 2cc initial)
em_inbox_grants_db_version     = 1.0.0  (slice 2ee initial)
em_inbox_drafts_db_version     = 1.0.0  (slice 2kk initial)
em_inbox_labels_db_version     = ...    (see inbox-labels.php)
em_inbox_contacts_db_version   = ...
em_inbox_ledger_db_version     = ...
em_inbox_opens_db_version      = ...
em_inbox_vacation_db_version   = ...
```

If a migration appears stuck, suspect PHP **opcache** — run:
```
kubectl exec -n <ns> <pod> -- wp --allow-root eval 'opcache_reset();'
```

## 4 · REST API surface (`em/v1/inbox/...`)

All require `is_user_logged_in()` unless noted.

```
POST   /webhook/receive                 HMAC-signed, no auth (slice 2a)
GET    /inboxes
GET    /threads?inbox=&page=&per_page=&unread|archived|trashed|starred|snoozed|scheduled|label_id    inbox=* = unified across every readable inbox (slice 2oo)
GET    /threads/{id}
GET    /search?q=&inbox=
POST   /threads/{id}/read|unread|archive|unarchive|trash|restore|star|unstar|unsnooze
POST   /threads/{id}/snooze            body: {until: ISO8601 future}
POST   /threads/{id}/delete-forever    (DELETE method also accepted)
POST   /threads/bulk                   body: {action, thread_ids}
PUT    /threads/{id}/labels            body: {label_ids: [int]}
GET/POST/PUT/DELETE /labels
POST   /send                           body: {to, cc, bcc, subject, body_plain, body_html, thread_id?, track_open?, undo_seconds?, send_at?, attachments?}
DELETE /messages/{raw_id}/cancel       cancel pending OR scheduled
GET    /scheduled                      list current user's scheduled outbound
GET    /contacts?q=
GET    /signature
POST   /signature                      body: {html}
GET    /vacation
POST   /vacation                       body: {enabled, start_at, end_at, subject, body_plain, body_html}
GET/POST/PUT/DELETE /filters
POST   /filters/{id}/test              body: {from, to, subject, body} → {match: bool}
GET    /unread-count?inbox=             {unread, total, latest_at} — bell polls every 30s
GET/POST/PUT/DELETE /drafts/{id?}      composer auto-saves here every ~1.5s on idle
GET    /grants                         {given: [...], received: [...]}
POST   /grants                         body: {grantee_email, scope: read|read_send, expires_at?}
DELETE /grants/{id}                    either party can revoke
POST   /track/{token}.gif              open-tracking pixel
```

## 5 · Outbound delivery model

Three states:

| `delivery_status` | When | Drained when |
|---|---|---|
| `pending` | Composer pressed Send with `undo_seconds > 0` (default 10s) | `delivery_next_attempt_at <= NOW()` (i.e. after undo window expires) |
| `scheduled` | Composer chose "Schedule send" with `send_at` future timestamp | `delivery_next_attempt_at <= NOW()` (when scheduled time arrives) |
| `retrying` | First relay attempt failed; under backoff | Backoff: 2m/5m/15m/30m/1h/3h/12h, max 8 attempts → `failed` |

Single cron at `em_inbox_outq_cron` (every 60s — `em_inbox_minute` schedule) runs `em_inbox_outq_drain()`.

Both pending + scheduled rows transition through the same path as retrying. Cancel works on pending AND scheduled (purges the mirror row + its messages + thread if last).

### SMTP allowlist (USER-TO-DO)

Workspace SMTP Relay requires the cluster's egress IPs to be allowlisted in `admin.google.com` → Apps → Google Workspace → Gmail → Routing → SMTP Relay Service. Until this is done, **every real outbound send will fail with `550 5.7.0 Mail relay denied`** and end up in `retrying` then `failed`. The smoke test bypasses relay so it still passes.

Current cluster egress IPs (subject to change):
- `35.193.156.109`
- `35.223.73.162`
- `136.119.133.245`

## 6 · Webhook contract (MTA → WP)

```
POST <site>/wp-json/em/v1/inbox/receive
Headers:
  Content-Type: application/json
  X-EM-Webhook-Timestamp: <unix-secs>
  X-EM-Webhook-Signature: sha256=<hex>           = HMAC-SHA256(secret, ts + "." + body)
  X-EM-Event-ID: <opaque uuid>                   for ledger dedupe
Body:
  {
    message_id, from, to: [...], subject,
    headers: [{name, value}, ...],
    body_plain, body_html,
    attachments: [{filename, content_type, size, gcs_key|content_b64}, ...],
    spf: {pass: bool, raw}, dkim: {pass: bool, signers}, dmarc: {pass: bool, policy}
  }
```

`em_inbox_hmac_secret` option is set by hand once (in admin → Email Manager → Inbox → Diagnostics, "Rotate secret"). The same secret signs outbound submissions to `http://email-mta-submit:8080/submit`.

## 7 · Authentication of inbound (SPF/DKIM/DMARC, slice 2w)

`webhook_forwarder.js` runs `mailauth` and forwards verdicts. The thread reader UI shows the badge (✓ verified / ⚠ partial / ✗ failed). Failures don't reject; they're advisory. To fully reject failed DMARC, add a Haraka plugin gate before `webhook_forwarder`.

## 8 · Attachments

Both inbound and outbound bounce through GCS bucket `gs://gend-me-email-attachments/` with object key `inbox/<container-domain>/<yyyy>/<mm>/<message-id>/<filename>`. Workload Identity ties the MTA KSA to `email-mta-sa@gend-me.iam.gserviceaccount.com` which has `roles/storage.objectAdmin` on the bucket only.

The base64 fallback (`content_b64` in the webhook payload) is what the smoke test exercises. Real attachments come back as `gcs_key`.

## 9 · DKIM

RSA 2048 keys live in K8s Secret `email-dkim-keys` in each cluster wp namespace, mounted at `/etc/dkim/<domain>.key`. The public selector record is in Cloud DNS:
```
selector1._domainkey.<container-domain>  TXT  "v=DKIM1; k=rsa; p=<pubkey>"
```
Selector name is `selector1`. Rotate by minting a new key, adding a new selector record (`selector2`), updating Haraka config, then removing the old selector record after 7 days propagation.

## 10 · Front-end (assets/inbox-app.js)

Single file React app via `wp.element` + `htm` (NO build step). One App component splits into Sidebar / Composer / FeedView / ThreadView / overlays. State held entirely in `useState` + `useEffect` — no Redux/Recoil.

Key sub-components:
- `Composer` — modal, attachments via FileReader.readAsDataURL → base64, Send dropdown for scheduled send (slice 2bb)
- `FeedView` — left pane, filter tabs (all/unread/starred/snoozed/scheduled/archived/trashed), `j`/`k` keyboard nav (slice 2x)
- `ThreadView` — right pane with messages
- `SnoozeButton` — per-thread snooze picker (slice 2aa)
- `ScheduledList` — Scheduled tab body (slice 2bb)
- `VacationModal` — auto-reply config (slice 2z)
- `FiltersModal` — rules CRUD (slice 2cc)
- `ManageLabels` / `ThreadLabelPicker` (slice 2r.1)
- `ContactTokenField` — FormTokenField wired to `/contacts?q=` (slice 2t)

CSS is plain stylesheet `assets/inbox-app.css`. No PostCSS / Tailwind.

## 11 · Plugin sync from local repo → cluster

The wordpress pods have an `initContainer plugin-sync` that rsyncs `gs://gend-me-plugins/<plugin-slug>/` → `/var/www/html/wp-content/plugins/<plugin-slug>/`. **Quirk** (see memory `project_plugin_sync_quirk.md`): rsync does NOT overwrite existing files in the PVC — so a live-patched copy stays even after a fresh image build. Workaround: `kubectl cp` to the running pod for hotfixes, build a new image for permanent changes.

Trigger an image build:
```
gcloud builds submit --config cloudbuild.yaml .
```
The image is published as `us-central1-docker.pkg.dev/gend-me/wp/wordpress:base` (mutable tag — pods re-pull on restart). Registry project is `gend-me`, NOT `gend-prod` (see memory `project_image_build.md`).

## 12 · Running the smoke test

```
kubectl exec -n <wp-ns> <wp-pod> -- wp --allow-root eval-file \
  /var/www/html/wp-content/plugins/email-manager/bin/inbox-smoke-test.php
```

Expected output ends with `PASS: 67   FAIL: 0`. Exits non-zero on any fail. Run after any schema migration, any change to webhook/threading/participants/filters/outbound queue. Coverage spans:

- schema versions (3 migrators)
- inbound threading (insert + JWZ reply stitch)
- participant state (read/star/snooze/unsnooze)
- listing + counts (snoozed key included)
- labels CRUD + thread attach
- contact auto-extract
- search
- HTML sanitizer (<script> strip + remote-image block)
- filters engine (create + e2e trigger + match_count bump)
- outbound queue (scheduled send + /scheduled list + cancel)
- vacation responder loaded
- signature endpoint
- diagnostics + idempotency + tracking module presence
- grants/delegation CRUD + permission check before & after revoke (slice 2ee)

The script bypasses actual SMTP relay, so it passes even before the Workspace allowlist is in place.

## 13 · Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `There has been a critical error on this website` on the inbox page | Often a fatal in `inbox-*.php` — check `wp-content/debug.log` | Tail the pod log: `kubectl logs -n <ns> <pod>` |
| Inbox shows "Loading inbox…" forever | JS parse error in `inbox-app.js` | Check browser console — past culprits: minified htm wrapper, missing escape |
| Sent message stuck in `retrying` | SMTP allowlist not in place (most common) | Add cluster IPs to Workspace SMTP Relay; failed rows auto-retry within ~12h |
| `/threads` returns 0 items as admin without inbox filter | Pre-existing query bug — extra_where attaches to JOIN ON when WHERE is empty | UI always passes inbox; for ops use, pass `?inbox=<addr>` |
| Migration option exists but ALTER not applied | OPcache cached the old DB_VERSION constant | `kubectl exec ... wp eval 'opcache_reset();'` |
| Reply not stitched to its parent thread | Missing/broken `In-Reply-To` or `References` header | Verify on raw row's `raw_headers` JSON — re-thread via `em_inbox_thread_one($raw_id)` |
| Filter doesn't fire on inbound | Filter disabled, or hook order broken, or kind != 'inbound' | Check `wp_gdc_inbox_filters.enabled`; `match_count` bumps if it fires |
| Webhook returns 401 | HMAC mismatch — secret rotated on one side only | Re-sync both Haraka config + `em_inbox_hmac_secret` option |
| Same message appears twice | Idempotency ledger missed | Check `X-EM-Event-ID` is being sent + `wp_gdc_inbox_ledger` has the event |

## 14 · Deferred polish (out of scope of milestone)

Each slice left small TODOs intentionally:

- **2s** (tracking): pre-insert raw_id (UUID) so the first send carries the tracking pixel without needing a retry round-trip
- **2w** (auth): hard-bounce DMARC failures (currently advisory-only)
- **2cc** (filters): only one match mode (AND across conditions); add OR/group; add "stop processing further filters" action; backfill apply-to-existing
- **2bb** (scheduled): no recurring sends, no per-user timezone resolution beyond browser locale
- **2aa** (snooze): no per-message snooze, no smart resurface time
- **2y** (undo): if `track_open=true` and the first attempt SUCCEEDED, the recipient's copy won't have the pixel (raw_id post-insert)
- **2ee** (delegation): filters fire only on the OWNER's user_id (so a grantee won't trigger their own filter rules on someone else's incoming mail — intentional but worth noting). Composer From-dropdown UI shipped as slice 2hh.

## 15 · Memory pointers

Living context in `~/.claude/projects/.../memory/`:

- `project_member_inbox_architecture.md` — top-level summary of this build
- `project_oauth_architecture.md` — per-user gend.me tokens (used by container WP for member identity)
- `project_image_build.md` — Cloud Build pipeline
- `project_plugin_sync_quirk.md` — the rsync no-overwrite gotcha

---

Last verified: 2026-06-02 — slices 2jj/2kk/2ll/2mm/2oo unverified on cluster (gcloud auth expired during 2jj build); JS validates clean via `node --check`, smoke schema asserts updated. 2oo adds inbox='*' merged view to /threads + /unread-count; UI prepends "— All inboxes —" option when user has >1 inbox; per-row origin chip rendered when inbox='*'. Run `bin/inbox-smoke-test.php` after every change.
