# Google Reviews Widget — Commercial WordPress Plugin
## Implementation Plan (Revised)

Planning document only. No code, migrations, or packages created.
Revised: 2026-07-27, after scope confirmation.

**Confirmed scope**
- A **commercial WordPress plugin**, built once and sold to many clients.
- **WordPress-only rendering** — shortcode, Gutenberg block, Divi and Elementor modules. Server-rendered PHP. No JS loader, no public JSON API, no CDN.
- **Google Business Profile API via OAuth** — full review set, owner replies, stored locally, real filtering and hand-picking.
- Google connection is made **from inside the plugin's own settings screen**. No third-party widget service (no Elfsight, Trustindex, EmbedSocial) anywhere in the stack.

---

## 1. Executive Summary

The plugin is self-contained on the client's site: they connect Google in **Settings → Connect**, pick their location, reviews import into custom database tables, they build a widget in a live-preview builder, and drop `[google_reviews_widget id="1"]` (or the block/module) into any page. Reviews render as real HTML in the page — good for SEO, immune to JavaScript being blocked, and naturally compatible with Breeze, WP Rocket, and every other page cache.

**One piece cannot live inside the plugin, and this is the single most important finding of this revision.**

Because you are *selling* this, every client site needs Google OAuth credentials. There are only two ways to do that, and one of them is broken:

**Each client creates their own Google Cloud project.** Their OAuth app sits in "Testing" mode unless they personally complete Google's sensitive-scope verification — privacy policy, terms, verified domain, demo video, brand verification, several weeks of back-and-forth. And **Google expires refresh tokens from Testing-mode apps after exactly 7 days.** Your customers would have to reconnect Google every week, forever. On top of a 14-day API access wait per customer. This is not a viable product.

**You run one central OAuth application** (recommended, and what every commercial plugin in this category does). You apply to Google once, verify once, and every client connects through it with a single click. This requires a **small "connect service"** — one tiny always-on endpoint that you host — because the Google client secret can never ship inside a distributed plugin.

I also checked whether PKCE could avoid the secret entirely: Google's Web application clients still require a client secret, Desktop clients only permit `localhost` and custom-scheme redirects (no WordPress admin URL), and the out-of-band flow was retired in 2022. There is no way around the proxy.

So the product is **two deliverables**:

| # | Deliverable | Who runs it | Size |
|---|---|---|---|
| 1 | **The WordPress plugin** — everything the customer sees | Customer's site | The bulk of the work |
| 2 | **The connect service** — OAuth proxy + licensing | You | ~300 lines + hosting |

The connect service is deliberately **stateless — it never stores customer tokens or review data.** It holds only your client secret and swaps codes and refresh tokens on demand. Tokens live encrypted on the customer's own site. That keeps your GDPR exposure near zero and gives you a real selling point ("your review data never leaves your server"), matching the zero-telemetry stance in your Cart Rescue plugin.

**Timeline:** ~10–12 weeks to a sellable v1. **Google's approvals are the critical path and must start in week 1** — API access is ~14 days and OAuth sensitive-scope verification commonly takes 4–8 weeks. Build proceeds in parallel behind them.

---

## 2. Current Repository Assessment

### The target repository

`wp-content/plugins/google-reviews/` contains **only `.git`** — zero commits, zero files, no remote. Nothing exists to extend. Every checklist item (framework, autoloader, database layer, auth, API, jobs, tests, build tooling, deployment) must be created.

### Environment — useful as a test bed

| Item | Finding |
|---|---|
| Host | Local by Flywheel, nginx + PHP-FPM + MySQL |
| WordPress | 7.0.2, minimum PHP 7.4 |
| Page cache | **Breeze active**, `WP_CACHE` on — a real cache-compatibility test bed |
| Commerce | WooCommerce, WooCommerce Subscriptions (Stripe SDK vendored) |
| Themes | `picostrap5` + child (Bootstrap 5), `dr-mike-theme`, Twenty Twenty-Three/Four/Five |
| Google libraries | None anywhere in the install |

