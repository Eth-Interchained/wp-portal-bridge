# ⬡ WP Portal Bridge

**Keep WordPress as your backend. Upgrade the website your customers actually see.**

WP Portal Bridge exposes your WordPress content as a signed, versioned **Portal content contract** so the [Portal framework](https://github.com/interchained/Portal) can render your public site — lean, fast, modern, cacheable, SEO-safe — while your team keeps the WordPress admin they already know.

```
WordPress Admin
  ↓
WP Portal Bridge Plugin
  ↓
HMAC-secured Portal Content Contract API
  ↓
Portal Source Adapter (@interchained/portal-source-wordpress)
  ↓
Portal Renderer
  ↓
Fast public website
```

**WordPress owns content editing. Portal owns rendering, routing, layout, performance, deployment, caching, and public UX.**

This is not an exporter. Not React-inside-WordPress. Not a migration tool. It is the secure contract layer between a WordPress backend and a Portal frontend.

---

## How it works

1. Install and activate the plugin.
2. **WordPress Admin → Portal Bridge → Connect Portal Frontend** — generates your `PORTAL_TMK` (Tunnel Master Key) and shows it **once**.
3. Copy the env block into your Portal deployment:

```bash
PORTAL_BRIDGE_BASE_URL=https://cms.example.com
PORTAL_TMK=portal_tmk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

4. Portal detects the env, enables the WordPress source adapter, and renders your public site from WordPress-backed content.
5. Your client keeps editing pages, posts, media, menus, and SEO in WordPress. The public site is Portal.

## What the contract carries

- **Routes** — pages, posts, and public custom post types, with paths preserved **exactly** as WordPress generates them. Authority preservation beats pretty route redesign.
- **Content** — rendered WordPress HTML (shortcodes and blocks resolved), plus plain text. Block-level normalization is a future slice.
- **SEO** — title, meta description, canonical, Open Graph, Twitter, robots — extracted from **Yoast SEO**, **Rank Math**, or **AIOSEO**, with WordPress-core fallbacks for every field.
- **Menus** — registered menus with items, hierarchy, and internal/external classification.
- **Media** — featured images and assets referenced by published content (dimensions, alt text, captions).
- **Taxonomies** — categories, tags, and public custom taxonomies.
- **Sitemap data** — honest `lastmod` from real modification timestamps. Portal renders the XML.
- **Snapshot** — the full contract in one envelope with `snapshotId`, `contentHash`, `routeCount`, `generatedAt`, and chunked pagination for large sites.

### What never leaks

Drafts. Private posts. Password-protected content. Media attached only to private content. Author emails. Admin-only metadata. Published-only, always.

## The secure tunnel

Server-to-server HMAC. No bearer tokens, no cookies, no secrets in URLs, nothing exposed to the browser.

Every request Portal makes is signed with `PORTAL_TMK` (**PORTAL-BRIDGE-V1**):

```
canonical = "PORTAL-BRIDGE-V1" \n METHOD \n PATH_WITH_QUERY \n TIMESTAMP \n NONCE \n BODY_SHA256
signature = hex(hmac_sha256(PORTAL_TMK, canonical))
```

- `PATH_WITH_QUERY` is the raw request target **exactly as sent** — verified against `REQUEST_URI` with no re-encoding or re-ordering on either side.
- Bodyless requests hash the empty string.
- Timestamps must be within ±300s (configurable). Nonces are single-use inside a 10-minute replay window.
- Key rotation keeps the previous key valid for a configurable grace window, so deployed Portals never break mid-rotation. New signatures always use the current key.
- Failed attempts are rate-limited per IP and answered with a **generic 401** — the precise reason is only visible in the admin's internal fail log.
- Responses are signed too (**PORTAL-BRIDGE-RESPONSE-V1**, bound to the request nonce), so Portal can verify integrity end to end.

Both implementations — this plugin (PHP) and the Portal adapter (TypeScript) — are asserted against the same golden vectors (`tests/vectors/hmac-vectors.json`). If either drifts, its test suite fails.

## Endpoints

All under `/wp-json/wp-portal-bridge/v1/`, all signed-only by default:

| Endpoint | Returns |
|---|---|
| `health` | Plugin/version/security status, content version, key ids (never secrets) |
| `site` | Site identity, permalink config, front-page mode, detected SEO plugins |
| `routes?page=&perPage=` | All public renderable routes, paginated |
| `route?path=/about/` | One route by public path |
| `menus` | Registered menus and items |
| `assets?page=&perPage=` | Media referenced by published routes |
| `taxonomies` | Categories, tags, public custom taxonomies |
| `sitemap` | Portal-ready sitemap data |
| `snapshot?page=&perPage=` | Full signed contract envelope (413 + chunk metadata when over the route ceiling) |

## Admin screen

**WordPress Admin → Portal Bridge**: connection status, tunnel status, TMK generate/rotate with one-time reveal, copy-paste Portal env block, snapshot state + content hash, route preview, loop-back **Test Signed Request** (signs a request to its own health endpoint through the real HTTP stack), failed-attempt log, tunnel settings.

## Requirements

- WordPress ≥ 6.0, PHP ≥ 7.4
- Pretty permalinks recommended (the Portal adapter derives `/wp-json/...` endpoint paths)
- `PORTAL_BRIDGE_BASE_URL` must be the canonical origin — redirects break signatures by design

## Development

```bash
# Regenerate golden vectors (canonical algorithm definition)
node tools/generate-vectors.mjs

# PHP conformance against the vectors (no WordPress needed)
php tests/test-auth-vectors.php

# Full security matrix against a live install
WPB_BASE_URL=http://127.0.0.1:8080 WPB_TMK=... WPB_KEY_ID=... \
  node tests/integration/run-security-tests.mjs --draft-path /some-draft/
```

## Roadmap (designed-for, not yet built)

Route remapping UI · redirect generators · SEO parity auditor · Gutenberg block translation · webhook cache invalidation · **NEDB snapshot ledger** (versioned, tamper-evident, replayable content snapshots) · migration/export mode · agency multi-site mode · local SEO classifier · schema builder.

## License

GPLv2 or later. © Interchained LLC.

*Portal framework adapter: [`@interchained/portal-source-wordpress`](https://github.com/interchained/Portal).*
