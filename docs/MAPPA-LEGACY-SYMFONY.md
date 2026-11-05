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
| Ripartizione quantità | `lib/ripartizione.php` (47) | `Campaign\Application\QuantityAllocator` (pronto); nel legacy `ripartisci()` non è usata da nessun endpoint rimasto: il passaggio "ordine pronto" è la transizione `allocate` fatta dalla chiusura | fatto (funzione pura) | US-11 |
| Regole gruppi | `lib/gruppi_regole.php` (54) | validator + `CampaignGuards` | parziale | US-10 |
| Registrazione, login, sessione | `endpoints/auth.php` (100), `lib/auth.php` (141) | `Security` (json_login), `Identity\Controller\AuthController`, `RegistrationService` | fatto (b2b incluso; sospensione di sessione già aperta: no) | US-02, US-03 |
| Google OAuth | `lib/oauth.php` (503), `endpoints/oauth.php` | `POST /api/auth/google` (token dal client, non redirect): `Identity\Google\TokenInfoGoogleIdTokenVerifier`, `GoogleLoginService`, `ExternalIdentity` | fatto | US-03 |
| Microsoft OAuth | `lib/oauth.php` | idem, verifica token | da fare | US-24 |
| Reset password | `endpoints/password.php` (157) | `PasswordResetToken` + Messenger/Mailer | da fare | US-17 |
| Profilo | `endpoints/profilo.php` (184) | `ProfileController` | da fare | US-22 |
| Export dati / cancellazione account | `endpoints/utenti.php` (266) | `PrivacyController` | da fare | US-15, US-16 |
| Lista e dettaglio gruppi | `endpoints/campagne.php` (1763, parte lettura) | `Campaign\Controller\CampaignController` (`GET /api/campaigns`, `/api/campaigns/{id}`), `CampaignPresenter`, `CampaignDisplayStatus`; importi in centesimi, sola lettura | fatto | US-01 |
| Crea, modifica, elimina gruppi (admin) | `campagne.php` (`campagne_crea`, `campagne_modifica`, `campagne_elimina`, `valida_scaglioni`), `lib/gruppi_regole.php`, `lib/stato_lavoro.php` | `Campaign\Controller\CampaignAdminController` (`POST /api/campaigns`, `PATCH`/`DELETE /api/campaigns/{id}`), `Campaign\Application\CampaignAdminService`, `CampaignActions` | fatto (senza bozze e foto) | US-10 |
| Azioni admin sul gruppo: prolunga con avviso, chiudi ora, annulla, pubblica, duplica, scheda con soldi e cronologia | `endpoints/admin_gruppi.php` (437) | `Campaign\Controller\CampaignAdminController` | da fare (prolunga base già in `PATCH`; il resto con US-11) | US-11, US-23 |
| Catalogo e prodotti | `catalogo.php`, `prodotti.php` | `CatalogController` (fornitori, prodotti, sedi) | fatto | US-21 |
| Adesione con acconto | `endpoints/partecipazioni.php` (142), `pagamento.php` (`pagamento_prepara_acconto`, `pagamento_acconto_checkout`, `webhook_acconto_crea`, `pagamento_rimborsa_extra`) | `Participation\Controller\ParticipationController` (`POST /api/campaigns/{id}/join` → 410, `POST /api/payments/deposit-checkout`), `Participation\Service\DepositCheckoutService`, `DepositConfirmationService` (adesione da acconto pagato; chiamata dal webhook in US-07) | fatto (senza notifiche/email) | US-04 |
| Ritiro adesione prima del minimo | `partecipazioni.php` (`partecipazioni_ritira`) | `Participation\Service\WithdrawalService`; `DELETE /api/campaigns/{id}/participation` (adesione "annullata", non eliminata) | fatto (US-05); lock del gruppo già presente, test di concorrenza reale da fare in US-25 | US-05, US-25 |
| Stripe: checkout, webhook, rimborsi | `pagamento.php` (`pagamento_webhook`, `pagamento_checkout`, `pagamento_stato`, `saldo_completa`, `webhook_saldo_guardia`), `pagamenti.php` (142) | `Payment\Gateway\StripeGateway` (interfaccia), `HttpStripeGateway` (REST via HttpClient, senza SDK), finto nei test; `Payment\Webhook\StripeSignatureVerifier`, `StripeWebhookProcessor` (idempotenza su `StripeEvent`), `Payment\Controller\StripeWebhookController` (`POST /api/stripe/webhook`), `PaymentController` (`POST /api/payments/balance-checkout`, `GET /api/payments/status`), `Participation\Service\BalanceService`, `SucceededPaymentHandler` | fatto per acconto e saldo pagato dal cliente (US-04, US-07); restano addebito automatico del saldo, pagamenti falliti, riprova e rimborsi di chiusura | US-04, US-07, US-11, US-26 |
| Chiusura gruppo e saldo | `campagne.php` (`campagne_chiusura`, `chiusura_colletta`, `chiusura_solo_rimborsi`, `gruppo_annulla_e_rimborsa`, `campagne_invia_fornitore`), `lib/prezzi.php` | `Campaign\Application\CampaignClosureService`, `CampaignCancellationService`, `Payment\Service\RefundService`, `Supplier\Service\SupplierOrderService`; `Campaign\Controller\CampaignLifecycleController` (`POST /api/campaigns/{id}/close`, `/cancel`, `/send-to-supplier`) | fatto | US-11 |
| Saldo fallito 48h/24h | `campagne.php` (`segna_azione_richiesta`, `saldi_scaduti_esegui`, `gruppo_valuta_dopo_annullo`), `notifiche_eventi.php` | `CampaignClosureService` (segna "azione richiesta" e scadenza di 48 ore), `Participation\Service\BalanceExpiryService` (annulla l'adesione, rimborsa l'acconto, rivaluta il gruppo) | fatto senza promemoria a 24 ore (servono le notifiche), riprova del cliente (US-26) e costo a carico di BuyPool (US-27) | US-11, US-26 |
| Scelta consegna e conferma ricezione | `endpoints/consegne.php` (`consegne_costo`, `consegne_scelta`, `consegne_conferma_ricezione`) | `Delivery\Service\DeliveryChoiceService`, `DeliveryReceiptService`; `Delivery\Controller\DeliveryController` (`GET /api/deliveries/cost`, `POST /api/deliveries/choice`, `POST /api/deliveries/{id}/confirm-receipt`) | fatto (US-06, US-09); spedizione admin (`consegne_spedisci`) da fare in US-20 | US-06, US-09, US-20 |
| Ritiro con QR e "merce arrivata" | `endpoints/ritiro.php`, `admin_ordini_ritiri.php` (`admin_gruppo_merce_arrivata_esegui`) | `Delivery\Service\PickupService` (`GET /api/participations/{id}/pickup-qr`, `POST /api/pickups/{token}/scan`), `Campaign\Application\GoodsArrivalService` (`POST /api/campaigns/{id}/goods-arrived`), `PickupToken` | fatto | US-08 |
| Area fornitore: invito, attivazione, ordini | `fornitori.php` (`fornitori_invito_genera`, `fornitori_invito_verifica`, `fornitori_attiva`), `fornitore_ordini.php`, `lib/fornitore_ordini.php`, `lib/fornitore_stati.php` | `Supplier\Service\SupplierInviteService`, `SupplierFulfillmentService`; `Supplier\Controller\SupplierController` (`POST /api/admin/suppliers/{id}/invite`, `GET /api/suppliers/invite/{token}`, `POST /api/suppliers/activate`, `GET /api/supplier/orders`, `POST …/orders/{id}/confirm`, `…/ship`) | fatto | US-12 |
| Area fornitore: proposte, pagamenti al fornitore, azienda/IBAN, problemi, da-fare, panoramica, collega utente | `fornitore_proposte.php`, `fornitore_pagamenti.php`, `fornitore_da_fare.php`, `fornitore_azienda.php`, `fornitori_collega` | – | da fare / rimandato | US-13, US-14, US-23, US-27 |
| Proposte e voto | `proposte.php` (345), `admin_proposte.php` (379), `lib/proposte_regole.php` | `Proposal\…` | da fare | US-13, US-14 |
| Recensioni | `recensioni.php` (208) | `Review` (entity nuova, non c'è) | da fare | US-18 |
| Sospensione utenti, admin utenti/fornitori/messaggi | `admin_messaggi.php`, `utenti.php` | `Admin\UserAdminController` | da fare | US-19, US-23 |
| Notifiche in-app | `notifiche.php`, `lib/notifiche_eventi.php` (823) | `Notification\Service\NotificationService` | solo creazione notifica in-app (ricevuta, ritiro, merce pronta); elenco, lettura ed email da fare | US-09, US-23 |
| Email | `lib/mail.php` (214, PHPMailer) | Symfony Mailer + Messenger | da fare | vari |
| Impostazioni | `lib/impostazioni.php` | parametri `config/services.yaml` (ore saldo, spedizione, acconto %) | da fare | US-06 |
| Cron | `lib/cron.php` + `scripts/` (non copiati) | comando `app:campaigns:run-scheduled` (`Campaign\Command\CloseExpiredCampaignsCommand`), da lanciare ogni ora con cron o con l'Utilità di pianificazione di Windows; Symfony Scheduler non è installato (servirebbe una dipendenza nuova) | fatto come comando | US-11 |
| Upload immagini | `lib/immagini.php` (255) | `ProductImage` + servizio upload | da fare (MV2) | US-23 |
| I miei acquisti | `acquisti.php`, `lib/acquisti.php` | `PurchasesController`, `PurchasesService` | fatto | US-21 |
| Dashboard admin/cliente | `admin_dashboard.php`, `dashboard.php` | `DashboardController` | da fare | US-21 |
| Aumento prezzo, `costo_buypool`, consigliati, IBAN fornitore, wallet | `lib/aumento.php`, `consigliati.php`, `fornitore_azienda.php` | – | rimandato / non serve | US-27 |
| `dev.php`, `.env`, `config.php` | – | – | non serve | – |

## Valori di configurazione ereditati dal legacy
`acconto_percent` 20 · minimo checkout 0,50 € · `saldo_termine_ore` 48 · `saldo_promemoria_ore` 24 · `costo_spedizione` 4,90 (confermato, parametro `app.pricing.home_delivery_cents` = 490) · `ritiro_giorni_max` 10 · `fornitore_conferma_ore` 72.

## Contratto API ereditato dal legacy
Risposte `{ "ok": true, "dati": … }` e `{ "ok": false, "errore": { "codice", "messaggio", "campi"? } }` (classi `Shared\Http\ApiResponse` e `ApiError`). Nomi dei campi e codici errore restano quelli del legacy (italiano: `nome`, `cognome`, `ruolo`, `stato`, `CREDENZIALI_NON_VALIDE`…).
