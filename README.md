# AWA AI Agent Consulting — Landing Page Template

A static HTML/CSS landing page template for **AI Agent Consulting service pages**, built for
consultants and digital agencies in the **AWA (AI Workspace Agency)** network who resell AI
agents to SMEs through the AWA whitelabel platform.

This repository is the live source of `aiagents.gtaviani.com` (GTAVIANI — Retail E-Commerce
Growth), released publicly so any AWA-affiliated consultant or agency can **fork it, rebrand it,
and launch their own AI Agent Consulting offer page** without starting from a blank page.

If you're setting up a clone, also read [`CLONE_AGENT_PROMPT.md`](./CLONE_AGENT_PROMPT.md) — a
ready-to-use prompt for an AI coding agent (e.g. Claude Code) to do the rebranding and content
rewrite for you.

---

## What's in the page

Single page (`index.html`), 8 sections in a **fixed order**, designed as a narrative funnel from
problem to conversion:

1. `#hero` — Hero: direct headline + subheadline
2. `#problema` — Problem recognition (why AI adoption stalls for SMEs), with KPI stat strip
3. `#fiducia-faq` — AI Act & GDPR: why compliance is now a business requirement, what an SME
   must do, risk/penalty box
4. `#consulente-esterno` — Why an AI Consultant (vs. a generic tool or dev agency), with a
   results strip
5. `#piattaforma` — The whitelabel AI Workspace platform: dashboard screenshot + feature grid
6. `#piani` — Monthly pricing plans + CTAs (main conversion point)
7. `#faq` — FAQ grouped by category (min. 3 Q&A per category), with `FAQPage` JSON-LD for GEO
8. Footer — brand links, client login, sitemap, privacy

---

## Landing page campagne Ads (optional add-on)

Oltre alla home, il repo include landing page dedicate al funnel **Annuncio → Landing con form
integrato**, una per ogni angolo di campagna Google Ads/Meta Ads (`lp-*.html`, es.
`lp-consulente-ai.html`, `lp-ai-act-gdpr.html`, `lp-chatbot-aziendale.html` in questo clone).
Pattern tecnico, comune a tutte:

- **Header minimo**: solo logo + un link testuale verso la home ("← Scopri di più") — nessun
  menu di navigazione, per non distrarre dalla conversione (best practice PPC)
- **Layout a 2 colonne**: colonna sx con contenuto di convincimento (hero, 3 bullet
  problema/rischio, box "La soluzione"/"La differenza"), colonna dx con il **form Tally integrato
  direttamente in pagina** (`position: sticky`), nessun click-through verso una pagina di
  contatto separata — il lead si lascia senza uscire dalla landing
- **Responsive**: sotto i 900px il layout collassa a colonna singola, il form scende sotto il
  contenuto (ordine DOM, nessun CSS `order` necessario)