Divi and Elementor are **not currently installed** — both must be added to this site (or a second Local site) to satisfy the builder test matrix in §11.

### House conventions to inherit

`wp-content/plugins/Abandon-Cart/CLAUDE.md` establishes the standard this plugin should match:

- PSR-4 namespace mapped to `includes/`, files `class-{kebab-name}.php`, one class per file
- Consistent prefix on every hook, option, table, CSS class, and JS handle
- WordPress Coding Standards, tabs, Yoda conditions, no `else` after `return`
- **Every query through `$wpdb->prepare()`**; every AJAX/REST handler gets nonce + capability check
- **Every output escaped at the point of output**
- **Action Scheduler for all recurring work, never `wp_cron` directly**
- No PII in URLs or log messages
- `composer run lint` (PHPCS + WPCS), `composer run analyse` (PHPStan + phpstan-wordpress)

`Company-Formation-plugin/` shows the Composer-vendored dependency pattern already in use here.

### Not relevant

The connected Supabase project `lriicvyhetdnymnsqlvo` holds an unrelated, empty ESG-assessment schema. It plays no part in this product.

---

## 3. Google Access — The Critical Path

Nothing ships until these clear. **Start in week 1.**

### 3.1 What you must obtain

| # | Requirement | Time | Notes |
|---|---|---|---|
| 1 | Google Cloud project | minutes | Owned by your company, not a personal account |
| 2 | **Business Profile API access request** | **~14 days** | Needs a **business-domain email** (gmail.com is rejected) and a **live public website** |
| 3 | OAuth client (Web application type) | minutes | Redirect URI = your connect service callback |
| 4 | **Sensitive-scope verification for `business.manage`** | **4–8 weeks, variable** | Privacy policy, terms of service, homepage on a domain you've verified in Search Console, a demo video walking through the consent flow, and brand verification |
| 5 | Consent screen set to **In Production** | — | **Mandatory.** Testing mode = 7-day refresh token expiry = broken product |

### 3.2 What you need to have ready first

Items 2 and 4 both require assets that do not exist yet:

- A **business domain** and matching email address.
- A **live product website** for the plugin — which you need anyway to sell it.
- A **privacy policy** and **terms of service** on that domain.
- A **demo video** showing a user connecting, granting the scope, and the reviews being displayed.

Google reviewers reject applications with thin or placeholder sites. Build the marketing site properly before applying, not after.

### 3.3 Constraints that follow

**Clients must own or manage the Business Profile they connect.** There is no lawful endpoint returning the full review set for an arbitrary business. If a buyer is an agency wanting to display a client's reviews, that agency must be added as a manager on the profile in Google Business Profile first. **Say this on the sales page** — it will otherwise become your top refund reason.

**Your API quota is shared across every customer.** The default is ~300 queries per minute at the *project* level, and all customer traffic uses credentials issued by your project. A one-location site with 200 reviews costs ~5 requests per full sync. At one sync per day, 300 QPM comfortably supports many thousands of installs — **provided syncs are jittered.** If every install syncs at midnight, you break every customer at once. Every site gets a stable pseudo-random offset derived from its site URL, and a scheduled sync never runs at a fixed hour.

**Concentration risk, stated plainly:** one Google project serves your whole customer base. If it gets suspended or the quota is exhausted, every customer's sync stops simultaneously. Mitigations: quota telemetry on the connect service, alerting well below the ceiling, cadence degradation before the ceiling rather than after, and — crucially — **published widgets keep rendering from the local database when sync fails.** A Google problem must never blank a customer's page.

**Deprecation risk:** the reviews endpoints live on My Business API **v4**, which is deprecated overall with reviews surviving as an explicit exception. Isolate every Google call behind one `Google_Client` class so a future migration touches one file, and watch Google's change log.

---

## 4. Architecture

