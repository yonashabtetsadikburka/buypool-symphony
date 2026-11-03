/** Escape HTML completo: da usare per ogni dato utente messo in innerHTML. */
export function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function plurale(n, singolare, pluraleStr) {
  return `${n} ${n === 1 ? singolare : pluraleStr}`;
}

/** "3 giorni fa" da una data MySQL ("YYYY-MM-DD HH:MM:SS"). */
export function tempoRelativo(data) {
  const d = new Date(String(data).replace(' ', 'T'));
  if (isNaN(d)) return '';
  const sec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
  if (sec < 60) return 'adesso';
  const min = Math.floor(sec / 60);
  if (min < 60) return `${plurale(min, 'minuto', 'minuti')} fa`;
  const ore = Math.floor(min / 60);
  if (ore < 24) return `${plurale(ore, 'ora', 'ore')} fa`;
  const giorni = Math.floor(ore / 24);
  if (giorni === 1) return 'ieri';
  if (giorni < 7) return `${giorni} giorni fa`;
  if (giorni < 30) return `${plurale(Math.floor(giorni / 7), 'settimana', 'settimane')} fa`;
  return `${plurale(Math.floor(giorni / 30), 'mese', 'mesi')} fa`;
}

/** Solo il dominio di un link http/https (senza "www."), altrimenti null. */
export function dominioDa(url) {
  try {
    const u = new URL(url);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    return u.hostname.replace(/^www\./, '');
  } catch (e) {
    return null;
  }
}
