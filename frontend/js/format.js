/**
 * Formattazione condivisa (importi all'italiana, plurali, tempi). Nessuna dipendenza dal DOM.
 */

/** 3659.58 -> "€3.659,58" */
export function euro(n) {
  const v = Number(n);
  const x = Number.isFinite(v) ? v : 0;
  // Formattazione manuale: it-IT non raggruppa le migliaia sotto 10.000 in tutti i browser
  const [intero, dec] = Math.abs(x).toFixed(2).split('.');
  const conPunti = intero.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${x < 0 && Number(intero + dec) !== 0 ? '-' : ''}€${conPunti},${dec}`;
}

/** plurale(1, 'voto', 'voti') -> "1 voto"; plurale(3, ...) -> "3 voti" */
export function plurale(n, singolare, pluraleStr) {
  const v = Number(n) || 0;
  return `${v} ${v === 1 ? singolare : pluraleStr}`;
}

export const pezzi = (n) => plurale(n, 'unità', 'unità');
export const voti = (n) => plurale(n, 'voto', 'voti');
export const gruppi = (n) => plurale(n, 'gruppo', 'gruppi');
export const ritiri = (n) => plurale(n, 'ritiro', 'ritiri');
export const recensioni = (n) => plurale(n, 'recensione', 'recensioni');
export const persone = (n) => plurale(n, 'persona', 'persone');

/** "2026-10-12 09:30:00" -> "12/10/2026" */
export function dataGGMMAAAA(valore) {
  const d = leggiData(valore);
  if (!d) return '';
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
}

/** "12/10" (senza anno) */
export function dataGGMM(valore) {
  return dataGGMMAAAA(valore).slice(0, 5);
}

/** "09:30" */
export function oraHHMM(valore) {
  const d = leggiData(valore);
  if (!d) return '';
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function leggiData(valore) {
  const d = valore instanceof Date ? valore : new Date(String(valore).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

/** "Scade tra 18g 12h" (>= 1 giorno), "Scade tra 5h 20m" (< 1 giorno), "Scaduto". `adesso` serve ai test. */
export function scadenzaTesto(dataLimite, adesso = Date.now()) {
  const d = leggiData(dataLimite);
  if (!d) return '';
  const minuti = Math.floor((d.getTime() - adesso) / 60000);
  if (minuti <= 0) return 'Scaduto';
  const giorni = Math.floor(minuti / 1440);
  const ore = Math.floor((minuti % 1440) / 60);
  if (giorni >= 1) return `Scade tra ${giorni}g ${ore}h`;
  return `Scade tra ${ore}h ${minuti % 60}m`;
}

/** Scadenza da secondi gia' calcolati dal server: "Scade tra 6g 20h", "Scade tra 5h 20m", "Scade a momenti", "Scaduto". */
export function scadenzaDaSecondi(secondi) {
  const s = Number(secondi);
  if (!Number.isFinite(s) || s <= 0) return 'Scaduto';
  const minuti = Math.floor(s / 60);
  if (minuti < 1) return 'Scade a momenti';
  const giorni = Math.floor(minuti / 1440);
  const ore = Math.floor((minuti % 1440) / 60);
  return giorni >= 1 ? `Scade tra ${giorni}g ${ore}h` : `Scade tra ${ore}h ${minuti % 60}m`;
}

/**
 * Motivazione mostrata per un rimborso automatico. `r` = { scadenza, pezzi, minimo }.
 * Nel DB non c'e' un motivo salvato: lo ricaviamo da scadenza e pezzi del gruppo.
 */
export function motivoRimborso(r) {
  if (!r) return '';
  if (Number(r.pezzi) < Number(r.minimo)) {
    return `Il gruppo non ha raggiunto il minimo entro la scadenza (${dataGGMMAAAA(r.scadenza)}): ${plurale(r.pezzi, 'unità', 'unità')} su ${r.minimo} richiesti.`;
  }
  return 'Il gruppo non è andato a buon fine: ti restituiamo l\'acconto.';
}

/** Date delle notifiche: "oggi", "ieri", "2 giorni fa", poi la data "12/10/2026". */
export function dataRelativa(data, adesso = Date.now()) {
  const d = leggiData(data);
  if (!d) return '';
  const inizio = (t) => { const x = new Date(t); x.setHours(0, 0, 0, 0); return x.getTime(); };
  const giorni = Math.round((inizio(adesso) - inizio(d)) / 86400000);
  if (giorni <= 0) return 'oggi';
  if (giorni === 1) return 'ieri';
  if (giorni < 7) return `${giorni} giorni fa`;
  return d.toLocaleDateString('it-IT');
}
