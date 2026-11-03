import { apiPost } from '../api.js';
import { esc } from './../utils.js';
import { showToast } from './toast.js';

/**
 * Finestra "Lascia una recensione" (prodotto e/o fornitore) dopo il ritiro.
 * Stesso flusso della vecchia pagina Ordini: POST /campagne/{id}/recensioni e POST /fornitori/{id}/recensioni
 * (voto 1-5, testo max 1000). Mostra solo i blocchi che mancano.
 *
 * @param {{fornitoreId:number, nomeFornitore:string, campagnaId:number, nomeProdotto:string,
 *          mancaProdotto:boolean, mancaFornitore:boolean, dopo?:Function}} opz
 */
export function apriRecensioni(opz) {
  document.getElementById('rec-popup-modal')?.remove();
  const faProd = !!opz.mancaProdotto && !!opz.campagnaId;
  const faForn = !!opz.mancaFornitore && !!opz.fornitoreId;
  if (!faProd && !faForn) return;

  const voti = { prod: 0, forn: 0 };
  const blocco = (target, titolo, nome, segnaposto) => `
    <fieldset class="rec-blocco" data-target="${target}">
      <legend>${titolo}</legend>
      <p class="rec-nome">${esc(nome)}</p>
      <div class="rec-stelle" role="group" aria-label="Voto da 1 a 5 stelle">
        ${[1, 2, 3, 4, 5].map(i => `<button type="button" class="rec-stella" data-voto="${i}" aria-pressed="false" aria-label="${i} ${i === 1 ? 'stella' : 'stelle'}">&#9733;</button>`).join('')}
      </div>
      <textarea class="rec-testo input" rows="2" maxlength="1000" placeholder="${esc(segnaposto)}" aria-label="Commento (facoltativo)"></textarea>
      <p class="rec-errore" role="alert" hidden></p>
    </fieldset>`;

  document.body.insertAdjacentHTML('beforeend', `
    <div id="rec-popup-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="rec-titolo">
      <div class="modal-content card rec-modal">
        <div class="card-content">
          <h3 id="rec-titolo">Lascia una recensione</h3>
          <p class="rec-intro">per il tuo acquisto ritirato.</p>
          ${faProd ? blocco('prod', 'Il prodotto', opz.nomeProdotto || 'questo prodotto', "Com'è il prodotto? (max 1000 caratteri)") : ''}
          ${faForn ? blocco('forn', 'Il fornitore', opz.nomeFornitore || 'questo fornitore', "Com'è andato l'acquisto? (max 1000 caratteri)") : ''}
          <div class="rec-azioni">
            <button type="button" class="btn btn-default rec-invia">Invia ${faProd && faForn ? 'recensioni' : 'recensione'}</button>
            <button type="button" class="btn btn-ghost rec-chiudi">Chiudi</button>
          </div>
        </div>
      </div>
    </div>`);

  const modal = document.getElementById('rec-popup-modal');
  const chiudi = () => { document.removeEventListener('keydown', tasto); modal.remove(); };
  const tasto = (e) => { if (e.key === 'Escape') chiudi(); };
  document.addEventListener('keydown', tasto);
  modal.querySelector('.rec-stella')?.focus();

  const errore = (target, testo) => {
    const el = modal.querySelector(`.rec-blocco[data-target="${target}"] .rec-errore`);
    if (!el) return;
    el.textContent = testo || '';
    el.hidden = !testo;
  };

  modal.addEventListener('click', async (e) => {
    if (e.target === modal || e.target.closest('.rec-chiudi')) { chiudi(); return; }

    const stella = e.target.closest('.rec-stella');
    if (stella) {
      const target = stella.closest('.rec-blocco').dataset.target;
      voti[target] = parseInt(stella.dataset.voto);
      stella.closest('.rec-stelle').querySelectorAll('.rec-stella').forEach((s, i) => {
        s.classList.toggle('attiva', i < voti[target]);
        s.setAttribute('aria-pressed', String(i + 1 === voti[target]));
      });
      errore(target, '');
      return;
    }

    if (!e.target.closest('.rec-invia')) return;
    let valido = true;
    if (faProd && !(voti.prod >= 1 && voti.prod <= 5)) { errore('prod', 'Seleziona un voto da 1 a 5 stelle per il prodotto.'); valido = false; }
    if (faForn && !(voti.forn >= 1 && voti.forn <= 5)) { errore('forn', 'Seleziona un voto da 1 a 5 stelle per il fornitore.'); valido = false; }
    if (!valido) return;

    const bottone = e.target.closest('.rec-invia');
    bottone.disabled = true;
    const testo = (target) => modal.querySelector(`.rec-blocco[data-target="${target}"] .rec-testo`)?.value.trim() || '';
    const invia = (target, url, voto) => apiPost(url, { voto, testo: testo(target) })
      .then(() => ({ ok: true }))
      .catch(err => ({ ok: false, target, msg: err.message }));
    const esiti = await Promise.all([
      faProd ? invia('prod', `/campagne/${opz.campagnaId}/recensioni`, voti.prod) : { ok: true },
      faForn ? invia('forn', `/fornitori/${opz.fornitoreId}/recensioni`, voti.forn) : { ok: true }
    ]);
    esiti.filter(x => !x.ok).forEach(x => errore(x.target, x.msg));
    if (esiti.some(x => !x.ok)) { bottone.disabled = false; return; }

    chiudi();
    showToast({ title: 'Grazie per le recensioni!', variant: 'success', duration: 3000 });
    if (typeof opz.dopo === 'function') opz.dopo();
  });
}
