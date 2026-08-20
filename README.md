# snakecatchingbykev.com.au

One-page website for Snake Catching By Kev (Wonthaggi VIC) — 24/7 snake catching & relocation, Bass Coast & South Gippsland.

## How it's hosted
- Static site on **GitHub Pages** (free). No build step, no framework, no database.
- Everything lives in `index.html` (all CSS inline) + `images/`.

## How to update
1. Edit `index.html` (it's plain HTML with commented sections — phone number, towns, services are all just text).
2. Commit and push to `main`:
   ```
   git add -A && git commit -m "update site" && git push
   ```
3. GitHub Pages redeploys automatically in ~1 minute.

Or just ask Claude Code to make the change — this repo is the whole site.

## Custom domain (once the domain transfer completes)
1. In the domain's DNS, add:
   - `A` records for apex `snakecatchingbykev.com.au` → 185.199.108.153, 185.199.109.153, 185.199.110.153, 185.199.111.153
   - `CNAME` record for `www` → `adacarullo-byte.github.io`
2. In the repo: Settings → Pages → Custom domain → `snakecatchingbykev.com.au`, then tick **Enforce HTTPS** once the certificate issues.
3. Add a `CNAME` file containing `snakecatchingbykev.com.au` to the repo root (GitHub does this automatically when you set the custom domain in settings).

## Assets
Source artwork (full-res mural/logo, photos) lives outside this repo in the project folder.
