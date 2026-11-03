import { setPageInterval, setPageTimeout, clearPageTimers } from './page-timers.js';

/**
 * Formatta il tempo residuo fino a `target` come "{g}gg {hh}:{mm}:{ss}"
 * (giorni senza zeri iniziali); sotto le 24 ore solo "{hh}:{mm}:{ss}".
 * Ritorna 'Scaduta' se passato, null se la data non è valida.
 */
export function formatCountdown(target) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const diff = t - Date.now();
  if (diff <= 0) return 'Scaduta';
  const s = Math.floor(diff / 1000);
  const g = Math.floor(s / 86400);
  const hh = String(Math.floor((s % 86400) / 3600)).padStart(2, '0');
  const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
  const ss = String(s % 60).padStart(2, '0');
  const hms = `${hh}:${mm}:${ss}`;
  return g > 0 ? `${g}gg ${hms}` : hms;
}

/**
 * Livello di urgenza in base al tempo residuo:
 * 'now' (<= 24h), 'soon' (<= 3 giorni), '' oltre. Null se data non valida o scaduta.
 */
export function urgencyLevel(target) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const diff = t - Date.now();
  if (diff <= 0) return null;
  if (diff <= 24 * 3600 * 1000) return 'now';
  if (diff <= 3 * 24 * 3600 * 1000) return 'soon';
  return '';
}

/**
 * Aggiorna tutti gli span `.countdown-live` presenti nel DOM
 * leggendo la scadenza da `data-scadenza`, e il livello di urgenza
 * del box genitore `[data-urgency]` (se presente).
 */
export function tickCountdowns() {
  document.querySelectorAll('.countdown-live').forEach(el => {
    const txt = formatCountdown(el.dataset.scadenza);
    el.textContent = txt === null ? '—' : txt;
    const box = el.closest('[data-urgency]');
    if (box) {
      const lvl = urgencyLevel(el.dataset.scadenza);
      box.classList.toggle('countdown-soon', lvl === 'soon');
      box.classList.toggle('countdown-now', lvl === 'now');
    }
  });
}

/**
 * Avvia il tick ogni secondo (cancella prima eventuali timer di pagina).
 * `onTick`, se passato, viene eseguito dopo ogni aggiornamento.
 */
export function startCountdowns(onTick) {
  clearPageTimers();
  tickCountdowns();
  setPageInterval(() => {
    tickCountdowns();
    if (typeof onTick === 'function') onTick();
  }, 1000);
}

/**
 * ============================================================
 *  SCADENZA DETTAGLIO GRUPPO — giorni : ore : minuti
 * ============================================================
 *
 * Formato visivo: "18g 12h 8m" (senza zeri iniziali, senza secondi;
 * giorni 0 -> "0g : 5h : 12m"). Ritorna 'Gruppo scaduto' se il target
 * e' passato, null se la data non e' valida.
 */
export function formatScadenzaGHM(target, now = Date.now()) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const diff = t - now;
  if (diff <= 0) return 'Gruppo scaduto';
  const s = Math.floor(diff / 1000);
  const g = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  return `${g}g ${h}h ${m}m`;
}

/** Testo completo per aria-label: "Scade tra 18 giorni, 12 ore e 8 minuti". */
export function scadenzaAriaLabel(target, now = Date.now()) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return 'Scadenza non disponibile';
  const diff = t - now;
  if (diff <= 0) return 'Gruppo scaduto';
  const s = Math.floor(diff / 1000);
  const g = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const parte = (n, sing, plur) => `${n} ${n === 1 ? sing : plur}`;
  const pezzi = [parte(g, 'giorno', 'giorni'), parte(h, 'ora', 'ore'), parte(m, 'minuto', 'minuti')];
  return `Scade tra ${pezzi[0]}, ${pezzi[1]} e ${pezzi[2]}`;
}

/**
 * Aggiorna gli span `.scadenza-live` leggendo `data-scadenza`, e l'aria-label
 * del contenitore `[data-scadenza-box]`.
 */
export function tickScadenze() {
  document.querySelectorAll('.scadenza-live').forEach(el => {
    const txt = formatScadenzaGHM(el.dataset.scadenza);
    el.textContent = txt === null ? '—' : txt;
    const box = el.closest('[data-scadenza-box]');
    if (box) box.setAttribute('aria-label', scadenzaAriaLabel(el.dataset.scadenza));
  });
  document.querySelectorAll('[data-scadenza-blocchi]').forEach(el => {
    el.innerHTML = scadenzaBlocchiHtml(el.dataset.scadenzaBlocchi);
    const box = el.closest('[data-scadenza-box]');
    if (box) box.setAttribute('aria-label', scadenzaAriaLabel(el.dataset.scadenzaBlocchi));
  });
}

/** Vero se un timer a blocchetti della pagina ha meno di 24 ore (serve il tick al secondo per i SEC). */
function sottoLe24Ore() {
  return [...document.querySelectorAll('[data-scadenza-blocchi]')].some(el => scadenzaBlocchi(el.dataset.scadenzaBlocchi)?.modo === 'oms');
}

/**
 * Avvia l'aggiornamento ALLINEATO al cambio di minuto (un tick al minuto,
 * non al secondo). Cancella prima i timer di pagina.
 */
export function startScadenzaMinuti() {
  clearPageTimers();
  tickScadenze();
  // Sotto le 24 ore il timer mostra anche i secondi: tick al secondo; altrimenti uno al minuto
  // (e passa al secondo quando si scende sotto le 24 ore).
  if (sottoLe24Ore()) {
    setPageInterval(tickScadenze, 1000);
    return;
  }
  const alMinuto = 60000 - (Date.now() % 60000);
  setPageTimeout(() => {
    tickScadenze();
    if (sottoLe24Ore()) { setPageInterval(tickScadenze, 1000); return; }
    setPageInterval(() => {
      tickScadenze();
      if (sottoLe24Ore()) startScadenzaMinuti();
    }, 60000);
  }, alMinuto);
}

/**
 * ============================================================
 *  TIMER A BLOCCHETTI (dettaglio) — GIORNI/ORE/MIN, sotto 24 ore ORE/MIN/SEC
 * ============================================================
 * Ritorna { modo: 'gmo' | 'oms' | 'scaduto', blocchi: [{ n, etichetta }] }, null se la data non è valida.
 */
export function scadenzaBlocchi(target, now = Date.now()) {
  const t = new Date(target).getTime();
  if (!Number.isFinite(t)) return null;
  const diff = t - now;
  if (diff <= 0) return { modo: 'scaduto', blocchi: [] };
  const s = Math.floor(diff / 1000);
  const g = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  const due = (n) => String(n).padStart(2, '0');
  if (g >= 1) return { modo: 'gmo', blocchi: [{ n: due(g), etichetta: 'GIORNI' }, { n: due(h), etichetta: 'ORE' }, { n: due(m), etichetta: 'MIN' }] };
  return { modo: 'oms', blocchi: [{ n: due(h), etichetta: 'ORE' }, { n: due(m), etichetta: 'MIN' }, { n: due(s % 60), etichetta: 'SEC' }] };
}

/** HTML dei blocchetti (o "Scaduto"); '—' se la data non è valida. */
export function scadenzaBlocchiHtml(target, now = Date.now()) {
  const r = scadenzaBlocchi(target, now);
  if (!r) return '<span class="bp-scad-scaduto">&mdash;</span>';
  if (r.modo === 'scaduto') return '<span class="bp-scad-scaduto">Scaduto</span>';
  return r.blocchi.map(b => `<span class="bp-scad-b"><b>${b.n}</b><i>${b.etichetta}</i></span>`).join('');
}
