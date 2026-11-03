import { esc } from '../utils.js';

let chiusuraGlobaleAttiva = false;

/** HTML del menu "⋯" di una riga. voci: [{azione, etichetta, pericolo?}] */
export function menuAzioniHtml(id, voci, etichettaAria = 'Altre azioni') {
  if (!voci.length) return '';
  return `<div class="adm-menu" data-menu>
    <button type="button" class="adm-btn-icona" data-menu-toggle aria-haspopup="menu" aria-expanded="false" aria-label="${esc(etichettaAria)}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
    </button>
    <div class="adm-menu-lista" role="menu" hidden>
      ${voci.map(v => `<button type="button" role="menuitem" class="adm-menu-voce${v.pericolo ? ' adm-menu-pericolo' : ''}" data-azione="${esc(v.azione)}" data-id="${id}" tabindex="-1">${esc(v.etichetta)}</button>`).join('')}
    </div>
  </div>`;
}

/** Posizione (viewport) della lista: allineata al bordo destro del pulsante, sotto di lui o, se sotto manca spazio, sopra. Pura, testabile. */
export function posizioneMenu(pulsante, larghezza, altezza, vw, vh, margine = 8, scarto = 6) {
  const sotto = vh - pulsante.bottom - scarto - margine;
  const sopra = pulsante.top - scarto - margine;
  const inAlto = altezza > sotto && sopra > sotto;
  const top = inAlto ? Math.max(margine, pulsante.top - scarto - altezza) : pulsante.bottom + scarto;
  const left = Math.min(Math.max(margine, pulsante.right - larghezza), Math.max(margine, vw - larghezza - margine));
  return { top, left, inAlto, maxHeight: Math.max(120, inAlto ? sopra : sotto) };
}

/** La lista usa position:fixed: nessun contenitore con overflow (tabelle) la ritaglia ne' crea scrollbar interne. */
function posiziona(menu) {
  const lista = menu.querySelector('.adm-menu-lista');
  const toggle = menu.querySelector('[data-menu-toggle]');
  lista.style.maxHeight = '';
  const p = posizioneMenu(toggle.getBoundingClientRect(), lista.offsetWidth, lista.offsetHeight, window.innerWidth, window.innerHeight);
  lista.style.top = `${p.top}px`;
  lista.style.left = `${p.left}px`;
  lista.style.maxHeight = `${p.maxHeight}px`;
}

function chiudiTutti(tranne = null) {
  document.querySelectorAll('[data-menu]').forEach(m => {
    if (m === tranne) return;
    m.querySelector('.adm-menu-lista').hidden = true;
    m.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', 'false');
  });
}

/** Attiva apertura/chiusura, frecce, Esc e click fuori per tutti i menu dentro `root` (idempotente). */
export function attivaMenuAzioni(root) {
  root.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-menu-toggle]');
    if (!toggle) return;
    const menu = toggle.closest('[data-menu]');
    const lista = menu.querySelector('.adm-menu-lista');
    const apri = lista.hidden;
    chiudiTutti(menu);
    lista.hidden = !apri;
    toggle.setAttribute('aria-expanded', String(apri));
    if (apri) { posiziona(menu); lista.querySelector('[role="menuitem"]')?.focus({ preventScroll: true }); }
  });
  root.addEventListener('keydown', (e) => {
    const menu = e.target.closest?.('[data-menu]');
    if (!menu) return;
    const lista = menu.querySelector('.adm-menu-lista');
    const toggle = menu.querySelector('[data-menu-toggle]');
    const voci = [...lista.querySelectorAll('[role="menuitem"]')];
    if (e.key === 'Escape' && !lista.hidden) {
      e.preventDefault(); e.stopPropagation(); chiudiTutti(); toggle.focus(); return;
    }
    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !lista.hidden) {
      e.preventDefault();
      const i = voci.indexOf(document.activeElement);
      voci[(i + (e.key === 'ArrowDown' ? 1 : -1) + voci.length) % voci.length]?.focus();
    }
    if (e.key === 'ArrowDown' && e.target === toggle && lista.hidden) { e.preventDefault(); toggle.click(); }
    if (e.key === 'Tab' && !lista.hidden) chiudiTutti();
  });
  if (!chiusuraGlobaleAttiva) {
    chiusuraGlobaleAttiva = true;
    document.addEventListener('click', (e) => { if (!e.target.closest('[data-menu]')) chiudiTutti(); });
    // Lista fissa rispetto alla finestra: se la pagina scorre o cambia misura si chiude (il focus torna al pulsante se era nel menu)
    const chiudiSeAperto = () => {
      const aperto = document.querySelector('[data-menu] .adm-menu-lista:not([hidden])');
      if (!aperto) return;
      const eraDentro = aperto.contains(document.activeElement);
      const toggle = aperto.closest('[data-menu]').querySelector('[data-menu-toggle]');
      chiudiTutti();
      if (eraDentro) toggle.focus();
    };
    window.addEventListener('scroll', chiudiSeAperto, { passive: true });
    window.addEventListener('resize', chiudiSeAperto);
  }
}

/** Chiude i menu aperti (da chiamare prima di eseguire una voce). */
export function chiudiMenuAzioni() { chiudiTutti(); }
