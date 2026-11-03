// Parti condivise delle card gruppo e del dettaglio: badge sconto, prezzo mostrato, percorso degli scaglioni, pillola persone.
// Funzioni pure (nessun accesso a DOM, stato o rete): ricevono l'oggetto gruppo dell'API (`/campagne`) e restituiscono dati o HTML.
// Solo presentazione: i prezzi sono quelli degli scaglioni già calcolati, qui non si ricalcola nulla.
// Uso: tests/gruppo_card_test.mjs.
import { euro } from '../format.js';
import { statoScaglioni } from '../scaglioni-stato.js';
import { sogliaPrima } from '../stato-card.js';

const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const num = (v) => parseFloat(v) || 0;
const unita = (n) => `${n} unità`;

/** Scaglioni numerici; senza scaglioni resta un solo gradino (il minimo) al prezzo corrente. */
function gradini(c) {
  const s = Array.isArray(c.scaglioni) ? c.scaglioni : [];
  const lista = s.length ? s : [{ soglia: sogliaPrima(c), prezzo: c.prezzo_corrente }];
  return lista.map(g => ({ soglia: parseInt(g.soglia) || 0, prezzo: num(g.prezzo) }));
}

/** Unica formula dello sconto sul listino, usata da card e dettaglio: round(100*(listino-prezzo)/listino); 0 senza listino o prezzo. */
export const scontoPct = (listino, prezzo) => (listino > 0 && prezzo > 0 ? Math.round(100 * (listino - prezzo) / listino) : 0);
const sconto = scontoPct;

/** Sconto dell'ultimo scaglione rispetto al listino (0 se il listino non esiste). */
export function scontoMassimo(c) {
  const s = Array.isArray(c.scaglioni) ? c.scaglioni : [];
  if (!s.length) return 0;
  return sconto(num(c.prezzo_base), num(s[s.length - 1].prezzo));
}

/**
 * Prezzo "in grande": con il minimo raggiunto è lo scaglione corrente, altrimenti il PRIMO scaglione (il MOQ).
 * scontoPct = sconto di quel prezzo rispetto al listino (0 se non c'è listino).
 */
export function prezzoMostrato(c) {
  const g = gradini(c);
  const qty = parseInt(c.quantita_attuale) || 0;
  const stati = statoScaglioni(g, qty);
  const iCorrente = stati.indexOf('attivo');
  const attivo = iCorrente >= 0;
  const gradino = attivo ? g[iCorrente] : g[0];
  const listino = num(c.prezzo_base);
  return { attivo, prezzo: gradino.prezzo, listino, scontoPct: sconto(listino, gradino.prezzo), sogliaPrima: g[0].soglia, primoPrezzo: g[0].prezzo };
}

/** Punti del percorso (listino + un punto per scaglione), avanzamento 0..1 e prossimo traguardo. */
export function percorsoModel(c) {
  const g = gradini(c);
  const qty = parseInt(c.quantita_attuale) || 0;
  const stati = statoScaglioni(g, qty);
  const listino = num(c.prezzo_base);
  const punti = [];
  if (listino > 0) punti.push({ tipo: 'listino', soglia: 0, prezzo: listino, stato: 'superato' });
  g.forEach((x, i) => punti.push({ tipo: 'scaglione', soglia: x.soglia, prezzo: x.prezzo, stato: stati[i] }));

  // La barra va da un punto al successivo in proporzione alle unità; i punti sono equidistanti.
  const n = punti.length;
  let ultimo = -1;
  punti.forEach((p, i) => { if (qty >= p.soglia) ultimo = i; });
  let riempi = 0;
  if (n === 1) riempi = ultimo === 0 ? 1 : 0;
  else if (ultimo >= n - 1) riempi = 1;
  else if (ultimo >= 0) {
    const da = punti[ultimo].soglia;
    const a = punti[ultimo + 1].soglia;
    riempi = (ultimo + (a > da ? Math.min(1, (qty - da) / (a - da)) : 0)) / (n - 1);
  } else {
    riempi = 0;
  }
  const prossimo = g.find((x, i) => stati[i] === 'da_raggiungere') || null;
  return {
    qty, punti, riempi,
    prossimo: prossimo ? { mancano: Math.max(0, prossimo.soglia - qty), soglia: prossimo.soglia, prezzo: prossimo.prezzo } : null,
    prezzoAttuale: stati.indexOf('attivo') >= 0 ? g[stati.indexOf('attivo')].prezzo : null
  };
}