```
┌──────────────────────────────────────────────────────────────┐
│  YOUR CONNECT SERVICE  (stateless, ~300 lines)               │
│  ├─ GET  /authorize    → redirect to Google, signed state    │
│  ├─ GET  /callback     → exchange code, hand tokens to site  │
│  ├─ POST /refresh      → refresh token → new access token    │
│  └─ POST /license/*    → activate / validate / updates       │
│                                                               │
│  Holds: your Google client_id + client_secret                │
│  Stores: NO tokens, NO reviews, NO customer data             │
└────────────────────┬─────────────────────────────────────────┘
                     │ HTTPS, license-signed
┌────────────────────▼─────────────────────────────────────────┐
│  THE PLUGIN  (customer's WordPress site)                     │
│                                                               │
│  Admin                                                        │
│   Dashboard · Reviews inbox · Widget builder (React)          │
│   Locations · Settings/Connect · License                      │
│                                                               │
│  Core                                                         │
│   Google_Client ──── direct calls to mybusiness.googleapis    │
│   Importer · Sync_Scheduler (Action Scheduler)                │
│   Selection_Engine · Renderer · Cache · Crypto                │
│                                                               │
│  Storage                                                      │
│   {prefix}gbrw_locations · gbrw_reviews · gbrw_widgets           │
│   gbrw_widget_reviews · gbrw_sync_log                           │
│   wp_options: encrypted tokens, settings, license             │
│                                                               │
│  Output                                                       │
│   Shortcode · Gutenberg block · Elementor widget · Divi module│
│   → server-rendered HTML + scoped CSS + optional 5KB JS       │
└──────────────────────────────────────────────────────────────┘
```

**API calls go direct from the customer's site to Google.** Only token exchange and refresh route through your service, because only those need the client secret. Your hosting stays cheap and there is no bottleneck between a customer and their own data.

### Connect flow, end to end

1. Admin clicks **Connect Google** in plugin settings.
2. Plugin generates a nonce, stores it in a transient, and redirects to `your-service/authorize?site=…&license=…&state=…`.
3. Service validates the license, signs its own state binding the return URL, and redirects to Google's consent screen.
4. Customer grants `business.manage`.
5. Google calls back to the service. The service exchanges the code for tokens **and immediately forgets them** after redirecting back to the site's admin with the tokens in a short-lived, signed, single-use payload.
6. Plugin verifies the signature and nonce, encrypts both tokens, stores them in `wp_options`, and moves to location selection.
7. From then on the plugin calls Google directly with the access token, and hits `POST /refresh` only when the access token nears expiry.

Return payload transport must be a **POST back to the site's admin-ajax/REST endpoint over HTTPS, not a URL query string** — refresh tokens must never appear in a URL, browser history, or a server access log. Sites without HTTPS are refused with a clear message.

---

## 5. Database Plan

Custom tables, prefixed `{$wpdb->prefix}gbrw_`. **Reviews must not be a custom post type** — a busy location can hold thousands of reviews and CPT plus postmeta would bloat `wp_posts` and make filtered queries slow. Widgets use a custom table too, for consistency and because config is a single JSON document rather than many meta rows.

**`gbrw_locations`**
`id`, `source_account_id`, `source_location_id` (unique), `business_name`, `address`, `website_url`, `phone`, `google_maps_uri`, `average_rating`, `total_review_count`, `status`, `last_sync_started_at`, `last_sync_completed_at`, `last_sync_status`, `last_sync_error`, `next_sync_at`, `created_at`, `updated_at`

**`gbrw_reviews`**
`id`, `location_id`, `source` (default `google`), `source_review_id`, `reviewer_name`, `reviewer_photo_url`, `reviewer_profile_url`, `star_rating`, `review_text` (LONGTEXT), `review_language`, `source_created_at`, `source_updated_at`, `owner_reply_text`, `owner_reply_updated_at`, `is_hidden`, `is_featured`, `internal_note`, `imported_at`, `last_seen_at`, `deleted_at`, `created_at`, `updated_at`

- Unique `(location_id, source_review_id)` — makes import idempotent by construction
- Indexes: `(location_id, source_created_at)`, `(location_id, star_rating, is_hidden, deleted_at)`
- `utf8mb4` throughout — non-negotiable for emoji and non-Latin reviews
- FULLTEXT on `review_text` for inbox search

