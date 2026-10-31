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

## 2026-10-31 · Accesso con Google e clienti b2b (US-03, US-02)
- Aggiunto `POST /api/auth/google`: il client invia l'`idToken` ricevuto da Google (scelta B, come dice la US-03). Il token è verificato interrogando l'endpoint `tokeninfo` di Google (firma) e controllando audience (`GOOGLE_CLIENT_ID`), emittente e scadenza. Senza `GOOGLE_CLIENT_ID` il login risponde 503.
- Regole prese dal legacy (`oauth_risolvi` e `oauth_completa`): identità già collegata → accede; stessa email di un account esistente → collega solo se Google garantisce l'email; nessun account → lo crea come cliente solo con il consenso privacy (`PRIVACY_NON_ACCETTATA` altrimenti), con tipo privato o b2b. Account sospeso → 403.
- Differenza dal legacy: il flusso a redirect e il passaggio "collega con password" per email non verificate non ci sono; l'email non verificata riceve 403 `EMAIL_NON_VERIFICATA`. Nessuna dipendenza Composer nuova (HttpClient già presente, nessuna libreria JWT).
- Clienti b2b: nuova colonna `users.company_name` (migrazione `Version20261031100000`, da lanciare anche sul DB di sviluppo), ragione sociale obbligatoria (max 190) e partita IVA italiana validata con cifra di controllo (porta di `piva_normalizza`). Vale per registrazione e primo accesso con Google; per i privati non si salva nulla.
- Resta fuori: l'accesso del fornitore invitato con Google (US-12), Microsoft (US-24).
