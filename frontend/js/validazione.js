// Validazioni di registrazione condivise dal browser (le stesse regole sono ripetute lato server in api/endpoints/auth.php).

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Requisiti della password: almeno 8 caratteri, una lettera, un numero. */
export function passwordRequisiti(p) {
  const s = String(p ?? '');
  return { lunghezza: s.length >= 8, lettera: /\p{L}/u.test(s), numero: /\d/.test(s) };
}

export function passwordValida(p) {
  const r = passwordRequisiti(p);
  return r.lunghezza && r.lettera && r.numero;
}

/** Partita IVA italiana: 11 cifre con cifra di controllo (prefisso IT facoltativo). Ritorna le 11 cifre o null. */
export function pivaNormalizza(p) {
  let s = String(p ?? '').toUpperCase().replace(/[\s.\-]/g, '');
  if (s.startsWith('IT')) s = s.slice(2);
  if (!/^\d{11}$/.test(s)) return null;
  let somma = 0;
  for (let i = 0; i < 10; i++) {
    let c = Number(s[i]);
    if (i % 2 === 1) { c *= 2; if (c > 9) c -= 9; }
    somma += c;
  }
  return (10 - (somma % 10)) % 10 === Number(s[10]) ? s : null;
}