**`gbrw_widgets`**
`id`, `name`, `description`, `status` (`draft|published|paused`), `layout_type`, `selection_mode` (`auto|manual`), `settings_json` (LONGTEXT), `published_settings_json` (LONGTEXT), `settings_version`, `published_at`, `created_at`, `updated_at`

Two settings columns give you the draft/publish separation from the brief without a versions table: edits write `settings_json`, publishing copies it to `published_settings_json`, and the front end **only ever reads the published column.** Editing a widget can never change a live page mid-edit.

**`gbrw_widget_reviews`** — `id`, `widget_id`, `review_id`, `display_order`, `is_pinned`. Unique `(widget_id, review_id)`.

**`gbrw_sync_log`** — `id`, `location_id`, `job_type`, `status`, `attempt_count`, `started_at`, `completed_at`, `reviews_imported`, `reviews_updated`, `reviews_removed`, `error_code`, `error_message`, `resume_token`. Retained 90 days.

**Options** — `gbrw_google_connection` (encrypted tokens + expiry + scopes + status), `gbrw_settings`, `gbrw_license`, `gbrw_db_version`.

**Uninstall:** `uninstall.php` drops tables and options **only if** the user ticked "delete all data on uninstall" in settings. Default is to keep data — deleting a customer's imported reviews because they deactivated a plugin briefly is unforgivable.

---

## 6. Google Integration (Plugin Side)

**`Google_Client`** — the only class that talks to Google. Handles auth headers, retries with exponential backoff on 429/5xx, and normalizes errors. All calls via `wp_remote_get`/`wp_remote_post`, never cURL directly.

**Token handling** — access token refreshed via your connect service ~10 minutes before expiry, guarded by a transient lock so two concurrent requests cannot double-refresh. On `invalid_grant`: mark the connection revoked, stop all sync jobs, show a persistent admin notice, email the site admin — **and keep rendering published widgets from the database.**

**Encryption** — `sodium_crypto_secretbox` (libsodium is bundled with PHP 7.2+, no dependency). Key resolution order:
1. `GBRW_ENCRYPTION_KEY` constant in `wp-config.php` if defined (documented as the recommended setup)
2. Otherwise derived via HKDF from `AUTH_KEY` + `SECURE_AUTH_SALT`

A `key_id` marker is stored alongside the ciphertext so rotation is possible. Documented clearly: **rotating WordPress salts invalidates stored tokens and requires reconnecting Google.** Better a documented reconnect than a silent decryption failure.

**Import** — `GET /v4/accounts/{a}/locations/{l}/reviews?pageSize=50`, following `nextPageToken` to completion. Upsert on `(location_id, source_review_id)`. Preserve Google's `createTime`/`updateTime` verbatim; never substitute local time. Store owner replies. Stamp `last_seen_at` on every row seen. On failure, persist the last good page token in `gbrw_sync_log.resume_token` and resume rather than restart.

**Deletion propagation** — after a *complete, successful* sync, any review not seen gets `deleted_at` set and disappears from all widgets. Only ever on a fully successful run: a sync that failed halfway must not mass-delete a customer's reviews.

**Scheduling** — Action Scheduler, matching your house rule. Daily by default, configurable 6h/12h/24h/weekly, plus a manual **Sync now** rate-limited to once per hour per location. Per-site jitter offset. Per-location lock via transient.

---

## 7. Admin UI

Top-level menu **Google Reviews**, `manage_options` capability throughout, with submenus:

| Screen | Contents |
|---|---|
| **Dashboard** | Connection status, location cards, total reviews, average rating, last sync, failed-sync alerts, quick links |
| **Reviews** | The inbox — search, filter by rating / location / language / date / has-text / hidden / selected, sort by newest / oldest / rating / length, bulk hide / feature / add-to-widget, internal notes |
| **Widgets** | List with status, layout, shortcode copy button, duplicate, delete |
| **Widget builder** | The core screen (below) |
| **Settings** | Connect Google, location selection, sync cadence, encryption status, data-on-uninstall, reset |
| **License** | Activation and status |

