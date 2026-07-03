# Gtaviani AI Agents — sito di offerta consulenza AI Agent

Landing page per l'offerta di consulenza AI Agent di **GTAVIANI — Retail E-Commerce Growth**,
rivolta a PMI italiane del settore retail/e-commerce. Piattaforma erogata in whitelabel come
**Gtaviani AI Space**.

Questo repository è **pubblico di proposito**: è il template ufficiale distribuito a tutti i
consulenti/agency della rete AWA come modello da clonare e personalizzare per il proprio brand
e verticale di mercato. Se stai leggendo questo file per creare un clone, vedi anche
[`CLONE_AGENT_PROMPT.md`](./CLONE_AGENT_PROMPT.md).

## Struttura del sito

Pagina unica (`index.html`), 8 sezioni in **ordine fissato**:

1. `#hero` — Hero (curiosità, nessuna spiegazione/offerta rivelata)
2. `#problema` — Il Problema (pain recognition del verticale)
3. `#posta-in-gioco` — La Posta in Gioco (urgenza: mercato, compliance)
4. `#consulente-esterno` — Perché il Consulente Esterno (positioning)
5. `#piattaforma` — La piattaforma whitelabel (accessi, compliance)
6. `#piani` — I Piani (pricing, CTA principale di conversione)
7. `#fiducia-faq` — Fiducia & FAQ (trust + FAQ GEO/JSON-LD)
8. `#cta-footer` — CTA finale + Footer

## Cosa NON toccare in un clone

Questi elementi sono parte dell'architettura narrativa e tecnica del template e vanno mantenuti
identici (o quasi) in ogni fork, per coerenza e per non rompere il pattern di tracking/SEO/GEO
condiviso dalla rete:

- **Ordine e numero delle 8 sezioni** (`index.html`) — non aggiungere/rimuovere/riordinare senza
  una ragione forte, il flusso narrativo (curiosità → pain → urgenza → positioning → prodotto →
  prezzo → trust → CTA) è validato e condiviso da tutti i cloni.
- **Naming convention id dei bottoni CTA**: `cta-hero-*`, `cta-plans-*`, `cta-faq-*`,
  `cta-footer-*` — necessario per il tracking GTM/GA aggregato lato rete.
- **Pattern tecnico del tracking** (container GTM + GA4 in `<head>`, un solo punto di
  aggiornamento ID) — vedi `index.html` head.
- **Schema JSON-LD FAQPage** in sezione 7 — struttura tecnica del markup, non il contenuto delle
  domande (quello va riscritto, vedi sotto).
- **Disclosure di compliance** (badge "GDPR & AI Act by design" in sezione 5/7) — il claim di
  conformità va mantenuto in ogni clone che eroga una piattaforma whitelabel equivalente.
- **Struttura CSS/palette a variabili** (`assets/css/style.css`, `:root { --color-primary; ... }`)
  — cambia i VALORI dei colori, non l'architettura delle variabili.

## Cosa DEVE cambiare in ogni clone

- Brand: nome consulente/agency, logo (`assets/images/`), colori (`--color-primary` /
  `--color-secondary` in `assets/css/style.css`)
- Nome azienda e dominio (title, meta description, canonical, link footer)
- ID tracking: container GTM e property GA4 (mai riusare quelli di un altro consulente/cliente)
- Offerta/pacchetti: nomi piani, ore di consulenza, crediti piattaforma, prezzi (sezione 6)
- **Contenuti testuali delle sezioni 2, 3, 4 e delle FAQ (sezione 7)**: vanno riscritti sul
  verticale di mercato specifico del nuovo consulente. Copiare questi testi identici tra cloni
  penalizza la SEO/GEO di TUTTI i siti della rete (contenuto duplicato) — è il punto più
  importante da rispettare in un clone.
- Link di piattaforma/login cliente (es. `aispace.gtaviani.com` → dominio whitelabel del nuovo
  consulente)
- Iframe del form di contatto esterno (sezione 6/`GTA-FORM-001`)

## Sviluppo locale

Nessuna dipendenza, HTML/CSS statico puro. Per testare in locale:

```bash
python3 -m http.server 9091
# http://localhost:9091/
```

## Stack

HTML/CSS statico, nessun backend. Font: Google Fonts (Inter). Tracking: GTM + GA4 (placeholder
finché non configurati).
