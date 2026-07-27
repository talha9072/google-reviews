# Connect Service

This is **not** part of the WordPress plugin. You deploy it once, to a domain you
own, and every customer site talks to it. It exists for one reason: the Google
client secret cannot ship inside a plugin you distribute.

With this running, a customer's entire setup is:

> Install plugin → click **Connect Google** → approve on Google's screen → done.

No Google Cloud project. No API keys. No 14-day wait. That is all handled once,
by you, here.

## What it does and does not hold

| | |
|---|---|
| **Holds** | Your Google client ID and client secret |
| **Holds briefly** | Tokens, for up to 3 minutes, under a one-time ticket |
| **Never holds** | Customer reviews, customer credentials, long-term tokens, any customer database |

Refresh tokens live encrypted on each customer's own WordPress site. This service
only lends them the client secret needed to use one. If this service is offline,
existing widgets keep rendering — only new connections and token refreshes pause.

## Requirements

- PHP 8.0+ with cURL
- HTTPS on a real public domain (Google will not accept anything else)
- No database, no framework, no Composer

## Install

1. Upload this folder somewhere public, e.g. `https://connect.yourdomain.com/`.
2. Copy `config.example.php` to `config.php` and fill it in.
3. Generate the state secret:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
4. Make sure `tickets/` is writable by PHP and **not** readable over HTTP.
5. Confirm `config.php` returns 403 or 404 in a browser. If it returns a blank
   page, that is fine (PHP executed it); if it shows your secret, stop and fix
   the web server before going further.

## Google Cloud setup (once, by you)

1. Create a Google Cloud project.
2. **APIs & Services → Credentials → Create credentials → OAuth client ID →
   Web application.**
3. Under **Authorised redirect URIs** add exactly one entry:
   ```
   https://connect.yourdomain.com/callback.php
   ```
   Customer sites are never listed here. That is the whole point — their URLs,
   including `.local` and staging domains, never reach Google.
4. Copy the client ID and secret into `config.php`.
5. **OAuth consent screen → Publish app.** Leaving it in Testing makes Google
   expire every refresh token after 7 days, which would force all your customers
   to reconnect weekly.
6. Complete **sensitive scope verification** for
   `https://www.googleapis.com/auth/business.manage`. Expect 4–8 weeks. Until
   this is done, customers see an "unverified app" warning.
7. Separately, apply for **Google Business Profile API access**. Roughly 14 days.
   Without it, connecting succeeds but importing reviews returns 403.

## Point the plugin at it

In each customer's `wp-config.php` — or, better, baked into the build you ship:

```php
define( 'GBRW_CONNECT_SERVICE_URL', 'https://connect.yourdomain.com' );
```

When this constant is absent or still points at `example.com`, the plugin falls
back to asking for the site owner's own Google credentials, which is the
developer path, not the customer path.

## Endpoints

| Endpoint | Method | Purpose |
|---|---|---|
| `authorize.php` | GET | Customer site sends the owner here; redirects to Google |
| `callback.php` | GET | Google's single registered redirect URI |
| `claim.php` | POST | Customer site redeems its one-time ticket for tokens |
| `refresh.php` | POST | Swap a refresh token for a fresh access token |

## How a connection actually flows

1. Plugin generates a nonce, stores it, and sends the browser to
   `authorize.php?site=…&nonce=…&return=…`.
2. Service signs that into an HMAC state and redirects to Google.
3. Customer approves. Google redirects to `callback.php`.
4. Service verifies the state, exchanges the code for tokens using the client
   secret, parks them under a random 32-byte ticket, and redirects the browser
   back to the customer's site with **only the ticket** in the URL.
5. The site POSTs to `claim.php` server-to-server with the ticket, its own
   origin, and the nonce it generated. All three must match.
6. Service returns the tokens once, deletes the ticket, and forgets everything.
7. Plugin encrypts the tokens and stores them locally.

Tokens never travel through the browser's address bar, so they cannot end up in
history, referrer headers, or server access logs.

## Security notes

- The `return` URL must be on the same origin as `site`, checked in both
  `authorize.php` and `callback.php`. Without it this would be an open redirector.
- State is HMAC-signed and expires after 15 minutes.
- Tickets are single-use, expire in 3 minutes, and are stored under
  `sha256(ticket)` so the filename does not reveal the ticket.
- Claims are bound to both the originating site origin and its nonce, compared
  with `hash_equals`.
- Basic per-IP rate limiting on `authorize`, `claim`, and `refresh`.

## Still to add before selling

- **Licence validation.** `claim.php` currently accepts any site. Add a licence
  check there so only paying installs can connect.
- Move `tickets/` outside the web root if your host allows it.
- Structured logging and uptime monitoring.
