# Piano di migrazione (consegna 19 novembre 2026)

Stato al 30/10/2026: ~20 giorni. Entity, migrazioni, fixtures e macchina a stati sono pronti; mancano controller, sicurezza, pricing, Stripe, QR, Scheduler, mail. Baseline: 110 test unit OK, `lint:container` OK.
Le stime sono ore nette di lavoro. Totale MV1 ≈ 60 h, MV2 ≈ 45 h, MV3 ≈ 8 h.

## Ordine di lavoro

| # | Blocco | US | Ore | Dipende da | Rischio | Test previsti |
|---|---|---|---|---|---|---|
| 1 | Servizio prezzi (acconto, saldo, scaglione, spedizione, commissione) | US-04, US-07 | 4 | – | basso | unit con casi numerici identici al legacy al centesimo |
| 2 | Registrazione, login, ruoli, logout | US-02, US-03 | 7 | – | medio (Google) | functional (400/401/409/201) |
| 3 | Elenco e dettaglio gruppi pubblici | US-01 | 4 | 1 | basso | functional 200/404 |
| 4 | Gestione gruppi admin | US-10 | 6 | 2, 3 | basso | functional 403/400/409 |
| 5 | Adesione con acconto Stripe (gateway + mock) | US-04 | 8 | 1, 2, 3 | alto (Stripe) | unit gateway finto, functional 410/422 |
| 6 | Webhook, idempotenza, saldo | US-07 | 8 | 5 | alto | firma non valida, evento doppio |
| 7 | Chiusura gruppo, rimborsi, allocazione, Scheduler | US-11 | 9 | 1, 6, PR #20 | alto | unit su minimo/scaglione, 409 sulla ripetizione |
| 8 | Consegna, QR, conferma ricezione | US-06, US-08, US-09 | 8 | 5, 7 | medio | functional 404/409/403 |
| 9 | Fornitore: attivazione e avanzamento ordini | US-12 | 6 | 7 | medio | functional 410/404/409/400 |
| 10 | Ritiro adesione | US-05 | 3 | 5 | medio | 409/403/404 |
| — | **MV1 completo (demo end-to-end)** | | ≈ 63 | | | |
| 11 | Reset password, profilo, Microsoft | US-17, US-22, US-24 | 8 | 2 | basso | |
| 12 | Elenco adesioni e catalogo | US-21 | 4 | 5 | basso | |
| 13 | Proposte, voti, pubblicazione | US-13, US-14 | 9 | 4 | medio | |
| 14 | Spedizione admin, sospensione, gestione admin, notifiche e email | US-19, US-20, US-23 | 12 | 2, 8 | medio | |
| 15 | Privacy: export e cancellazione, recensioni | US-15, US-16, US-18 | 8 | 2 | basso | |
| 16 | Robustezza: soglie e concorrenza | US-25 | 4 | 5 | alto | lock pessimistico, test concorrenza |
| 17 | Saldo fallito 48h/24h, storico wallet | US-26, US-27 | 8 | 7 | medio | |

## Obbligatorio / tagliabile
- **Obbligatorio per la demo** (MV1, blocchi 1–10): flusso completo accedi → aderisci con acconto → chiusura → saldo o rimborso → ritiro con QR → fornitore.
- **Se il tempo non basta, tagliare in quest'ordine**: US-27, US-26, US-24, US-15/16, US-18, US-13/14, US-23 (parte messaggi). Il blocco 16 (US-25) va comunque fatto prima di consegnare, almeno il lock sul ritiro adesione.
- **Mai portare**: aumento prezzo, `costo_buypool`, consigliati, IBAN fornitore (non sono nelle US).

## Punti da decidere (il legacy e le US/il brief divergono)
1. **US-05 vs brief**: la US dice "ritiro possibile solo se non pagato"; il brief dice "non annullabile dopo il minimo raggiunto". Propongo: ritiro consentito prima della scadenza e finché il minimo non è raggiunto, con rimborso dell'acconto.
2. **Spedizione**: `config.php` ha 4,90 €, le impostazioni hanno 0 centesimi di default. Propongo 4,90 € (490 cent) come valore configurabile.
3. **Saldo fallito**: il legacy usa 48 h e 24 h configurabili (coincide col brief). Le US lo mettono in MV3 (US-26), ma la regola serve già alla chiusura (US-11): propongo di fare in MV1 solo il caso base e in US-26 il retry.
4. **Cron**: la cartella `scripts/` del legacy non è stata copiata; il comportamento dei cron lo ricavo da `campagne.php` e `notifiche_eventi.php`.
5. **Naming su GitHub**: milestone MV1/MV2/MV3 e label must/should/could/docs, mentre il file US dice M1/M2/M3 e label `user-story`. Annotato, non modificato.
6. **Lingua dell'API**: messaggi di errore e testi cliente in italiano ("gruppo", "adesione", "unità").

## Nuove issue proposte (non create)
| Titolo | Label | Milestone | Perché |
|---|---|---|---|
| Servizio prezzi in centesimi con test paritari | must | MV1 | oggi non c'è un'issue per le formule |
| Scheduler: chiusura gruppi, promemoria e scadenza saldo | must | MV1 | serve a US-11, nessuna issue dedicata |
| Gateway Stripe con mock e webhook idempotente | must | MV1 | separare l'infrastruttura da US-04/US-07 |
| Email e notifiche (Messenger) | should | MV2 | base per US-09, US-20, US-23 |
| Test di parità (`tests/Parity`) sulle formule | should | MV2 | confronto con valori legacy |
| docs: diario JOURNEY.md e mappa legacy | docs | MV1 | richiesto dal percorso |

## Procedura per ogni issue
Una issue alla volta su branch `feat/<slug>`; io scrivo codice, test e voce di JOURNEY.md; tu fai commit, push, PR e sposti la card. Per ogni issue ti lascio pronti messaggio di commit, titolo/descrizione PR e commento per l'issue.
