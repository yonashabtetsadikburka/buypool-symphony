// Pagina di ritorno dopo il login: solo percorsi interni dell'app, mai URL esterni.

const CARATTERI_AMMESSI = /^\/[A-Za-z0-9\-._~\/?=&%,+]*$/;

/** Ritorna il percorso se è interno e sicuro per il ruolo, altrimenti null. */
export function ritornoSicuro(raw, ruolo = 'cliente') {
  if (typeof raw !== 'string' || raw.length === 0 || raw.length > 300) return null;
  if (!CARATTERI_AMMESSI.test(raw)) return null;
  // "//host" è un URL relativo allo schema; "/\" viene normalizzato dai browser.
  if (raw.startsWith('//') || raw.includes('..') || /%2f%2f|%5c/i.test(raw)) return null;
  const percorso = raw.split('?')[0];
  if (percorso === '/admin' || percorso.startsWith('/admin/')) return ruolo === 'admin' ? raw : null;
  if (percorso === '/fornitore' || percorso.startsWith('/fornitore/')) return ruolo === 'fornitore' ? raw : null;
  return raw;
}

/** Legge il parametro ?next= dall'hash corrente (#/login?next=/campagne/5). */
export function ritornoDaHash(hash = window.location.hash) {
  const query = (hash.split('?')[1] || '').split('#')[0];
  const next = new URLSearchParams(query).get('next');
  return next || null;
}

/** Percorso di ritorno: parametro ?next= o, in mancanza, quello salvato in sessione. */
export function leggiRitorno(ruolo) {
  const daHash = ritornoSicuro(ritornoDaHash(), ruolo);
  if (daHash) return daHash;
  try {
    return ritornoSicuro(sessionStorage.getItem('postLoginHash'), ruolo);
  } catch (e) {
    return null;
  }
}

export function consumaRitorno() {
  try { sessionStorage.removeItem('postLoginHash'); } catch (e) {}
}
