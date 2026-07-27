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
Phase 1 (Foundation) complete: autoloader, schema, encryption, logging, settings,
admin menu shell. Next is Phase 3 in `PLAN.md` section 13 — the real OAuth connect
flow, which is blocked on the connect service and Google API approval.

Done so far: bootstrap, `Install` (5 tables), `Crypto`, `Logger`, `Settings`,
`Google\Connection` (encrypted token storage), `Admin\*` (menu, dashboard,
settings screen with the Connect panel).

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
