# Mappa legacy → Symfony

Il legacy (PHP senza framework) è in `_private/legacy-src/api` (fuori da git, solo lettura). Questa mappa dice dove finisce ogni funzione nel progetto Symfony (API JSON). Le decisioni di schema sono in `_private/legacy/05b-model-decisions.md` (denaro in centesimi, UUID v7, enum PHP backed).

Stato: **fatto** = esiste già; **da fare**; **non serve** = non si porta.
Le issue sono le US di `docs/01-analysis/user-stories.md` (milestone GitHub: MV1 MVP, MV2 Complete, MV3 Enhancement; nel file sono chiamate M1/M2/M3).

## Convenzioni di nome

| Legacy | Symfony | Nota |
|---|---|---|
| `collette` / "campagna" | `Campaign` | testi per il cliente: "gruppo" |
| `prenotazioni` | `Participation` | testi: "adesione" |
| `scaglioni_prezzo` | `PriceTier` | |
| `pezzi` | `quantity` | testi: "unità" |
| `ordini_fornitore` | `SupplierOrder` | |
| `qr_codes` | `PickupToken` | |
| `eventi_stripe` | `StripeEvent` | idempotenza webhook |
| importi DECIMAL(10,2) | interi `*Cents` + `currency` | conversione `round(x*100)` |
| `collette.percentuale_commissione` | `commissionRate` DECIMAL(5,2) | resta tasso |

## Funzioni

| Area legacy | File legacy (righe) | Equivalente Symfony | Stato | US |
|---|---|---|---|---|
| Entity e schema (22 tabelle) | `schema.sql` | `src/<Contesto>/Entity`, 6 migrazioni | fatto | – |
| Macchina a stati gruppo | `lib/stato.php` (92), `lib/stato_lavoro.php` (168) | `Campaign\Application\CampaignTransitioner`, Workflow | fatto (PR #20) | US-11 |
| Calcoli prezzo/saldo | `lib/prezzi.php` (131) | `Pricing\PricingCalculator` (funzioni pure, centesimi) | da fare | US-04, US-07 |
| Ripartizione quantità | `lib/ripartizione.php` (47) | `Campaign\Application\AllocationService` | da fare | US-11 |
| Regole gruppi | `lib/gruppi_regole.php` (54) | validator + `CampaignGuards` | parziale | US-10 |
| Registrazione, login, sessione | `endpoints/auth.php` (100), `lib/auth.php` (141) | `Security` (json_login), `Identity\Controller\AuthController`, `RegistrationService` | fatto (b2b incluso; sospensione di sessione già aperta: no) | US-02, US-03 |
| Google OAuth | `lib/oauth.php` (503), `endpoints/oauth.php` | `POST /api/auth/google` (token dal client, non redirect): `Identity\Google\TokenInfoGoogleIdTokenVerifier`, `GoogleLoginService`, `ExternalIdentity` | fatto | US-03 |
| Microsoft OAuth | `lib/oauth.php` | idem, verifica token | da fare | US-24 |
| Reset password | `endpoints/password.php` (157) | `PasswordResetToken` + Messenger/Mailer | da fare | US-17 |
| Profilo | `endpoints/profilo.php` (184) | `ProfileController` | da fare | US-22 |
| Export dati / cancellazione account | `endpoints/utenti.php` (266) | `PrivacyController` | da fare | US-15, US-16 |
| Lista e dettaglio gruppi | `endpoints/campagne.php` (1763, parte lettura) | `CampaignController` (pubblico) | da fare | US-01 |
| CRUD gruppi admin | `endpoints/admin_gruppi.php` (437) | `Admin\CampaignAdminController` | da fare | US-10 |
| Catalogo e prodotti | `catalogo.php`, `prodotti.php` | `CatalogController` | da fare | US-21 |
| Adesione con acconto | `endpoints/partecipazioni.php` (142), `pagamento.php` (1409) | `Participation\JoinService`, `PaymentController` | da fare | US-04 |
| Ritiro adesione prima del minimo | `partecipazioni.php`, `campagne.php` | `WithdrawService` | da fare | US-05, US-25 |
| Stripe: checkout, webhook, rimborsi | `pagamento.php`, `pagamenti.php` (142) | `Payment\StripeGateway` (interfaccia + mock), `StripeWebhookController` | da fare | US-04, US-07 |
| Chiusura gruppo e saldo | `campagne.php` (chiusura), `lib/prezzi.php` | `Campaign\Application\CloseCampaignService` + comando/Scheduler | da fare | US-11 |
| Saldo fallito 48h/24h | `campagne.php` (~1185-1300), `notifiche_eventi.php` | `FailedBalanceHandler` + Scheduler | da fare | US-26 |
| Scelta consegna e spedizione | `endpoints/consegne.php` (178) | `DeliveryController` | da fare | US-06, US-09, US-20 |
| Ritiro con QR | `endpoints/ritiro.php` (95) | `PickupController`, `PickupToken` | da fare | US-08 |
| Area fornitore (attivazione, ordini, pagamenti) | `fornitori.php` (553), `fornitore_*.php`, `lib/fornitore_*.php` | `Supplier\…Controller` | da fare | US-12 |
| Proposte e voto | `proposte.php` (345), `admin_proposte.php` (379), `lib/proposte_regole.php` | `Proposal\…` | da fare | US-13, US-14 |
| Recensioni | `recensioni.php` (208) | `Review` (entity nuova, non c'è) | da fare | US-18 |
| Sospensione utenti, admin utenti/fornitori/messaggi | `admin_messaggi.php`, `utenti.php` | `Admin\UserAdminController` | da fare | US-19, US-23 |
| Notifiche in-app | `notifiche.php`, `lib/notifiche_eventi.php` (823) | `Notification\NotificationService` | da fare | US-09, US-23 |
| Email | `lib/mail.php` (214, PHPMailer) | Symfony Mailer + Messenger | da fare | vari |
| Impostazioni | `lib/impostazioni.php` | parametri `config/services.yaml` (ore saldo, spedizione, acconto %) | da fare | US-06 |
| Cron | `lib/cron.php` + `scripts/` (non copiati) | Symfony Scheduler | da fare | US-11 |
| Upload immagini | `lib/immagini.php` (255) | `ProductImage` + servizio upload | da fare (MV2) | US-23 |
| Dashboard admin/cliente | `admin_dashboard.php`, `dashboard.php`, `acquisti.php`, `lib/acquisti.php` | `DashboardController` | da fare | US-21 |
| Aumento prezzo, `costo_buypool`, consigliati, IBAN fornitore, wallet | `lib/aumento.php`, `consigliati.php`, `fornitore_azienda.php` | – | rimandato / non serve | US-27 |
| `dev.php`, `.env`, `config.php` | – | – | non serve | – |

## Valori di configurazione ereditati dal legacy
`acconto_percent` 20 · minimo checkout 0,50 € · `saldo_termine_ore` 48 · `saldo_promemoria_ore` 24 · `costo_spedizione` 4,90 (confermato, parametro `app.pricing.home_delivery_cents` = 490) · `ritiro_giorni_max` 10 · `fornitore_conferma_ore` 72.

## Contratto API ereditato dal legacy
Risposte `{ "ok": true, "dati": … }` e `{ "ok": false, "errore": { "codice", "messaggio", "campi"? } }` (classi `Shared\Http\ApiResponse` e `ApiError`). Nomi dei campi e codici errore restano quelli del legacy (italiano: `nome`, `cognome`, `ruolo`, `stato`, `CREDENZIALI_NON_VALIDE`…).