### The widget builder

A **React app** using WordPress's bundled `wp.element` and `@wordpress/components`, so it looks native and adds no framework to the front end. Three panes: settings accordion on the left, live preview centre, review picker on the right (manual mode only).

**Preview is the real renderer.** The builder POSTs the current settings to a REST route `gbrw/v1/preview`, which runs **the same PHP renderer the front end uses** and returns HTML. That is injected into a same-origin iframe sized to desktop / tablet / mobile. Preview and production cannot drift, because they are the same code path — this directly satisfies "no fake preview implementation separate from the real widget renderer." Debounced ~400ms. The iframe also carries a light/dark background toggle so users can check both.

All states are reachable from a dev dropdown for QA: loading, empty, paused, unpublished, no eligible reviews, connection revoked, invalid settings.

**Settings shape** — one JSON document validated by a single PHP schema class that the builder, the preview route, and the renderer all share. Responsive values are `{desktop, tablet, mobile}` triples where tablet and mobile inherit upward when null. A `settings_version` integer lets old saved widgets keep rendering after a schema change.

---

## 8. Rendering and CSS Isolation

Server-rendered PHP. One renderer class per layout, all extending a base that handles the card, stars, avatar, date, and read-more partials.

**MVP layouts:** Grid, Carousel, List, Rating Badge. **Phase 2:** Slider, Masonry, Floating Badge, Popup, Ticker, Review Wall.

### Isolation without Shadow DOM

Shadow DOM is off the table — it would hide the review text from search engines, which is one of the main reasons to render server-side. The defence is layered instead:

1. **Every class prefixed `gbrw-`**, BEM-style, no generic names.
2. **A reset on the root:** `.gbrw-root *, .gbrw-root *::before, .gbrw-root *::after { all: revert; box-sizing: border-box; }` then our own styles. This neutralizes the aggressive `!important` resets Divi, Elementor, and Bootstrap themes apply to descendants.
3. **CSS custom properties** for every themeable value, set inline on the root from widget settings — so styling never requires a stylesheet rebuild and specificity wars are impossible.
4. **Container queries, not media queries.** A widget in a 320px Divi sidebar column on a 1920px monitor must render its mobile layout; viewport width is the wrong signal entirely. A tiny `ResizeObserver` fallback sets `data-size` attributes for older browsers.
5. **No `!important` in our own CSS** except in the reset block, so customers can still override deliberately.

### Assets

- **CSS**: one stylesheet, enqueued *only* when a widget is present on the page. Detected by scanning post content for the shortcode/block and by a flag set from the Elementor/Divi render callbacks. Late enqueue in the footer for builder-injected cases.
- **JS**: only carousel needs it — ~5KB vanilla, no jQuery, enqueued only when a carousel or slider widget is on the page. Grid, list, and badge widgets ship **zero JavaScript**.
- **Fonts**: none loaded by default. "Inherit theme font" is the default setting.
- **Images**: reviewer photos hotlinked from Google's CDN with `loading="lazy"`, explicit `width`/`height` to prevent layout shift, and an initials-avatar fallback on error.

### Escaping

Review text is output through `esc_html()` with `wpautop()` for line breaks — **never `wp_kses` on raw HTML, never `innerHTML` equivalents.** This makes stored XSS through review content structurally impossible.

### Caching

Rendered HTML stored in a transient keyed by `widget_id + hash(published_settings) + reviews_version`. Invalidated on publish, on sync completion that changed anything, and on manual review hide/feature. Uses the object cache when one is present. Because output is plain server-rendered HTML, Breeze, WP Rocket, LiteSpeed, and Cloudflare all cache the page normally with no exclusions needed — a real advantage of this approach over the JS-embed alternative.

---

## 9. Page Builder Integration

