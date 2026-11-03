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

## 2026-10-31 · Elenco e dettaglio pubblici dei gruppi (US-01)
- Aggiunti `GET /api/campaigns` (ordinato per scadenza) e `GET /api/campaigns/{id}`, pubblici. Il dettaglio mostra scaglioni per soglia crescente, immagini (principale per prima), fornitore, costo della consegna a domicilio (490 centesimi); un id sconosciuto o malformato dà 404 (`CAMPAGNA_INESISTENTE` per un UUID valido).
- Decisioni: campi con i nomi del legacy (`quantita_minima`, `quantita_attuale`, `data_limite`, `stato_campagna`, `scaglioni`…), ma importi in centesimi (`prezzo_corrente_cents`, `prezzo_cents`) invece che in euro con decimali: il client deve dividere per 100. Gli id sono UUID, non interi.
- Differenza dal legacy: in lettura non si scrive nulla. Il legacy ricalcolava e salvava lo stato del gruppo a ogni GET (`ricalcola_stato`); qui `stato_campagna` (in_corso, in_chiusura, conclusa, non_riuscita) si deriva in memoria con `CampaignDisplayStatus` e le transizioni restano a workflow e chiusura (US-11). Un test verifica che le GET non modifichino lo stato.
- Privacy: ospiti e clienti vedono solo il numero di partecipanti (clienti distinti con adesione attiva, escluse annullate e rimborsate: il legacy contava anche quelle), mai nomi o email.
- Query: prodotto, fornitore, categoria e punto di ritiro in una query; scaglioni, immagini e conteggi in una query ciascuno, senza N+1. Senza punto di ritiro proprio vale la sede predefinita (la prima creata).
- Non incluso: valutazioni del fornitore e del gruppo (US-18), `mia_partecipazione` (US-04/US-21), bozze (non esistono tra gli stati), banner ospite (frontend).
- Problema: con più entità Uuid nelle clausole `IN` Doctrine non trovava righe; si usano gli id binari con `IDENTITY()`.

## 2026-11-03 · Crea, modifica ed elimina gruppi (US-10)
- Aggiunti `POST /api/campaigns`, `PATCH /api/campaigns/{id}` e `DELETE /api/campaigns/{id}`, solo admin: senza login 401, con un altro ruolo 403 (regola in `access_control`, quindi vale anche per gli endpoint futuri).
- Creazione: su un prodotto esistente (`prodotto_id`) oppure con prodotto nuovo (`nome`, `id_fornitore`, prezzi). Fino a 10 scaglioni `{soglia, prezzo_cents}`; con gli scaglioni il minimo è la soglia del primo (come `minimo_da_scaglioni`). Errori come nel legacy: minimo < 1, più di 10 scaglioni, prezzi che non scendono, soglie doppie → 400; listino non sopra al primo scaglione → 422 con gli errori per campo.
- Modifica: contano solo i campi diversi dal valore attuale. Minimo, scaglioni, prezzi e commissione si cambiano solo se il gruppo è aperto e senza adesioni (altrimenti 409 `AZIONE_NON_CONSENTITA`); nome e descrizione anche con adesioni; la scadenza con adesioni si può solo prolungare (409 `SCADENZA_NON_ACCORCIABILE`). Gruppo con ordine già partito → 409 `CAMPAGNA_NON_MODIFICABILE`.
- Eliminazione: solo gruppo aperto senza adesioni vive e senza pagamenti, anche rimborsati (409 `HA_PAGAMENTI`).
- Decisioni: importi in centesimi interi (un decimale come 9.5 è rifiutato), id UUID. La regola "cosa si può fare in che stato" è una funzione pura (`CampaignActions`, porta di `AZIONI_PER_STATO`) con test su ogni stato. Il gruppo viene bloccato con `FOR UPDATE` mentre si controlla, per non modificare prezzi mentre qualcuno aderisce.
- Differenze dal legacy: niente bozze (non esistono tra gli stati; quindi niente "pubblica" e "duplica come bozza"), niente upload foto (MV2, US-23), niente registro delle azioni admin (non c'è un'entity), nessun avviso ai partecipanti quando si prolunga (arriva con le notifiche). Gruppo inesistente: sempre `CAMPAGNA_INESISTENTE` (il legacy in modifica usava `CAMPAGNA_NON_TROVATA`).
- Problema: `wrapInTransaction` di Doctrine chiude l'EntityManager se si lancia un'eccezione, e qui un errore di validazione è una risposta normale: uso una transazione manuale con rollback.

