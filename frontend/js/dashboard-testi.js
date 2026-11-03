/* Testi e modello dati della Dashboard (funzioni pure, testate in tests/dashboard_testi_test.mjs).
   I numeri arrivano dall'API (/mie/dashboard), calcolati con la stessa classificazione di "I miei acquisti". */

import { gruppi, ritiri } from './format.js';

export const LINK_RITIRI = '#/acquisti?tab=in-arrivo';
export const LINK_GRUPPI = '#/acquisti?tab=in-corso';

const num = (v) => Math.max(0, parseInt(v) || 0);

/**
 * Frase sotto "Ciao <Nome>": parti a 0 omesse, plurali corretti.
 * I numeri sono link: i ritiri portano alla scheda "In arrivo", i gruppi alla scheda "In corso".
 * Ritorna HTML (i valori sono numeri, nessun input utente).
 */
export function fraseRiepilogo(nRitiri, nGruppi) {
  const r = num(nRitiri);
  const g = num(nGruppi);
  const linkR = `<a class="dsh-frase-link" href="${LINK_RITIRI}"><strong>${ritiri(r)} ${r === 1 ? 'pronto' : 'pronti'}</strong></a>`;
  const linkG = `<a class="dsh-frase-link" href="${LINK_GRUPPI}"><strong>${gruppi(g)} in corso</strong></a>`;
  if (r > 0 && g > 0) return `Hai ${linkR} e partecipi a ${linkG}.`;
  if (r > 0) return `Hai ${linkR}.`;
  if (g > 0) return `Partecipi a ${linkG}.`;
  // L'unica situazione in cui compare l'invito a scoprire i gruppi
  return 'Non hai gruppi in corso. <a class="dsh-frase-link" href="#/">Scopri i gruppi a cui puoi unirti.</a>';
}

/** "Via X, Città · orari": solo le parti presenti; '' se la sede non ha dati. */
export function sedeTesto(s) {
  if (!s) return '';
  const luogo = [s.sede_indirizzo, s.sede_citta].filter(Boolean).join(', ');
  const dettagli = [luogo, s.sede_orari].filter(Boolean).join(' · ');
  if (!s.sede_nome && !dettagli) return '';
  return `${s.sede_nome || 'sede BuyPool'}${dettagli ? `: ${dettagli}` : ''}`;
}

/**
 * Modello del blocco "Da fare": una riga per ogni ritiro pronto e per ogni saldo non riuscito,
 * i pagamenti (urgenti) prima dei ritiri; riga sede solo se c'e' almeno un ritiro pronto.
 * Vuoto = nessuna riga: l'interfaccia mostra "Tutto in ordine: non hai nulla da fare."
 */
export function modelloDaFare(daFare) {
  const ritiriPronti = Array.isArray(daFare?.ritiri) ? daFare.ritiri : [];
  const saldi = Array.isArray(daFare?.saldi_non_riusciti) ? daFare.saldi_non_riusciti : [];
  const righe = [
    ...saldi.map(s => ({ tipo: 'saldo', id: s.id, prodotto: s.prodotto, quantita: num(s.quantita), scadenza: s.saldo_scadenza || null })),
    ...ritiriPronti.map(r => ({ tipo: 'ritiro', id: r.id, prodotto: r.prodotto, quantita: num(r.quantita) }))
  ];
  const sedi = [];
  ritiriPronti.forEach(r => { const t = sedeTesto(r); if (t && !sedi.includes(t)) sedi.push(t); });
  return { righe, sedi, vuoto: righe.length === 0 };
}
