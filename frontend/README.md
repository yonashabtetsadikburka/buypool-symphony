# Frontend (ereditato dal legacy)

Pagine HTML/JS senza build, copiate da `_private/frontend` (che resta intatta). Parlano ancora "legacy"
(percorsi italiani, importi in euro): `js/compat.js` li traduce per la nuova API Symfony in `backend/`.

## Avvio in locale

1. **Database di sviluppo** (una volta): da `backend/`
   `php bin/console doctrine:migrations:migrate` (serve la migrazione `company_name`, senza la registrazione fallisce).
   I dati di prova si caricano con `php bin/console doctrine:fixtures:load` (cancella i dati di sviluppo!).
2. **Backend** su `http://localhost:8000`: da `backend/` `php -S localhost:8000 -t public`
   (o `docker compose up`). `CORS_ALLOW_ORIGIN` deve essere l'indirizzo del frontend (default `http://localhost:8080`).
3. **Frontend** su `http://localhost:8080`: da `frontend/` `php -S localhost:8080`
   e aprire `http://localhost:8080/index.html`.
4. Pagamenti di prova: `STRIPE_SECRET_KEY=sk_test_…` in `backend/.env.local` (mai nei file committati).
   Per ricevere il webhook in locale: `stripe listen --forward-to localhost:8000/api/stripe/webhook`; il comando stampa
   un segreto `whsec_…` da mettere in `STRIPE_WEBHOOK_SECRET` (sempre in `.env.local`). Senza webhook l'adesione viene
   registrata comunque dal controllo che il frontend fa al ritorno da Stripe (`GET /api/payments/status`).
5. Dopo aver aggiornato il codice: `php bin/console doctrine:migrations:migrate` (c'è una migrazione nuova, `final_price_cents`).

`API_URL` è in `js/constants.js`. Usare sempre `localhost` (non `127.0.0.1`) per frontend e backend: il cookie
di sessione vale per lo stesso sito.

## Cosa funziona oggi

| Funzione | Stato |
|---|---|
| Registrazione, accesso, uscita | sì |
| Accedi / Registrati con Google | sì, se `GOOGLE_CLIENT_ID` è impostato in `js/constants.js` (stesso valore di `backend/.env.local`) e in Google Cloud Console è tra le "Origini JavaScript autorizzate" `http://localhost:8080`. Il browser ottiene l'idToken da Google e lo invia a `POST /api/auth/google`. Microsoft: non ancora |
| Elenco e dettaglio dei gruppi | sì |
| "Unisciti al gruppo" e pagamento dell'acconto su Stripe | sì: l'adesione si crea dal webhook o dal controllo al ritorno da Stripe |
| Pagamento del saldo (adesione confermata) | solo le chiamate (`balance-checkout`, `status`); la chiusura che conferma le adesioni è la US-11 |
| Admin: creare, modificare, eliminare un gruppo | solo le chiamate (`POST/PATCH/DELETE /api/campaigns`); l'elenco admin e la scelta del fornitore non sono migrati |
| Profilo, notifiche, acquisti, wallet, fornitore, proposte, recensioni, ritiro con QR, Microsoft nella pagina | non ancora: rispondono 404 e la pagina mostra un errore |

## Cosa cambia rispetto al legacy

- `js/compat.js`: tabella dei percorsi vecchio → nuovo, importi `*_cents` letti anche in euro, prezzi inviati in centesimi,
  moduli multipart inviati come JSON (le foto sono ignorate fino al caricamento immagini, US-23).
- `js/api.js`: cookie di sessione (`credentials: 'include'`), niente `Authorization: Bearer`.
- `js/auth.js`: l'uscita chiude anche la sessione sul server.
- `js/consegna-metodi.js`, `js/pages/dettaglio.js`: l'id della sede di ritiro è un UUID, non più un intero.
- I gruppi di prova delle fixture hanno scadenze nel passato: per vedere un gruppo aperto va creato uno nuovo (admin) o ricaricate le fixture.

## Attenzione alle porte

La porta **8888 è di MAMP** (Apache, phpMyAdmin, il vecchio frontend su `/buypool`): non avviarci sopra `php -S`,
altrimenti MAMP smette di rispondere e phpMyAdmin dà "The requested resource … was not found on this server".
Il frontend nuovo usa la **8080**, il backend la **8000**.
