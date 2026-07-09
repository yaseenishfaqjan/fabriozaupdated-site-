# FABRIOZA — fabrioza.com

Production deployment for **fabrioza.com**, an ISO 9001 certified custom clothing
manufacturer (Sialkot, Pakistan) — custom sportswear, streetwear, womenswear,
kids, scrubs, intimate apparel and sustainable fashion with 50-piece MOQ.

## Structure

- `dist/` — the complete static site as served in production (React SPA homepage
  + 12 prerendered collection pages + 50 blog posts + `.htaccess` routing)
- `Dockerfile` / `docker-compose.yml` — the deploy unit: Apache + PHP 8.3 with
  the exact module set the `.htaccess` needs (rewrite, headers, expires, deflate)
- `CLAUDE.md` — architecture notes, URL conventions, deploy gotchas

## Deploy (VPS)

```bash
git clone <this repo> && cd fabrioza-deployment-v3
docker compose up -d --build   # serves on port 8080
```

Put an SSL-terminating reverse proxy (Caddy / nginx / Traefik) in front of
port 8080. It must forward `X-Forwarded-Proto: https` (all three do by default).

After DNS points at the VPS: visit `/indexnow.php` once and resubmit
`sitemap.xml` in Bing Webmaster Tools + Google Search Console.

## Conventions (do not break)

- Canonical URLs have **no trailing slash** and no `index.html` suffix
- All redirects live in `dist/.htaccess` as `RewriteRule` — never use
  `Redirect`/`RedirectMatch` there (mod_alias is dead code in that context)
- Team zips are stale snapshots: diff and merge only new content, never
  extract over `dist/`
