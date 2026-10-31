# JOURNEY

Diario del percorso di riscrittura di BuyPool in Symfony, in ordine cronologico.

## 2026-10-30 · Analisi del legacy e piano di migrazione
- Letto il legacy (`_private/legacy-src/api`, ~11.000 righe PHP, 22 tabelle) e scritti `docs/MAPPA-LEGACY-SYMFONY.md` e `docs/PIANO-MIGRAZIONE.md`.
- Decisione: il progetto resta un'API JSON; ordine di lavoro guidato dalla demo del flusso completo (MV1) entro il 19 novembre.
- Problema: la cartella `scripts/` dei cron non è stata copiata nel legacy; il comportamento si ricava da `campagne.php` e `notifiche_eventi.php`.

## 2026-10-30 · Servizio prezzi e ripartizione (US-04, US-07, US-11)
- Aggiunto `App\Pricing\PricingCalculator`: acconto (20% del primo scaglione × quantità, minimo 0,50 €), saldo con voci arrotondate al centesimo (merce, commissione, spedizione, acconti), scaglione raggiunto, minimo del gruppo, prezzo fissato alla chiusura.
- Aggiunto `App\Campaign\Application\QuantityAllocator`, porta di `ripartisci()`.
- Decisioni: tutto in centesimi interi; arrotondamento "half up" in aritmetica intera al posto dei float del legacy; percentuale acconto e costo consegna a domicilio sono parametri (`app.pricing.*`), 20 e 490 centesimi (4,90 € dal `config.php` legacy, confermato).
- Verifica: un test confronta 20.000 casi casuali con la formula float del legacy (`saldo_dettaglio`) e i valori coincidono al centesimo.
- Differenza voluta: nel ripartitore i resti si confrontano con interi esatti, non con float, così a parità vince davvero l'adesione più vecchia.
- Non portati: aumento prezzo, `costo_buypool`, barra di avanzamento (non servono alle US).

## 2026-10-30 · Registrazione, login e logout con email (US-02, US-03)
- Aggiunti `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`. Registrazione e login aprono una sessione con cookie (HttpOnly, SameSite lax), come nel legacy.
- Decisioni: stessa forma di risposta del legacy (`{ok, dati}` / `{ok:false, errore:{codice, messaggio}}`), stessi nomi dei campi e dei codici errore (`PRIVACY_NON_ACCETTATA`, `EMAIL_GIA_USATA`, `CREDENZIALI_NON_VALIDE`, …) perché il parity-first (ADR 0002) vale anche per il contratto con il client. Regole password del legacy: 8-128 caratteri, una lettera e un numero (più severe della US-02, che chiede solo 8).
- Correzione di sicurezza rispetto al legacy: limite ai tentativi di login (5 ogni 15 minuti). L'account sospeso riceve 403 solo dopo aver indovinato la password.
- Non fatto in questo blocco: login con Google (vedi sotto), clienti b2b (ragione sociale e partita IVA: manca la colonna `companyName`), chiusura della sessione di un utente sospeso già collegato (US-19), token CSRF (il legacy aveva `X-CSRF-Token`).
- Scoperta: il legacy non verifica un token inviato dal client. Usa il flusso a redirect (`/auth/google/start` e `/callback`, con scambio del codice e verifica della firma tramite JWKS), mentre la US-03 e FR-04 parlano di "Google identity token". Da decidere prima di scrivere il login con Google.
- Decisioni: tutto in centesimi interi; arrotondamento "half up" in aritmetica intera al posto dei float del legacy; percentuale acconto e costo consegna a domicilio sono parametri (`app.pricing.*`), 20 e 490 centesimi (4,90 € dal `config.php` legacy, da confermare).
- Verifica: un test confronta 20.000 casi casuali con la formula float del legacy (`saldo_dettaglio`) e i valori coincidono al centesimo.
- Differenza voluta: nel ripartitore i resti si confrontano con interi esatti, non con float, così a parità vince davvero l'adesione più vecchia.
- Non portati: aumento prezzo, `costo_buypool`, barra di avanzamento (non servono alle US).
