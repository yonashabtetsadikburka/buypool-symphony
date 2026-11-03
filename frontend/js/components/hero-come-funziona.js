// Hero della pagina "Gruppi d'acquisto": titolo, tre passi e dettaglio "Come funziona" apribile.
// Solo presentazione: i prezzi del passo 2 sono illustrativi e non vengono dal calcolo dei gruppi.
import { ACCONTO_PERCENT } from '../constants.js';

export const HERO_DETTAGLIO_ID = 'bp-how-detail';

const SCUDO = 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.029 9-11.622 0-1.042-.133-2.052-.382-3.016A11.959 11.959 0 0112 2.714z';

const PASSI = [
  { t: 'Unisciti a un gruppo', s: `Paghi solo il ${ACCONTO_PERCENT}% di acconto`,
    icon: 'M13 7a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0M18 8v6m-3-3h6' },
  { t: 'Risparmia', s: 'Più siamo, più scende il prezzo', prezzi: true,
    icon: 'M12 5v14m0 0l-6-6m6 6l6-6' },
  { t: 'Ricevi o ritira', s: 'A casa o in sede',
    icon: 'M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75' }
];

const DETTAGLIO = [
  { t: 'Cerca un gruppo', s: 'Ogni gruppo è creato da BuyPool, con prezzi a scaglioni.' },
  { t: `Unisciti con il ${ACCONTO_PERCENT}% di acconto`, s: 'Scegli la quantità e la modalità di consegna.' },
  { t: 'Più il gruppo cresce, più il prezzo scende', s: 'Più persone si uniscono, più scende il prezzo per tutti.' },
  { t: 'Paghi il saldo solo se il gruppo si completa', s: 'Il saldo si addebita alla chiusura, solo se la quantità minima è stata raggiunta.' },
  { t: 'BuyPool invia l\'ordine al fornitore', s: 'Il fornitore riceve l\'ordine, lo prepara e lo spedisce in sede.' },
  { t: 'Ricevi l\'ordine', s: 'A casa o in sede, con codice QR.' }
];

function passoHtml(p, i) {
  const prezzi = p.prezzi ? `
      <span class="bp-how-prices" role="img" aria-label="Esempio: il prezzo scende da 49,90 euro a 39,90 euro">
        <span class="bp-how-es">es.</span> <s>&euro;49,90</s> <i aria-hidden="true">&rsaquo;</i> &euro;44,90 <i aria-hidden="true">&rsaquo;</i> <b>&euro;39,90</b>
      </span>` : '';
  return `
    <li class="bp-how-step">
      <span class="bp-how-circle">
        <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${p.icon}"/></svg>
        <span class="bp-how-dot" aria-hidden="true">${i + 1}</span>
      </span>
      <span class="bp-how-text">
        <span class="bp-how-n">${p.t}</span>
        <span class="bp-how-sub">${p.s}</span>${prezzi}
      </span>
    </li>`;
}

function dettaglioPassoHtml(p, i) {
  return `
        <li class="bp-how-d-step">
          <span class="bp-how-d-dot" aria-hidden="true">${i + 1}</span>
          <h3>${p.t}</h3>
          <p>${p.s}</p>
        </li>`;
}

export function comeFunzionaHtml() {
  return `
    <section class="bp-how" aria-label="Come funziona l'acquisto di gruppo">
      <div class="bp-how-main">
        <div class="bp-how-left">
          <h1 class="bp-how-title">Pi&ugrave; siamo, <br><span class="bp-how-title-accent">meno si paga.</span></h1>
          <p class="bp-how-lead"><strong>Acquisti collettivi</strong>, a prezzo da grossista.</p>
        </div>
        <ol class="bp-how-steps">${PASSI.map(passoHtml).join('')}
        </ol>
      </div>
      <div class="bp-how-bar">
        <p class="bp-how-guarantee">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${SCUDO}"/></svg>
          Acconto rimborsato al 100% se il gruppo non parte.
        </p>
        <button type="button" class="bp-how-toggle" id="bp-how-toggle" aria-expanded="false" aria-controls="${HERO_DETTAGLIO_ID}">
          Come funziona, passo per passo
          <svg class="bp-how-chev" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>
        </button>
      </div>
      <div class="bp-how-panel" id="${HERO_DETTAGLIO_ID}" inert>
        <div class="bp-how-panel-inner">
          <ol class="bp-how-d-steps">${DETTAGLIO.map(dettaglioPassoHtml).join('')}
          </ol>
          <div class="bp-how-d-notes">
            <div class="bp-how-d-note">
              <h3>E se il gruppo non parte?</h3>
              <p>Se il minimo non viene raggiunto, l'acconto ti viene rimborsato al 100% in automatico.</p>
            </div>
            <div class="bp-how-d-note">
              <h3>Quando pago il saldo?</h3>
              <p>Alla chiusura del gruppo, solo se il minimo è raggiunto. Prima di addebitarlo ti avvisiamo per email.</p>
            </div>
          </div>
        </div>
      </div>
    </section>`;
}

/** Collega il pulsante al pannello. Il pannello chiuso è `inert` (e `visibility:hidden` da CSS): niente tabulazione. */
export function initComeFunziona(root = document) {
  const btn = root.querySelector('#bp-how-toggle');
  const panel = root.querySelector('#' + HERO_DETTAGLIO_ID);
  if (!btn || !panel) return;
  btn.addEventListener('click', () => {
    const apri = btn.getAttribute('aria-expanded') !== 'true';
    btn.setAttribute('aria-expanded', String(apri));
    panel.classList.toggle('is-open', apri);
    panel.inert = !apri;
  });
}
