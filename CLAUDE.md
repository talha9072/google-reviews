# Reviews Widget for Google Business Profile — Working Notes

## Conventions
- Namespace `GoogleReviews\`, PSR-4 style mapped to `includes/`
- Files named `class-{kebab-name}.php`, one class per file
- All hooks/options/tables/CSS/JS prefixed `gbrw_` / `gbrw-`
- Text domain `google-reviews-widget` on every translatable string
- WordPress Coding Standards, tabs for indentation
- Yoda conditions, no `else` after `return`
- PHP 8.0 minimum — typed properties, parameter types, return types everywhere

## Hard rules
- Every query uses `$wpdb->prepare()`
- Every admin/AJAX/REST handler: nonce + capability check
- Every output escaped at the point of output
- Review text rendered via `esc_html()` only — never raw HTML, never `wp_kses`
- Action Scheduler for all recurring work, never `wp_cron` directly
- **The Google client secret NEVER ships in this plugin.** Token exchange and
  refresh go through the hosted connect service
- No tokens, PII, or full URLs in log messages
- Never mass-delete reviews from a partially failed sync

## Autoloader mapping
`GoogleReviews\Sub\Namespace\ClassName` → `includes/Sub/Namespace/class-class-name.php`
(CamelCase → kebab-case, one class per file, `class-` prefix)

## Architecture
Two deliverables:
1. **This plugin** — runs on the customer's site. Stores tokens (encrypted) and
   reviews in the customer's own database. Calls the Google API directly.
2. **The connect service** — hosted by us, stateless. Holds the Google client
   secret and proxies OAuth code exchange + token refresh. Stores nothing.

Every customer connects their own Google account through our single verified
OAuth app. See `PLAN.md`.

## Rendering
Server-rendered PHP, not a JS widget. Review text must be in the page HTML for
SEO. CSS isolation is by `gbrw-` prefixing + an `all: revert` reset on the root +
CSS custom properties, NOT Shadow DOM (which would hide text from crawlers).
Responsive behaviour uses container queries — a widget in a 320px Divi column on
a 1920px screen must render mobile layout.

## Current phase
Foundation, OAuth, connect service, and the whole widget/display side are done.
Next: `Google\Client`, `Google\Importer`, `Google\SyncScheduler` — pulling real
reviews. Everything downstream of import already works against sample data.

Done: bootstrap, `Install` (5 tables), `Crypto`, `Logger`, `Settings`,
`Google\Credentials`, `Google\OAuth`, `Google\Connection`, `connect-service/`,
`Data\ReviewsRepository`, `Data\WidgetsRepository`, `Widget\SettingsSchema`,
`Widget\SelectionEngine`, `Render\Renderer` (grid/list/carousel/badge),
`Render\Assets`, `Integrations\Shortcode`, `Admin\*` (menu, dashboard, reviews
inbox, widget editor with live preview, settings, sample data).

## Sample data
`Admin\SampleData` loads 14 varied reviews (ratings 3–5, empty-text case, owner
replies, emoji, Arabic, Chinese, French) marked `source = 'sample'`. It exists so
the widget side can be built and demonstrated before Google access is approved.
Offered on the Reviews screen whenever Google is not connected.

## Rendering rules
- Review text goes out through `esc_html()` and nothing else. There is no code
  path that emits review content as markup. Do not add one.
- Settings reach CSS only as custom properties, and `SettingsSchema` accepts
  hex colours and bounded integers only — that is what stops style-attribute
  injection.
- Grid, list and badge widgets load **zero** JavaScript. Only carousel enqueues
  `carousel.js`, and the markup is a usable scroll-snap strip without it.
- Draft and published settings are separate columns. Editing a widget must never
  change what visitors are already seeing.
- Admin-only hints (missing widget, no eligible reviews) are gated behind
  `current_user_can( 'manage_options' )` so visitors never see a config error.

## Two credential modes
`Google\Credentials::mode()` returns:
- `managed` — **the customer path.** `GBRW_CONNECT_SERVICE_URL` points at our
  hosted connect service, which holds the client secret. One click, no Google
  Cloud project on the customer's side. Wins over `own` when configured.
- `own` — the site owner pasted their own client ID/secret. Development and
  advanced users only. **Never assume a customer can do this** — they cannot,
  and expecting it would kill adoption.
- `none` — neither configured; the Connect button stays disabled.

## The connect service
Lives in `connect-service/`, deployed separately to our own HTTPS domain.
Excluded from the plugin zip via `.distignore` and from PHPCS via `phpcs.xml`.
Plain PHP, no framework, no database. See `connect-service/README.md`.

Tokens reach the site through a **one-time ticket**, never through the browser's
address bar. `claim.php` binds each ticket to the originating site origin *and*
the nonce that site generated.

Still missing before selling: licence validation in `claim.php`.

## Redirect URI constraint (own mode only)
Google rejects redirect URIs that are not HTTPS on a real public domain
(`http://localhost` excepted), so `.local` and `.test` are refused.
`Credentials::redirect_uri_usable()` detects this and the settings screen
explains it instead of letting the user hit a Google error page.

**This does not apply in managed mode** — Google only ever sees the connect
service's own callback URL, so local and staging sites connect fine.

## Prefix
`gbrw` / `GBRW`, deliberately 4 characters. WPCS rejects prefixes under 4 chars as
collision-prone, and this plugin ships onto sites running arbitrary other plugins.
Do not shorten it.

## Commands
- Lint: `composer run lint`
- Fix: `composer run lint:fix`
- Analyse: `composer run analyse`

Composer's CLI PHP has no sodium extension, so `composer install` needs
`--ignore-platform-req=ext-sodium`. The site's own PHP-FPM does have it.

## Open items
- Product name, Author, and Plugin URI in the main file header are placeholders
- Connect service URL is a placeholder constant (`GBRW_CONNECT_SERVICE_URL`).
  While it still points at example.com the Connect button stays disabled.
