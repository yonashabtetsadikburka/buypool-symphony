import { apiPost } from '../api.js';
import { esc } from '../utils.js';
import { euro, pezzi } from '../format.js';

const ACCONTO_MINIMO = 0.5; // come il server (AUMENTO_ACCONTO_MINIMO)

/**
 * Finestra "Aumenta la quantità": sceglie quante unità aggiungere e porta al pagamento Stripe dell'acconto
 * delle sole unità in più (POST /pagamento/aumenta-checkout). L'importo mostrato è una STIMA: lo calcola il server.
 *
 * @param {{prenotazioneId:number, prodotto:string, quantitaAttuale:number, maxAggiuntivi:number,
 *          accontoUnitario:number, accontoPercent:number}} o
 */
export function apriAumenta(o) {
  document.getElementById('aumenta-modal')?.remove();
  const max = Math.max(1, Math.min(99, parseInt(o.maxAggiuntivi) || 1));
  let k = 1;
  const stima = (n) => Math.round(n * o.accontoUnitario * 100) / 100;

  document.body.insertAdjacentHTML('beforeend', `
    <div id="aumenta-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="aumenta-titolo">
      <div class="modal-content card aumenta-box">
        <div class="card-content">
          <h3 id="aumenta-titolo">Aumenta la quantità</h3>
          <p class="aumenta-sub">${esc(o.prodotto)} &middot; ora hai ${pezzi(o.quantitaAttuale)}</p>
          <div class="aumenta-stepper" role="group" aria-label="Unità da aggiungere">
            <button type="button" class="aumenta-step" data-delta="-1" aria-label="Un'unità in meno">&minus;</button>
            <input id="aumenta-k" type="number" min="1" max="${max}" value="1" inputmode="numeric" aria-label="Unità da aggiungere">
            <button type="button" class="aumenta-step" data-delta="1" aria-label="Un'unità in più">+</button>
          </div>
          <p class="aumenta-nuova" id="aumenta-nuova" aria-live="polite"></p>
          <div class="aumenta-riepilogo">
            <div class="aumenta-riga"><span>Acconto aggiuntivo</span><strong id="aumenta-importo"></strong></div>
            <p class="aumenta-nota">${esc(String(o.accontoPercent))}% del prezzo di gruppo sulle sole unità aggiunte. Il saldo resta addebitato alla chiusura, solo se il gruppo raggiunge il minimo.</p>
          </div>
          <p class="aumenta-errore" id="aumenta-errore" role="alert" hidden></p>
          <div class="aumenta-azioni">
            <button type="button" class="btn btn-default aumenta-vai" id="aumenta-vai">Vai al pagamento</button>
            <button type="button" class="btn btn-ghost aumenta-chiudi">Annulla</button>
          </div>
        </div>
      </div>
    </div>`);

  const modal = document.getElementById('aumenta-modal');
  const input = document.getElementById('aumenta-k');
  const errore = document.getElementById('aumenta-errore');
  const vai = document.getElementById('aumenta-vai');
  const chiudi = () => { document.removeEventListener('keydown', tasto); modal.remove(); };
  const tasto = (e) => { if (e.key === 'Escape') chiudi(); };
  document.addEventListener('keydown', tasto);

  const aggiorna = () => {
    k = Math.max(1, Math.min(max, parseInt(input.value) || 1));
    input.value = String(k);
    const importo = stima(k);
    document.getElementById('aumenta-importo').textContent = euro(importo);
    document.getElementById('aumenta-nuova').textContent = `Dopo il pagamento avrai ${pezzi(o.quantitaAttuale + k)}.`;
    const sotto = importo < ACCONTO_MINIMO;
    errore.hidden = !sotto;
    errore.textContent = sotto ? 'L\'acconto minimo è €0,50: aggiungi più unità.' : '';
    vai.disabled = sotto;
  };
  aggiorna();
  input.focus();
  input.select();

  input.addEventListener('input', aggiorna);
  modal.addEventListener('click', async (e) => {
    if (e.target === modal || e.target.closest('.aumenta-chiudi')) { chiudi(); return; }
    const step = e.target.closest('.aumenta-step');
    if (step) { input.value = String((parseInt(input.value) || 1) + parseInt(step.dataset.delta)); aggiorna(); return; }
    if (!e.target.closest('#aumenta-vai')) return;

    vai.disabled = true;
    vai.textContent = 'Ti porto al pagamento…';
    try {
      const res = await apiPost('/pagamento/aumenta-checkout', { prenotazione_id: o.prenotazioneId, quantita_aggiuntiva: k });
      if (res.dati?.checkout_url) { window.location.href = res.dati.checkout_url; return; }
      throw new Error('Pagamento non disponibile: riprova tra poco.');
    } catch (err) {
      vai.disabled = false;
      vai.textContent = 'Vai al pagamento';
      errore.textContent = err.message;
      errore.hidden = false;
    }
  });
}
