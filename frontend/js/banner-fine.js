/* Banner del dettaglio di un gruppo terminato (concluso o non attivato).
   Il modello e' una funzione pura, testata in tests/banner_fine_test.mjs; i valori vengono sempre escapati nell'HTML. */

import { euro } from './format.js';

const LINK_APERTI = '#/';

/** Stato reale dell'adesione (vista di "I miei acquisti") in parole. */
const STATO_ORDINE = {
  in_corso: 'ordine in preparazione',
  attivo: 'ordine in preparazione',
  inviato: 'ordine in preparazione',
  pronto: 'pronto per il ritiro',
  ritirato: 'ritirato',
  pagamento: 'pagamento del saldo da completare'
};

const pezziTesto = (n) => `${n} unità`;

/**
 * @param {{display:string, aderente:boolean, quantita?:number, vista?:string, spedizione?:boolean,
 *          acconto?:number, prezzoFinale?:number}} o
 *   display: 'conclusa' | 'in_chiusura' | 'non_riuscita'
 * @returns {{icona:'spunta'|'info', titolo:string, dettaglio:string, azioni:{testo:string, href:string, primaria:boolean}[]}}
 */
export function modelloBannerFine(o) {
  const nonAttivato = o.display === 'non_riuscita';
  const aperti = { testo: 'Vedi i gruppi aperti →', href: LINK_APERTI, primaria: false };

  if (nonAttivato) {
    const titolo = 'Questo gruppo non ha raggiunto il minimo';
    if (!o.aderente) return { icona: 'info', titolo, dettaglio: 'Gli acconti sono stati rimborsati.', azioni: [aperti] };
    // Nessuna data di rimborso e' salvata (ne' nel database ne' mostrata da Stripe al rendering): niente data
    const dettaglio = o.acconto > 0 ? `Il tuo acconto di ${euro(o.acconto)} è stato rimborsato.` : 'Il tuo acconto è stato rimborsato.';
    return { icona: 'info', titolo, dettaglio,
      azioni: [{ testo: 'Vai ai miei acquisti', href: '#/acquisti?tab=annullati', primaria: true }, aperti] };
  }

  const titolo = 'Questo gruppo è concluso';
  if (!o.aderente) {
    const prezzo = o.prezzoFinale > 0 ? ` Prezzo finale ${euro(o.prezzoFinale)}.` : '';
    return { icona: 'spunta', titolo, dettaglio: `Il gruppo ha raggiunto il minimo.${prezzo}`, azioni: [aperti] };
  }
  let stato = STATO_ORDINE[o.vista] || 'ordine in preparazione';
  if (o.vista === 'ritirato' && o.spedizione) stato = 'consegnato';
  const n = Math.max(0, parseInt(o.quantita) || 0);
  const tab = o.vista === 'ritirato' ? 'conclusi' : (o.vista === 'attivo' || o.vista === 'in_corso' || o.vista === 'pagamento' ? 'in-corso' : 'in-arrivo');
  return { icona: 'spunta', titolo, dettaglio: `Hai aderito con ${pezziTesto(n)} · ${stato}`,
    azioni: [{ testo: 'Vai ai miei acquisti', href: `#/acquisti?tab=${tab}`, primaria: true }, aperti] };
}

const esc = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const ICONE = {
  spunta: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>',
  info: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 11v5m0-9h.01"/>'
};

/** HTML del banner: una riga (colonna su mobile), subito sotto "Torna ai gruppi". */
export function bannerFineHtml(m) {
  const azioni = m.azioni.map(a =>
    `<a class="bp-banner-fine-btn${a.primaria ? ' is-primaria' : ''}" href="${esc(a.href)}">${esc(a.testo)}</a>`).join('');
  return `
    <section class="bp-banner-fine" role="status" aria-labelledby="bp-banner-fine-titolo">
      <span class="bp-banner-fine-icona" aria-hidden="true">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" focusable="false">${ICONE[m.icona]}</svg>
      </span>
      <div class="bp-banner-fine-testi">
        <div class="bp-banner-fine-titolo" id="bp-banner-fine-titolo">${esc(m.titolo)}</div>
        <div class="bp-banner-fine-dettaglio">${esc(m.dettaglio)}</div>
      </div>
      <div class="bp-banner-fine-azioni">${azioni}</div>
    </section>`;
}
