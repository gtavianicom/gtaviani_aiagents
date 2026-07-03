# Prompt per agente AI — clonare questo template per un nuovo consulente

Copia il blocco qui sotto e forniscilo a un agente AI (es. Claude Code) con accesso al repo
clonato, dopo aver risposto alle domande nella sezione "Dati da raccogliere prima di iniziare".

---

## Dati da raccogliere prima di iniziare

Prima di lanciare l'agente, raccogli queste informazioni dal nuovo consulente/agency:

1. **Brand**: nome commerciale, logo (orizzontale + icona/favicon), colore primario e
   secondario (hex)
2. **Verticale di mercato**: settore/nicchia target (es. "PMI manifatturiere", "studi legali",
   "hotel & hospitality" — non generico)
3. **Nome piattaforma whitelabel**: come viene chiamata la piattaforma AI agent verso il cliente
   finale (equivalente di "Gtaviani AI Space" in questo template), e dominio di login clienti
4. **Pacchetti/pricing**: nomi piani, ore di consulenza/mese, crediti piattaforma/mese, prezzo/
   mese per ciascun piano, sconto su acquisti extra fuori piano
5. **Dominio** del nuovo sito e repo GitHub di destinazione
6. **ID tracking**: container GTM e property GA4 (o placeholder se non ancora disponibili)
7. **Form di contatto**: iframe o link esterno da integrare in sezione 6 (Piani)
8. **Dati/statistiche di settore** da citare in sezione 2 (Il Problema) e 3 (La Posta in Gioco) —
   se non forniti, l'agente deve cercarli autonomamente da fonti verificabili (es. istituti di
   statistica, report di settore) e citarli con fonte

## Prompt da fornire all'agente

```
Sei un agente di sviluppo frontend. Devi clonare e personalizzare il sito
gtaviani_aiagents (repo: gtavianicom/gtaviani_aiagents) per un nuovo consulente/agency
della rete AWA, mantenendo intatta l'architettura narrativa e tecnica del template.

REGOLA FONDAMENTALE: prima di scrivere codice, leggi README.md di questo repo — spiega
cosa NON toccare (struttura 8 sezioni, naming CTA, pattern tracking, schema JSON-LD,
disclosure compliance) e cosa DEVE cambiare (brand, contenuti sezioni 2/3/4/FAQ, pricing,
tracking ID).

Dati del nuovo consulente:
- Brand: [NOME], colori: primario [HEX] secondario [HEX], logo: [PATH/URL]
- Verticale: [SETTORE/NICCHIA TARGET]
- Piattaforma whitelabel: [NOME PIATTAFORMA], login clienti: [DOMINIO]
- Piani: [TABELLA NOME/ORE/CREDITI/PREZZO/SCONTO PER OGNI PIANO]
- Dominio sito: [DOMINIO]
- GTM: [ID o placeholder] — GA4: [ID o placeholder]
- Form contatto: [IFRAME/LINK]
- Dati di settore per sezione 2/3: [STATISTICHE CON FONTE, o "cerca autonomamente"]

Compiti:
1. Sostituisci logo, palette colori (assets/css/style.css :root), title/meta/canonical,
   link footer con i dati del nuovo consulente.
2. Riscrivi INTEGRALMENTE i contenuti testuali delle sezioni 2 (Il Problema), 3 (La Posta
   in Gioco), 4 (Perché il Consulente Esterno) sul verticale di mercato indicato — NON
   copiare i testi originali, il contenuto duplicato penalizza la SEO/GEO di tutta la rete.
   Mantieni la stessa struttura narrativa (pain recognition → urgenza → positioning) e lo
   stesso tono assertivo/diretto in seconda persona.
3. Aggiorna sezione 5 (piattaforma) con il nome whitelabel del nuovo consulente, mantenendo
   il claim di compliance GDPR/AI Act.
4. Aggiorna sezione 6 (Piani) con i nuovi pacchetti/prezzi. Mantieni: prezzi arrotondati per
   eccesso senza decimali, nessun valore annuale visibile, CTA tipo "Richiedi informazioni"
   (mai "Acquista ora"), naming id `cta-plans-*`.
5. Riscrivi la FAQ (sezione 7) con obiezioni tipiche del nuovo verticale — mantieni lo
   schema JSON-LD FAQPage, contenuto delle domande unico (non copiato da questo template).
6. Aggiorna ID tracking GTM/GA4 in head, iframe del form in sezione 6.
7. Aggiorna README.md di conseguenza (brand, dominio nel testo di esempio).
8. NON modificare: ordine/numero delle 8 sezioni, naming convention id CTA, pattern
   tecnico del tracking, struttura schema JSON-LD, architettura delle variabili CSS.

Verifica finale prima di commit: nessun riferimento al brand/verticale originale
(gtaviani/AWA/retail-ecommerce) è rimasto nel codice o nei contenuti pubblici.
```