- **Un solo form per pagina**, titolo uniforme "Richiedi un Contatto" + una riga che spiega il
  prossimo passo (analisi dell'azienda, non un impegno) — evita di promettere un servizio
  specifico ("audit", "analisi") diverso da quello reale offerto in call
- CSS in `assets/css/style.css`, classi `.lp-*` (`.lp-header`, `.lp-main`, `.lp-layout`,
  `.lp-content`, `.lp-hero-block`, `.lp-risk-list`, `.lp-solution`, `.lp-form-col`,
  `.lp-form-card`, `.lp-footer-minimal`) — condivise da tutte le landing, non duplicare CSS
  per-pagina
- Ogni landing ha il proprio `Organization` JSON-LD (stesso contenuto di quello in home) e meta
  Open Graph/Twitter con **la stessa immagine social della home** (`og-image.png`) ma
  title/description adattati all'angolo di quella landing

Se il tuo clone non ha ancora campagne Ads attive, puoi ignorare questi file o rimuoverli (e i
relativi link in footer + `sitemap.xml`) finché non ti servono.

---

## Quick start: fork and customize

This is a zero-dependency static site — no build step, no framework.

```bash
git clone https://github.com/<your-fork>/gtaviani_aiagents.git
cd gtaviani_aiagents
# edit index.html, assets/css/style.css, assets/images/
```

To preview locally before publishing, open `index.html` directly in a browser, or serve the
folder with any static file server of your choice.

### Customization checklist

- **Brand**: replace logo files in `assets/images/`, update `--color-primary` /
  `--color-secondary` (and any derived shades) in `assets/css/style.css`
- **Copy**: rewrite `<title>`, meta description, canonical URL, and all section content —
  see "What must change" below
- **Pricing**: update the plan cards in section 6 (hours, credits, price, discount)
- **Tracking IDs**: your own GTM container and GA4 property (see Analytics section below)
- **Platform login link**: point to your own AWA whitelabel domain (e.g.
  `https://app.youragency.com`) instead of `aispace.gtaviani.com`
- **Contact**: swap the `mailto:` links and the footer contact for your own

---

## Deploying your customized copy

Since this is static HTML/CSS with no build step, you have two practical options:

### Option A — Dedicated subdomain (recommended, same setup as this repo)

This is how `aiagents.gtaviani.com` itself is deployed:

1. Register a subdomain, e.g. `aiagents.youragency.com`
2. Push this repo (customized) to your own static host — GitHub Pages, Netlify, Vercel, Cloudflare
   Pages, or a plain VPS/cPanel `public_html` folder all work, since there's no backend
3. Point the subdomain's DNS (CNAME or A record, depending on host) to your hosting provider
4. Update `<link rel="canonical">`, the `sitemap.xml` URL, and the `Sitemap:` line in
   `robots.txt` to your new domain

### Option B — Embed into an existing CMS (WordPress, etc.)

If you'd rather add this as a page on a site you already run:

1. Copy everything inside `<body>...</body>` from `index.html` into a custom page template
   (e.g. a WordPress page with a blank/custom template, or a custom HTML block)
2. Upload `assets/css/style.css` and `assets/images/` to your theme or media library, and adjust
   the `<link rel="stylesheet">` path accordingly
3. Move the `<head>` tags (meta, Open Graph, JSON-LD, GTM/GA snippets) into your CMS's page-head
   / SEO plugin fields, since most CMS themes control `<head>` separately from page content
4. Keep the Font Awesome CDN `<link>` (or replace with your own icon library) — the section
   icons depend on it

---

## What NOT to change

These are shared narrative and technical patterns across the whole AWA consultant network —
changing them breaks tracking aggregation or the validated conversion flow:

- **Order and count of the 8 sections** — the funnel (problem → compliance → positioning →
  product → price → FAQ → footer) is validated; don't reorder or remove sections without a
  strong reason
- **CTA id naming convention**: `cta-hero-*`, `cta-plans-*`, `cta-footer-*` — required for
  network-wide GTM/GA rollups
- **Tracking snippet placement**: GTM in `<head>` + noscript right after `<body>`, single ID to
  update in both places
- **JSON-LD schema types**: `Organization` in `<head>`, `FAQPage` before the footer — structure
  only, not the content
- **CSS variable architecture** (`:root { --color-primary; --color-secondary; ... }`) — change
  the values, not the pattern
- **Landing page pattern** (`lp-*.html`): layout 2 colonne con form integrato a destra, header
  minimo (solo logo + link home), un solo form per pagina — vedi sezione "Landing page campagne
  Ads" sopra. Non trasformarle in pagine con click-through a un form esterno

## What MUST change in every clone

- Brand: agency/consultant name, logo, colors, domain
- **All section copy**, especially sections 2 and 4 (problem framing, consultant positioning) —
  rewrite for your own market vertical. Copying this text verbatim between clones creates
  duplicate content and hurts SEO for every site in the network. Section 3 (AI Act & GDPR) is
  generic EU regulation and can be left mostly as-is
- Pricing (section 6) and platform/login links
- FAQ content (section 7) — keep the category structure (min. 3 Q&A per category), rewrite the
  actual questions/answers for your vertical, and update the `FAQPage` JSON-LD to match
- **Landing page copy** (`lp-*.html`, if you use them): rewrite hero/bullets/solution/difference
  for your own vertical and real ad campaign angles — don't reuse the angles or copy shipped in
  this template verbatim, they were written for retail/e-commerce SMEs specifically

---

## SEO & GEO — what's already built in

- Semantic HTML with per-section keyword targeting (headings match the search/GEO intent of
  that funnel stage)
- `Organization` JSON-LD in `<head>` (name, url, logo, `sameAs`)
- `FAQPage` JSON-LD before the footer, generated from the visible FAQ accordion — this is what
  lets AI answer engines (ChatGPT, Perplexity, Google AI Overviews) cite your FAQ content
  directly (Generative Engine Optimization / GEO)
- `sitemap.xml` and `robots.txt` at the repo root
- Open Graph + Twitter Card meta tags with a dedicated 1200×630 social share image
  (`assets/images/og-image.png`)

### What to do after cloning

1. Update `<link rel="canonical">`, `og:url`, `og:image`, `twitter:image` to your domain
2. Regenerate `assets/images/og-image.png` at **1200×630px** with your own brand/copy
3. Update `sitemap.xml` (`<loc>`) and the `Sitemap:` line in `robots.txt` to your domain, then
   submit the sitemap in Google Search Console (and Bing Webmaster Tools)
4. Update the `Organization` JSON-LD (`name`, `url`, `logo`, `sameAs`)
5. Rewrite the `FAQPage` JSON-LD `mainEntity` array to match your rewritten FAQ text exactly —
   mismatched schema vs. visible content can get flagged by search engines
6. Rewrite section 2/4 copy (see "What MUST change") to avoid duplicate-content penalties across
   the network
7. If you use the `lp-*.html` landing pages: update their `<link rel="canonical">`, Open Graph/
   Twitter meta (reuse the same `og-image.png`, just adapt title/description per landing) and
   `Organization` JSON-LD, and add each one to `sitemap.xml`

---

## Analytics & tracking setup

- **GTM**: replace the placeholder `GTM-XXXXXXX` in two places — the script in `<head>` and the
  `<noscript>` iframe right after `<body>` — with your own container ID. This is the single
  source of truth; don't hardcode GA/Ads IDs anywhere else, configure them as tags inside your
  GTM container
- **GA4**: this template ships with a placeholder `G-XXXXXXXXXX` gtag.js snippet in `<head>`.
  Either configure GA4 as a tag inside your GTM container (recommended — one less script to
  maintain) or replace the placeholder ID directly if you're not using GTM for it
- **CTA tracking**: every conversion button already has a stable `id` following the
  `cta-hero-*` / `cta-plans-*` / `cta-footer-*` convention — build your GTM triggers/tags off
  these ids rather than CSS selectors, which may change with a redesign

---

## Stack

Static HTML/CSS, no backend, no build step. Font Awesome (icons) via CDN. Google Fonts not
required (system font stack). Tracking via Google Tag Manager.
