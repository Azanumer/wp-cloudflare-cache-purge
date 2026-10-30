# Cloudflare Cache Purge

Stops the classic "I updated the post but visitors still see the old page"
problem: whenever content changes, this plugin purges Cloudflare's edge cache
automatically.

## What triggers a purge

| Event | Purge |
|---|---|
| Post published or updated | That post's URL (+ homepage, if enabled) |
| Post deleted | That post's URL |
| Comment approved | The commented post's URL |
| Theme switched | Everything (templates and assets all change) |

## Setup

1. Cloudflare dashboard → **My Profile → API Tokens** → create a token with
   **Zone:Cache Purge** permission, scoped to this zone only.
2. **Settings → Cloudflare Purge** — paste the Zone ID (from the zone's
   overview page) and the token.
3. Choose **Changed URLs only** (recommended) or **Everything on every change**.

The settings page also has a **Purge everything now** button and shows the
last purge result (time, success/failure, error message).

## Notes

- URL purges are chunked to Cloudflare's 30-URL limit per request.
- Filter `wccp_urls_to_purge` lets a theme add extra URLs (archive pages,
  feeds) to a purge.
- The API token is stored in `wp_options` and sent only to
  `api.cloudflare.com` — never to any third party.

## License

MIT — see [LICENSE](LICENSE).
