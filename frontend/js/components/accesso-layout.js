// Layout condiviso delle pagine di accesso: header minimo + card (con o senza pannello).

const HOME_GRUPPI = 'app.html#/';

function header() {
  return `
    <header class="acc-header">
      <a class="acc-back-mobile" href="${HOME_GRUPPI}" aria-label="Torna ai gruppi"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg></a>
      <a class="acc-logo" href="${HOME_GRUPPI}" aria-label="BuyPool, pagina dei gruppi">
        <img src="img/logo_buypool.webp" alt="BuyPool">
      </a>
      <a class="acc-back-desktop" href="${HOME_GRUPPI}">&larr; Torna ai gruppi</a>
    </header>`;
}

const PANNELLO = `
  <aside class="acc-panel" aria-label="Come funziona BuyPool">
    <h2>Più siamo, <span>meno si paga.</span></h2>
    <p class="acc-lead"><strong>Acquisti collettivi</strong>, a prezzo da grossista.</p>
    <ol class="acc-steps">
      <li>Scegli un gruppo</li>
      <li>Unisciti con il 20% di acconto</li>
      <li>Il gruppo cresce, il prezzo scende</li>
      <li>Saldo e ritiro solo se il gruppo si completa</li>
    </ol>
    <div class="acc-guarantee">Se il gruppo non raggiunge il minimo, l'acconto ti viene rimborsato al 100%.</div>
  </aside>`;

/** Pagina con modulo: card a sinistra, pannello a destra (nascosto sotto 900px). */
export function accessoConPannello(cardHtml) {
  return `
    <div class="acc">
      ${header()}
      <main class="acc-main">
        <div class="acc-cols">
          <div>
            <p class="acc-mobile-note">Se il gruppo non parte, l'acconto torna a te al 100%.</p>
            <section class="acc-card">${cardHtml}</section>
          </div>
          ${PANNELLO}
        </div>
      </main>
    </div>`;
}

/** Pagina di servizio: solo la card centrata. */
export function accessoSolo(cardHtml) {
  return `
    <div class="acc">
      ${header()}
      <main class="acc-main acc-solo"><section class="acc-card">${cardHtml}</section></main>
    </div>`;
}