## 2026-11-03 · Unisciti al gruppo: acconto su Stripe (US-04)
- Aggiunti `POST /api/payments/deposit-checkout` (crea o riusa la sessione di pagamento dell'acconto, richiede login) e `POST /api/campaigns/{id}/join`, che risponde sempre 410 `FLUSSO_DISABILITATO` come il legacy: l'adesione nasce solo da un acconto pagato.
- Acconto = quantità × prezzo del primo scaglione (senza scaglioni, prezzo corrente) × 20%, in centesimi, minimo 0,50 € (422 `ACCONTO_MINIMO`), calcolato da `PricingCalculator`. Controlli del legacy: gruppo in corso o riuscito (409 `CAMPAGNA_NON_APERTA`), non scaduto (`CAMPAGNA_SCADUTA`), una sola adesione per cliente e gruppo (`GIA_PARTECIPI`), scelta di consegna valida (ritiro nella sede del gruppo, oppure via, CAP e città).
- Stripe sta dietro l'interfaccia `StripeGateway`: `HttpStripeGateway` parla con l'API REST via HttpClient (nessuna libreria nuova, chiave in `STRIPE_SECRET_KEY`, vuota di default) e nei test c'è un finto gateway. Nessuna chiamata reale. Un secondo clic riusa la sessione aperta se importo e scelta sono identici, altrimenti la fa scadere e ne crea una nuova (lock `GET_LOCK` per cliente e gruppo, come nel legacy).
- `DepositConfirmationService` (porta di `webhook_acconto_crea`) trasforma un acconto pagato in adesione: crea adesione, consegna e pagamento, aumenta la quantità del gruppo, porta il gruppo a "riuscita" al raggiungimento del minimo (workflow di PR #20) e conferma le adesioni già prenotate. È idempotente sul PaymentIntent; un secondo acconto dello stesso cliente o un acconto in ritardo vengono rimborsati per intero. Se il rimborso fallisce lancia l'errore, così il webhook (US-07) verrà ripetuto. Il webhook vero (firma, `StripeEvent`) è la US-07.
- Differenze dal legacy: importi in centesimi e id UUID (`colletta_id` resta il nome del campo); la quantità deve essere un intero (il legacy convertiva qualsiasi valore) ma resta limitata a 1-99; `success_url` e `cancel_url` puntano a `FRONTEND_URL/campaigns/{id}`; mancano notifiche ed email al cliente e al fornitore (US-09, US-23); il Customer Stripe è salvato in `users.stripe_customer_id`.
- Anomalia del legacy conservata: chi ha già una riga di adesione, anche rimborsata o annullata, non può riaderire allo stesso gruppo (`GIA_PARTECIPI`, e vincolo unico su gruppo e cliente). Da decidere se è voluto.
- Estratto `TransactionRunner` (transazione manuale al posto di `wrapInTransaction`), ora usato anche dalla gestione admin dei gruppi.

## 2026-11-03 · Frontend del legacy collegato al nuovo backend (prova parziale)
- Il frontend (`_private/frontend`, identico al legacy e fuori da git) è stato copiato in `frontend/` e adattato lì; l'originale non è stato toccato.
- Scelta: un solo punto di adattamento, `frontend/js/compat.js`, invece di riscrivere le pagine. Traduce i percorsi (`/login` → `/api/auth/login`, `/campagne` → `/api/campaigns`, `/pagamento/acconto-checkout` → `/api/payments/deposit-checkout`, `/campagne/{id}/modifica` → `PATCH`), aggiunge ai campi `*_cents` il valore in euro che le pagine leggono, manda i prezzi in centesimi e converte i moduli multipart in JSON (foto ignorate).
- Sessione: il legacy usava un finto "token" in `localStorage`; la sessione vera è il cookie. Ora le chiamate usano `credentials: 'include'` e senza Bearer. L'uscita chiude anche la sessione sul server.
- Backend: `nelmio_cors.yaml` con `allow_credentials: true` e `CORS_ALLOW_ORIGIN` di default `http://localhost:8888` (frontend). Prova con il server PHP: l'elenco gruppi risponde con gli header CORS giusti e la richiesta preflight del login è accettata.
- Id UUID: corretti `consegna-metodi.js` e `dettaglio.js`, che trattavano la sede come numero.
- Limiti noti: le pagine su funzioni non ancora migrate (profilo, notifiche, acquisti, wallet, fornitore, proposte, recensioni, QR) mostrano un errore; l'admin può chiamare create/edit/delete ma l'elenco admin e la scelta del fornitore non esistono; l'adesione non compare dopo il pagamento finché non c'è il webhook (US-07). Le fixture hanno gruppi già scaduti.
- Non verificato: il comportamento nel browser (non ho un browser qui); verificati con test le funzioni di `compat.js` e, con curl, CORS e lista gruppi.