| Target | Integration |
|---|---|
| **Shortcode** | `[google_reviews_widget id="1"]` — works everywhere, including Divi Code Module and Elementor Shortcode widget |
| **Gutenberg** | Native block with a widget picker and server-side `render_callback`, so the editor preview is the real output |
| **Elementor** | A registered Elementor widget under a "Google Reviews" category, loaded only when Elementor is active |
| **Divi** | A Divi module, loaded only when Divi is active; falls back to the Code Module + shortcode if module registration is unavailable |
| **WPBakery / Beaver Builder** | Shortcode, plus a mapped element for WPBakery if demand justifies it |
| **Classic widgets / FSE** | A legacy widget and a block-based template part |

Divi's Visual Builder and Elementor's editor both re-render content via AJAX. Because output is server-rendered per request, both work naturally — the failure mode of the JS-loader approach simply does not exist here.

---

## 10. Licensing and Distribution

**Do not build a licensing system.** Options, in order of my preference for your situation:

| Option | Notes |
|---|---|
| **Easy Digital Downloads + Software Licensing** | Self-hosted on your own store site. No telemetry, no revenue share, full control. Pairs naturally with the connect service you're already running. Most work upfront. |
| **Lemon Squeezy** | Merchant of record — they handle EU VAT and global sales tax, which is a genuine headache otherwise. Clean licensing API. Small revenue share. |
| **Freemius** | Fastest to market, handles free/pro tiers, payments, licensing, and updates. **But it phones home**, which contradicts the zero-telemetry stance in your Cart Rescue plugin. Flagging the inconsistency; your call. |

Update delivery goes through the same service. If the plugin is ever listed on WordPress.org, the free tier must not include the OAuth proxy dependency in a way that violates their guidelines — worth checking before that route is taken.

**Free vs Pro split** (suggestion, easy to change): Free gets 1 location, 1 widget, Grid and List layouts, automatic selection, daily sync, and a small "Powered by" link. Pro gets unlimited locations and widgets, all layouts, manual and pinned selection, hourly sync, full styling controls, CTA buttons, and branding removal.

---

## 11. Testing Plan

**Unit (PHPUnit + Brain Monkey)** — the selection engine is the highest-value target: rating filters, text-required, length thresholds, language filter, ordering, manual order, pinned-then-fill, responsive value resolution, date formatting across locales. Plus encryption round-trip, token refresh, import deduplication, and cache key derivation.

**Integration (WP test suite + MySQL, Google mocked at the HTTP layer via `pre_http_request`)** — OAuth callback including tampered state, location import, multi-page review pagination, resume-after-failure, deletion propagation, sync idempotency (running twice changes nothing), Action Scheduler registration, publish/draft separation, cache invalidation.

**End-to-end (Playwright against the Local site)** — connect Google (mocked), import, create widget, choose layout, restyle, publish, insert shortcode, verify front end, edit and republish, verify the page updates.

**Builder matrix (the suite most likely to find real bugs)** — Divi frontend, Divi Visual Builder, Elementor frontend, Elementor editor, Gutenberg editor and frontend, plain shortcode in a classic theme, two widgets on one page, widget inside a narrow column. **Divi and Elementor must be installed on this Local site first.**

**Theme matrix** — the picostrap5 Bootstrap child theme already here (a good adversarial case), Twenty Twenty-Five, Astra, and one deliberately aggressive theme.

**Cache plugins** — Breeze (already installed), WP Rocket, LiteSpeed, Autoptimize, Cloudflare. Expected to be uneventful given server-rendered output, but must be verified rather than assumed.

**Compatibility** — PHP 7.4 through 8.4, WordPress 6.0 to current, MySQL and MariaDB, multisite.

**Accessibility** — axe-core, keyboard pass through the carousel, NVDA and VoiceOver smoke tests, `prefers-reduced-motion` disabling autoplay, and a **contrast warning inside the builder** when a user picks a failing colour pair, which prevents far more problems than an audit afterwards.

---

## 12. Security Plan