/** Testo alternativo del percorso, ad esempio "12 unità: prezzo attuale €42,99, mancano 8 unità per €40,99". */
export function percorsoAria(c) {
  const m = percorsoModel(c);
  const parti = [m.prezzoAttuale != null ? `prezzo attuale ${euro(m.prezzoAttuale)}` : 'prezzo di gruppo non ancora attivo'];
  parti.push(m.prossimo ? `mancano ${unita(m.prossimo.mancano)} per ${euro(m.prossimo.prezzo)}` : 'prezzo più basso raggiunto');
  return `${unita(m.qty)}: ${parti.join(', ')}`;
}

const SPUNTA = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

function puntoHtml(p, i, n, listino) {
  const left = n === 1 ? 50 : (i / (n - 1)) * 100;
  if (p.tipo === 'listino') {
    return `<span class="gp-pt gp-pt-listino" style="left:${left}%"><span class="gp-pt-top"></span><span class="gp-pt-dot"></span><span class="gp-pt-soglia">Listino</span><span class="gp-pt-prezzo"><s>${euro(p.prezzo)}</s></span></span>`;
  }
  const classe = (p.stato === 'attivo' ? 'gp-pt-now' : (p.stato === 'superato' ? 'gp-pt-done' : 'gp-pt-todo')) + (euro(p.prezzo).length > 8 ? ' gp-pt-lungo' : '');
  const ora = p.stato === 'attivo' ? '<span class="gp-pt-top">ORA</span>' : '<span class="gp-pt-top"></span>';
  const dot = p.stato === 'da_raggiungere' ? '<span class="gp-pt-dot"></span>' : `<span class="gp-pt-dot">${SPUNTA}</span>`;
  const pct = scontoPct(listino, p.prezzo);
  return `<span class="gp-pt ${classe}" style="left:${left}%">${ora}${dot}<span class="gp-pt-soglia">${unita(p.soglia)}</span><span class="gp-pt-prezzo">${euro(p.prezzo)}</span>${pct > 0 ? `<span class="gp-pt-pct">(-${pct}%)</span>` : ''}</span>`;
}

