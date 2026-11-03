import { esc } from '../utils.js';

const pila = [];   // finestre aperte: ascolta i tasti solo quella in cima

const FOCUSABILI = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Finestra di dialogo accessibile: focus intrappolato, Esc per chiudere, focus ripristinato sull'elemento che l'ha aperta.
 *
 * @param {object} o
 * @param {string} o.titolo
 * @param {string} [o.introHtml]     HTML gia' escapato sopra il corpo
 * @param {string} [o.corpoHtml]     HTML gia' escapato (campi, riepilogo)
 * @param {string} [o.etichettaConferma]  testo del pulsante principale (assente = solo "Chiudi")
 * @param {'primario'|'pericolo'} [o.variante]
 * @param {string} [o.parolaConferma]  se presente, il pulsante resta spento finche' non si scrive questa parola
 * @param {(el:HTMLElement)=>Promise<void>|void} [o.onConferma]  se lancia un errore, il messaggio resta nella finestra
 * @param {()=>void} [o.onChiusa]
 * @returns {{el:HTMLElement, chiudi:()=>void}}
 */
export function apriModale(o) {
  const attivo = document.activeElement;
  // se l'apertura viene da una voce di menu (che si richiude), il focus torna al pulsante del menu
  const precedente = attivo?.closest?.('[data-menu]')?.querySelector('[data-menu-toggle]') || attivo;
  const id = 'adm-modale-' + Math.random().toString(36).slice(2, 8);
  const overlay = document.createElement('div');
  overlay.className = 'adm-modale-overlay';
  overlay.innerHTML = `
    <div class="adm-modale" role="dialog" aria-modal="true" aria-labelledby="${id}-t">
      <h2 class="adm-modale-titolo" id="${id}-t">${esc(o.titolo)}</h2>
      ${o.introHtml ? `<div class="adm-modale-intro">${o.introHtml}</div>` : ''}
      <div class="adm-modale-corpo">${o.corpoHtml || ''}</div>
      ${o.parolaConferma ? `<div class="adm-campo adm-conferma-parola">
        <label for="${id}-p">Per confermare scrivi <strong>${esc(o.parolaConferma)}</strong></label>
        <input id="${id}-p" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false">
      </div>` : ''}
      <p class="adm-modale-errore" role="alert" hidden></p>
      <div class="adm-modale-azioni">
        <button type="button" class="adm-btn adm-btn-sec" data-modale="annulla">${o.etichettaConferma ? 'Annulla' : 'Chiudi'}</button>
        ${o.etichettaConferma ? `<button type="button" class="adm-btn ${o.variante === 'pericolo' ? 'adm-btn-pericolo' : 'adm-btn-pri'}" data-modale="conferma"${o.parolaConferma ? ' disabled' : ''}>${esc(o.etichettaConferma)}</button>` : ''}
      </div>
    </div>`;
  document.body.appendChild(overlay);
  const box = overlay.querySelector('.adm-modale');
  const errore = overlay.querySelector('.adm-modale-errore');
  const bConferma = overlay.querySelector('[data-modale="conferma"]');

  const chiudi = () => {
    document.removeEventListener('keydown', onKey, true);
    pila.splice(pila.indexOf(overlay), 1);
    overlay.remove();
    if (precedente && precedente.isConnected) precedente.focus();
    o.onChiusa?.();
  };
  function onKey(e) {
    if (pila[pila.length - 1] !== overlay) return;
    if (e.key === 'Escape') { e.stopPropagation(); chiudi(); return; }
    if (e.key !== 'Tab') return;
    const f = [...box.querySelectorAll(FOCUSABILI)].filter(x => !x.hidden && x.offsetParent !== null);
    if (!f.length) return;
    const primo = f[0], ultimo = f[f.length - 1];
    if (e.shiftKey && document.activeElement === primo) { e.preventDefault(); ultimo.focus(); }
    else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primo.focus(); }
    else if (!box.contains(document.activeElement)) { e.preventDefault(); primo.focus(); }
  }
  pila.push(overlay);
  document.addEventListener('keydown', onKey, true);
  overlay.addEventListener('mousedown', (e) => { if (e.target === overlay) chiudi(); });
  overlay.querySelector('[data-modale="annulla"]').addEventListener('click', chiudi);

  const parola = overlay.querySelector('.adm-conferma-parola input');
  if (parola && bConferma) {
    parola.addEventListener('input', () => { bConferma.disabled = parola.value.trim() !== o.parolaConferma; });
  }
  if (bConferma) {
    bConferma.addEventListener('click', async () => {
      errore.hidden = true;
      bConferma.disabled = true;
      const testo = bConferma.textContent;
      bConferma.textContent = 'Attendi…';
      try {
        await o.onConferma?.(box);
        chiudi();
      } catch (err) {
        errore.textContent = err.message || 'Operazione non riuscita';
        errore.hidden = false;
        bConferma.textContent = testo;
        bConferma.disabled = parola ? parola.value.trim() !== o.parolaConferma : false;
        errore.scrollIntoView?.({ block: 'nearest' });
      }
    });
  }

  const primoCampo = box.querySelector('input:not([disabled]), textarea, select');
  (primoCampo || box.querySelector('[data-modale="annulla"]')).focus();
  return { el: box, chiudi };
}