| Area | Control |
|---|---|
| Client secret | **Never in the plugin.** Lives only on the connect service |
| Token storage | `sodium_crypto_secretbox`, key from `wp-config.php` constant or HKDF from WP salts, `key_id` for rotation |
| Token transport | POST over HTTPS to a REST endpoint, signed and single-use. Never in a URL or query string. HTTP sites refused |
| Every admin action | Nonce + `manage_options` check, no exceptions |
| Every query | `$wpdb->prepare()`, no exceptions |
| Every output | Escaped at the point of output |
| Review content XSS | `esc_html()` only; no raw HTML path exists |
| User content | CTA labels and headers escaped; URLs validated against an `https?:` allowlist; `rel="noopener noreferrer"` on outbound links |
| Custom CSS | **Deferred to Phase 2 and gated behind a property allowlist** rejecting `url()`, `@import`, `expression`, `behavior`. If it can't be made safe, it doesn't ship |
| Connect service | License-signed requests, rate limiting, no token or review storage, structured logs with token redaction |
| Logs | No PII, no tokens, no full URLs with query strings |

---

## 13. Delivery Phases

**Phase 0 — Google and business setup (week 1, then waiting)**
Register the business domain and email. Build the product marketing site with privacy policy and terms. Create the Google Cloud project. **Submit the Business Profile API access request.** Record the demo video and **submit OAuth sensitive-scope verification.** Everything below proceeds in parallel while these are pending.

**Phase 1 — Foundation (week 1–2)**
Plugin scaffold, autoloader, activation/deactivation, database schema and migration runner, encryption utilities, logger, settings framework, Composer with PHPCS/PHPStan, CI.

**Phase 2 — Connect service (week 2–3)**
The four endpoints, license validation, deployment, monitoring. Small and self-contained.

**Phase 3 — Google integration (week 3–5)**
Connect flow end to end, encrypted token storage, refresh and revocation, account and location listing, initial import with pagination and resume, Action Scheduler sync with jitter and locks, deletion propagation, sync log and admin surfacing.

**Phase 4 — Reviews inbox (week 5–6)**
List table, all filters and sorts, search, hide/feature, internal notes, bulk actions.

**Phase 5 — Widget builder (week 6–8)**
Widgets schema, settings schema class, React builder, live preview via the real renderer, automatic and manual selection, draft/publish.

**Phase 6 — Rendering (week 8–10)**
Renderer base and the four MVP layouts, scoped CSS and reset, container queries, carousel JS, transient caching, shortcode, Gutenberg block, Elementor widget, Divi module, full builder and theme test matrix.

**Phase 7 — Ship (week 10–12)**
Licensing integration, update delivery, onboarding wizard, in-plugin docs, i18n with full POT, accessibility pass, PHP/WP compatibility matrix, marketing site, launch.

**Phase 8 — Post-launch**
Slider, masonry, floating badge, popup, ticker; hybrid pinned-then-fill selection; sanitized custom CSS; multi-location combined widgets; analytics; review reply from inside WordPress (the API supports it and no competitor does it well).

---

## 14. Risks and Open Questions

### Risks

| Risk | Severity | Mitigation |
|---|---|---|
| **Google denies API access or OAuth verification** | **Critical — no product** | Apply week 1; real website, real policies, clear demo video; budget for one round of clarifications |
| Verification takes longer than the build | High | Expected, honestly — plan for it rather than being surprised. Development finishes behind the approval either way |
| Shared quota across all customers | Medium at scale | Jittered scheduling, quota telemetry, alert below the ceiling, degrade cadence proactively |
| Project suspension breaks all customers at once | Medium | Widgets always render from the local database; sync failure is invisible to site visitors |
| My Business v4 sunset | Medium | One `Google_Client` class; monitor Google's change log |
| Customers don't own their Business Profile | Medium — commercial | State the requirement prominently on the sales page and in onboarding |
| Connect service downtime | Low–Medium | Only affects new connections and refreshes; access tokens last an hour and widgets render regardless. Keep it boring and monitored |

### Open questions