/** Icona orologio (stesso tracciato SVG del dettaglio), della dimensione richiesta. */
export const iconaOrologio = (size = 14) => `<svg width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;

const ICONA_PERSONA = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 12a4.5 4.5 0 100-9 4.5 4.5 0 000 9zm0 2c-4 0-7.5 2-7.5 5v1.5h15V19c0-3-3.5-5-7.5-5z"/></svg>';

/** Avatar sovrapposti con le iniziali (fino a 3 + "+N"); '' se nessuno si è unito. Le iniziali arrivano dall'API solo agli utenti loggati. Usato da pillola e intestazione del percorso. */
function avatarsHtml(c, { contatore = true } = {}) {
  const persone = parseInt(c.partecipanti) || 0;
  if (persone <= 0) return '';
  // Iniziali reali (fino a 3 + "+N"); senza iniziali (ospite) un cerchietto con icona persona.
  const ini = (Array.isArray(c.partecipanti_iniziali) ? c.partecipanti_iniziali : []).filter(Boolean).slice(0, 3);
  const avatar = ini.length
    ? ini.map(i => `<span class="gp-av">${esc(i)}</span>`)
    : [`<span class="gp-av gp-av-gen">${ICONA_PERSONA}</span>`];
  const resto = persone - avatar.length;
  const piu = contatore && resto > 0 ? `<span class="gp-av gp-av-more">+${resto}</span>` : '';
  return `<span class="gp-avs" aria-hidden="true">${avatar.join('')}${piu}</span>`;
}

export function pillPersoneHtml(c) {
  const persone = parseInt(c.partecipanti) || 0;
  if (persone <= 0) return '';
  const testo = persone === 1 ? '1 persona si è unita' : `${persone} persone si sono unite`;
  return `<span class="gp-people">${avatarsHtml(c)}<span class="gp-people-txt">${testo}</span></span>`;
}

/** Badge sconto appeso al bordo superiore dell'immagine: "FINO A / -35% / DI SCONTO"; '' senza sconto. */
export function badgeScontoHtml(pct, { spento = false, grande = false } = {}) {
  if (!(pct > 0)) return '';
  return `<span class="gp-badge${grande ? ' gp-badge-lg' : ''}${spento ? ' gp-badge-spento' : ''}" role="img" aria-label="Fino a -${pct}% di sconto"><span class="gp-badge-s" aria-hidden="true">FINO A</span><span class="gp-badge-n" aria-hidden="true">-${pct}%</span><span class="gp-badge-s" aria-hidden="true">DI SCONTO</span></span>`;
}

/** Blocco prezzo della card: prezzo in grande, listino barrato e riga esplicativa (regola del prezzo mostrato). */
export function prezzoBloccoHtml(c) {
  const p = prezzoMostrato(c);
  const riga = p.attivo ? 'prezzo unitario ora' : `prezzo unitario appena il gruppo arriva a ${unita(p.sogliaPrima)}`;
  return `<div class="gp-price">
      <div class="gp-price-main"><span class="gp-price-big">${euro(p.prezzo)}</span>${p.listino > 0 ? `<s class="gp-price-list">${euro(p.listino)}</s>` : ''}</div>
      <div class="gp-price-sub">${riga}</div>
    </div>`;
}

/**
 * Percorso degli scaglioni (non interattivo, role="img").
 * variante 'card': intestazione "<b>N</b> unità in totale" + "del gruppo, non a testa" e "Mancano N unità per €prezzo".
 * variante 'largo' (dettaglio): avatar + "N unità in totale" + "prenotate da K persone del gruppo" e "Mancano N unità per €Y".
 */
export function percorsoHtml(c, { variante = 'card', esito = '' } = {}) {
  const m = percorsoModel(c);
  const largo = variante === 'largo';
  const persone = parseInt(c.partecipanti) || 0;
  const prossimo = esito ? esc(esito) : m.prossimo
    ? `Mancano <b>${unita(m.prossimo.mancano)}</b> per <b>${euro(m.prossimo.prezzo)}</b>`
    : 'Prezzo più basso raggiunto';
  const totale = `<span class="gp-path-tot"><b>${m.qty}</b> unità in totale</span>`;
  const sinistra = largo
    ? `<span class="gp-path-qty">${avatarsHtml(c, { contatore: false })}<span class="gp-path-qty-txt">${totale}${persone > 0 ? `<span class="gp-path-sub">prenotate da ${persone} ${persone === 1 ? 'persona' : 'persone'} del gruppo</span>` : ''}</span></span>`
    : `<span class="gp-path-qty"><span class="gp-path-qty-txt">${totale}<span class="gp-path-sub">del gruppo, non a testa</span></span></span>`;
  const n = m.punti.length;
  return `<div class="gp-path gp-path-${largo ? 'largo' : 'card'}" role="img" aria-label="${esc(percorsoAria(c))}">
      <div class="gp-path-head">${sinistra}<span class="gp-path-next">${prossimo}</span></div>
      <div class="gp-path-track"><span class="gp-path-bar"><span class="gp-path-fill" style="width:${(m.riempi * 100).toFixed(1)}%"></span></span>${m.punti.map((p, i) => puntoHtml(p, i, n, num(c.prezzo_base))).join('')}</div>
    </div>`;
}

/** Riga sotto il prezzo del dettaglio: "Prezzo unitario". */
export function scontoRigaHtml(c) {
  const g = gradini(c);
  const ultimo = g[g.length - 1];
  const pct = scontoMassimo(c);
  const fino = g.length > 1 ? 'fino a ' : '';
  const sc = pct > 0 ? `${fino}<span class="gp-sconto">-${pct}%</span> ` : '';
  return `Prezzo unitario`;
}
