export function PrivacyPage() {
  const content = document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = `
    <div class="login-page" style="align-items:flex-start;padding-top:var(--space-8);padding-bottom:var(--space-8);">
      <div class="login-card card" style="max-width:720px;">
        <div class="card-content">
          <div class="login-logo"><h1><a href="app.html#/" class="login-logo-link">BuyPool</a></h1><p>Informativa privacy</p></div>
    

          <h3>1. Titolare del trattamento</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">BuyPool, P.IVA [al momento non c'è l'abbiamo], sede in Perugia, Umbria, email buypool@gmail.com. Per richieste privacy: buypool@gmail.com.</p>

          <h3>2. Quali dati raccogliamo</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Account: nome, cognome, email, password (solo hash irreversibile), telefono, indirizzo (via, CAP, citta, provincia), codice fiscale, partita IVA (opzionale), tipo account. Attivita': partecipazioni ai gruppi, pagamenti (importi e date), indirizzi di spedizione, proposte e voti, notifiche ricevute. Dati tecnici: log di accesso e stato sessione.</p>

          <h3>3. Perche' li usiamo (finalità e basi giuridiche)</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Esecuzione del servizio (gestione account, gruppi, ordini, pagamenti, consegne); obblighi di legge (conservazione contabile); legittimo interesse (sicurezza, prevenzione abusi); consenso per eventuali comunicazioni promozionali (mai attivo di default).</p>

          <h3>4. Con chi li condividiamo</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Stripe (pagamenti con carta, Carte: i dati carta non transitano mai dai nostri server); Google/Microsoft (solo se usi il login social); fornitori coinvolti nei tuoi gruppi (solo nome e quantita' necessarie all'evasione); hosting/server. Nessuna vendita di dati a terzi.</p>

          <h3>5. Per quanto li teniamo</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Per la durata dell'account e, dopo la cancellazione, solo i dati con obblighi di conservazione (es. 10 anni per i documenti fiscali legati ai pagamenti).</p>

          <h3>6. I tuoi diritti</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Accesso, rettifica, cancellazione, limitazione, portabilita' e opposizione: scrivi a buypool@gmail.com oppure usa le funzioni "Scarica i miei dati" e "Richiedi cancellazione" nel tuo Profilo. Puoi reclamare al Garante per la protezione dei dati personali (www.garanteprivacy.it).</p>

          <h3>7. Cookie e memorizzazione locale</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-3);">Usiamo solo strumenti tecnici: cookie di sessione PHPSESSID (login) e localStorage del browser (sessione). Niente cookie di profilazione nostri. Google/Microsoft e Stripe possono usare propri cookie secondo le loro informative quando usi login social o pagamenti.</p>

          <h3>8. Sicurezza</h3>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">Password mai salvate in chiaro (hash sicuri), accessi via sessione, dati di pagamento gestiti esclusivamente da Stripe. Segnala problemi a buypool@gmail.com.</p>

          <div style="text-align:center;"><a href="#/login" class="btn btn-outline">Torna al login</a></div>
        </div>
      </div>
    </div>`;
}
