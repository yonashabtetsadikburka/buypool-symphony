/* Adattatore tra il frontend ereditato dal legacy e la nuova API Symfony.
   Le pagine continuano a parlare "legacy" (percorsi italiani, importi in euro); qui, in un solo punto:
   - i percorsi vecchi diventano quelli nuovi (/api/...);
   - le risposte con importi in centesimi (`*_cents`) ricevono anche il campo in euro che le pagine si aspettano;
   - i payload che contengono euro (prezzi) escono in centesimi.
   Cosa non è ancora migrato risponde 404: le pagine che lo usano restano non funzionanti. */

/** @type {{ metodo: string|null, da: RegExp, a: (m: RegExpMatchArray) => string, metodoNuovo?: string }[]} */
const REGOLE = [
  { metodo: 'POST', da: /^\/login$/, a: () => '/api/auth/login' },
  { metodo: 'POST', da: /^\/registrazione$/, a: () => '/api/auth/register' },
  { metodo: 'POST', da: /^\/logout$/, a: () => '/api/auth/logout' },
  { metodo: 'GET', da: /^\/campagne$/, a: () => '/api/campaigns' },
  { metodo: 'GET', da: /^\/campagne\/([0-9a-f-]{36})$/i, a: (m) => `/api/campaigns/${m[1]}` },
  { metodo: 'POST', da: /^\/campagne$/, a: () => '/api/campaigns' },
  { metodo: 'POST', da: /^\/campagne\/([0-9a-f-]{36})\/modifica$/i, a: (m) => `/api/campaigns/${m[1]}`, metodoNuovo: 'PATCH' },
  { metodo: 'DELETE', da: /^\/campagne\/([0-9a-f-]{36})$/i, a: (m) => `/api/campaigns/${m[1]}` },
  { metodo: 'POST', da: /^\/pagamento\/acconto-checkout$/, a: () => '/api/payments/deposit-checkout' },
  { metodo: 'POST', da: /^\/campagne\/([0-9a-f-]{36})\/chiusura$/i, a: (m) => `/api/campaigns/${m[1]}/close` },
  { metodo: 'POST', da: /^\/admin\/gruppi\/([0-9a-f-]{36})\/annulla$/i, a: (m) => `/api/campaigns/${m[1]}/cancel` },
  { metodo: 'POST', da: /^\/campagne\/([0-9a-f-]{36})\/invia-fornitore$/i, a: (m) => `/api/campaigns/${m[1]}/send-to-supplier` },
  { metodo: 'POST', da: /^\/pagamento\/checkout$/, a: () => '/api/payments/balance-checkout' },
  { metodo: 'GET', da: /^\/pagamento\/stato$/, a: () => '/api/payments/status' },
];

/** Percorso e metodo nuovi per una chiamata legacy; se non c'è una regola resta com'è (e il server risponderà 404). */
export function riscriviRichiesta(metodo, url) {
  const [percorso, query] = url.split('?');
  for (const r of REGOLE) {
    if (r.metodo !== metodo) continue;
    const m = percorso.match(r.da);
    if (m) return { metodo: r.metodoNuovo || metodo, url: r.a(m) + (query ? `?${query}` : '') };
  }
  return { metodo, url };
}

/** Aggiunge a ogni campo `xxx_cents` il campo `xxx` in euro (cents / 100). Lavora su copie, ricorsivo. */
export function aggiungiEuro(valore) {
  if (Array.isArray(valore)) return valore.map(aggiungiEuro);
  if (valore && typeof valore === 'object') {
    const out = {};
    for (const [chiave, v] of Object.entries(valore)) {
      out[chiave] = aggiungiEuro(v);
      if (chiave.endsWith('_cents') && typeof v === 'number') out[chiave.slice(0, -6)] = v / 100;
    }
    return out;
  }
  return valore;
}

const aCentesimi = (euro) => Math.round(Number(euro) * 100);

/** Converte in centesimi i prezzi di un payload di creazione/modifica gruppo (campi legacy in euro). */
export function inCentesimi(body) {
  if (!body || typeof body !== 'object') return body;
  const out = { ...body };
  // I moduli mandano tutto come testo: numeri veri dove il server vuole numeri
  for (const k of ['quantita_minima', 'percentuale_commissione']) {
    if (typeof out[k] === 'string' && out[k].trim() !== '' && !Number.isNaN(Number(out[k]))) out[k] = Number(out[k]);
    if (out[k] === '') delete out[k];
  }
  for (const [euro, cents] of [['prezzo_corrente', 'prezzo_corrente_cents'], ['prezzo_base', 'prezzo_base_cents']]) {
    if (out[euro] !== undefined && out[euro] !== '') out[cents] = aCentesimi(out[euro]);
    delete out[euro];
  }
  if (Array.isArray(out.scaglioni)) {
    out.scaglioni = out.scaglioni.map((s) => ({ soglia: Number(s.soglia), prezzo_cents: aCentesimi(s.prezzo) }));
  }
  return out;
}

/** Campi di un FormData → oggetto; i file (foto) sono ignorati e `scaglioni` (stringa JSON) torna a essere un elenco. */
export function formDataInOggetto(formData) {
  const out = {};
  for (const [chiave, v] of formData.entries()) {
    if (typeof v !== 'string') continue;
    out[chiave] = v;
  }
  if (typeof out.scaglioni === 'string') {
    try { out.scaglioni = out.scaglioni.trim() === '' ? [] : JSON.parse(out.scaglioni); } catch (_) { /* resta com'è: lo rifiuta il server */ }
  }
  return out;
}

/** Trasforma il corpo della richiesta in base alla chiamata di destinazione. */
export function adattaCorpo(urlNuovo, metodoNuovo, body) {
  if (body == null) return body;
  const scrive = metodoNuovo === 'POST' || metodoNuovo === 'PATCH';
  if (scrive && /^\/api\/campaigns(\/|$)/.test(urlNuovo.split('?')[0]) && !urlNuovo.endsWith('/join')) return inCentesimi(body);
  return body;
}
