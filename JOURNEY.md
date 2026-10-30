# JOURNEY

Diario del percorso di riscrittura di BuyPool in Symfony, in ordine cronologico.

## 2026-10-30 · Analisi del legacy e piano di migrazione
- Letto il legacy (`_private/legacy-src/api`, ~11.000 righe PHP, 22 tabelle) e scritti `docs/MAPPA-LEGACY-SYMFONY.md` e `docs/PIANO-MIGRAZIONE.md`.
- Decisione: il progetto resta un'API JSON; ordine di lavoro guidato dalla demo del flusso completo (MV1) entro il 19 novembre.
- Problema: la cartella `scripts/` dei cron non è stata copiata nel legacy; il comportamento si ricava da `campagne.php` e `notifiche_eventi.php`.

## 2026-10-30 · Servizio prezzi e ripartizione (US-04, US-07, US-11)
- Aggiunto `App\Pricing\PricingCalculator`: acconto (20% del primo scaglione × quantità, minimo 0,50 €), saldo con voci arrotondate al centesimo (merce, commissione, spedizione, acconti), scaglione raggiunto, minimo del gruppo, prezzo fissato alla chiusura.
- Aggiunto `App\Campaign\Application\QuantityAllocator`, porta di `ripartisci()`.
- Decisioni: tutto in centesimi interi; arrotondamento "half up" in aritmetica intera al posto dei float del legacy; percentuale acconto e costo consegna a domicilio sono parametri (`app.pricing.*`), 20 e 490 centesimi (4,90 € dal `config.php` legacy, da confermare).
- Verifica: un test confronta 20.000 casi casuali con la formula float del legacy (`saldo_dettaglio`) e i valori coincidono al centesimo.
- Differenza voluta: nel ripartitore i resti si confrontano con interi esatti, non con float, così a parità vince davvero l'adesione più vecchia.
- Non portati: aumento prezzo, `costo_buypool`, barra di avanzamento (non servono alle US).
