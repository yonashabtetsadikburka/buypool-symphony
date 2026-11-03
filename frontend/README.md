# Frontend (ereditato dal legacy)

Pagine HTML/JS senza build, copiate da `_private/frontend` (che resta intatta). Parlano ancora "legacy"
(percorsi italiani, importi in euro): `js/compat.js` li traduce per la nuova API Symfony in `backend/`.

## Avvio in locale

1. **Database di sviluppo** (una volta): da `backend/`
   `php bin/console doctrine:migrations:migrate` (serve la migrazione `company_name`, senza la registrazione fallisce).
   I dati di prova si caricano con `php bin/console doctrine:fixtures:load` (cancella i dati di sviluppo!).
2. **Backend** su `http://localhost:8000`: da `backend/` `php -S localhost:8000 -t public`
   (o `docker compose up`). `CORS_ALLOW_ORIGIN` deve essere l'indirizzo del frontend (default `http://localhost:8888`).
3. **Frontend** su `http://localhost:8888`: da `frontend/` `php -S localhost:8888`
   e aprire `http://localhost:8888/index.html`.
4. Pagamenti veri di prova: `STRIPE_SECRET_KEY=sk_test_…` in `backend/.env.local` (mai nei file committati).

`API_URL` è in `js/constants.js`. Usare sempre `localhost` (non `127.0.0.1`) per frontend e backend: il cookie
di sessione vale per lo stesso sito.

## Cosa funziona oggi

| Funzione | Stato |
|---|---|
| Registrazione, accesso, uscita | sì |
| Elenco e dettaglio dei gruppi | sì |
| "Unisciti al gruppo" fino al pagamento su Stripe | sì (la creazione dell'adesione dopo il pagamento arriva con il webhook, US-07) |
| Admin: creare, modificare, eliminare un gruppo | solo le chiamate (`POST/PATCH/DELETE /api/campaigns`); l'elenco admin e la scelta del fornitore non sono migrati |
| Profilo, notifiche, acquisti, wallet, fornitore, proposte, recensioni, ritiro con QR, Google/Microsoft nella pagina | non ancora: rispondono 404 e la pagina mostra un errore |

## Cosa cambia rispetto al legacy

- `js/compat.js`: tabella dei percorsi vecchio → nuovo, importi `*_cents` letti anche in euro, prezzi inviati in centesimi,
  moduli multipart inviati come JSON (le foto sono ignorate fino al caricamento immagini, US-23).
- `js/api.js`: cookie di sessione (`credentials: 'include'`), niente `Authorization: Bearer`.
- `js/auth.js`: l'uscita chiude anche la sessione sul server.
- `js/consegna-metodi.js`, `js/pages/dettaglio.js`: l'id della sede di ritiro è un UUID, non più un intero.
- I gruppi di prova delle fixture hanno scadenze nel passato: per vedere un gruppo aperto va creato uno nuovo (admin) o ricaricate le fixture.