1. **Plugin name and prefix.** I've used `gbrw_` / `GRW` as a placeholder throughout. A real product name is needed early — it goes into the namespace, table names, option keys, CSS classes, and text domain, and changing it later is a migration.
2. **Where will the connect service run?** It needs to be always-on and reliable. A small VPS, Cloudflare Workers, or a serverless function all work. Rough cost: $5–20/month.
3. **Licensing platform** — EDD, Lemon Squeezy, or Freemius (§10). Affects Phase 7 and the telemetry question.
4. **PHP floor** — 7.4 to match WordPress's minimum, or 8.0+ for typed properties and cleaner code? 8.0+ excludes a shrinking minority of hosts. I'd suggest 8.0.
5. **Free tier?** Affects licensing choice and whether a WordPress.org listing is in scope.
6. **Legal check on review filtering.** Hiding genuine reviews and filtering to 4-star-plus is standard across every competitor and requested in your brief, but selectively displaying only positive reviews carries consumer-protection exposure in some jurisdictions independent of Google's terms. My plan builds the capability, defaults it off, and requires an explicit opt-in. **This needs a lawyer's opinion, not mine.**

---

## 15. File Plan

Nothing created yet. Names use the `gbrw` prefix (4+ chars, required by WPCS) pending a final product name.

```
google-reviews-widget.php          bootstrap, constants, activation hooks
uninstall.php                      conditional data removal
composer.json                      PHPCS + WPCS + PHPStan (mirrors Abandon-Cart)
package.json                       builder React app + esbuild for carousel JS
readme.txt                         WordPress-format readme

includes/
  class-autoloader.php
  class-plugin.php                 bootstrapping and hook wiring
  class-install.php                dbDelta schema, version upgrades
  class-logger.php
  class-crypto.php                 sodium encrypt/decrypt, key resolution
  class-settings.php

  Google/
    class-client.php               ONLY class that calls Google
    class-connection.php           token lifecycle, refresh, revocation
    class-importer.php             pagination, upsert, deletion propagation
    class-sync-scheduler.php       Action Scheduler registration, jitter, locks

  Data/
    class-locations-repository.php
    class-reviews-repository.php
    class-widgets-repository.php

  Widget/
    class-settings-schema.php      shared by builder, preview, renderer
    class-selection-engine.php     ← highest-value unit-test target
    class-cache.php

  Render/
    class-renderer.php             base: card, stars, avatar, date, read-more
    class-layout-grid.php
    class-layout-carousel.php
    class-layout-list.php
    class-layout-badge.php
    class-assets.php               conditional enqueue

  Integrations/
    class-shortcode.php
    class-block.php
    class-elementor-widget.php
    class-divi-module.php

  Admin/
    class-admin-menu.php
    class-reviews-list-table.php
    class-builder-page.php         mounts the React app
    class-rest-controller.php      builder + preview + sync endpoints
    class-notices.php

  License/
    class-license-manager.php
    class-updater.php

assets/
  css/widget.css                   scoped, prefixed, container queries
  js/carousel.js                   ~5KB vanilla, conditional
  admin/                           React builder source + build output

languages/google-reviews-widget.pot
tests/{unit,integration,e2e}/
```

Separate repository for the connect service (~300 lines): `/authorize`, `/callback`, `/refresh`, `/license/*`.

---

## 16. Approval Checkpoint

No code written, no packages installed, no migrations created. Only this document.

**The two things worth your attention before I start:**

**Google's approvals are the critical path, not the code.** API access is ~14 days; OAuth sensitive-scope verification is commonly 4–8 weeks and needs a live website, privacy policy, terms, and a demo video that don't exist yet. If that work starts today, it finishes around when the plugin does. If it starts when the plugin is done, you sit idle for two months.

**You will need to host one small service.** It is unavoidable for a distributed plugin — the Google client secret cannot ship inside plugin files, and the alternative (each client running their own Google project) means weekly reconnections for every customer because Testing-mode refresh tokens expire after 7 days. It is a genuinely small piece of work; it just can't be zero.

**To begin Phase 1 I need:** the product name and prefix (question 1), and the PHP floor (question 4). Everything else can be settled while building.
