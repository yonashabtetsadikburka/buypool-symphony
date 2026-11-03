/* Pagina Azienda del fornitore (HTML e regole pure, testate in tests/fornitore_azienda_front_test.mjs).
   Le regole di validazione (P.IVA, IBAN, email...) ripetono quelle del server, che resta l'autorita'.
   L'IBAN e' mostrato solo al proprietario; non finisce in log ne' in registri. */
import { esc } from './utils.js';

export const CAMPI = [
  ['nome_azienda', 'Ragione sociale'], ['piva', 'P.IVA'], ['categoria', 'Categoria'], ['referente', 'Referente'], ['email_contatto', 'Email'], ['telefono', 'Telefono'],
  ['iban', 'IBAN'], ['indirizzo_magazzino', 'Indirizzo del magazzino'], ['orari_carico', 'Orari di carico'],
];
const ETICHETTA_MANCANTE = { nome_azienda: 'ragione sociale', piva: 'P.IVA', categoria: 'categoria', referente: 'referente', email_contatto: 'email', telefono: 'telefono', iban: 'IBAN', indirizzo_magazzino: 'indirizzo del magazzino', orari_carico: 'orari di carico' };
export const LIMITI = { nome_azienda: 200, categoria: 100, referente: 120, email_contatto: 255, telefono: 30, iban: 34, indirizzo_magazzino: 255, orari_carico: 200 };

/** Percentuale e campi mancanti: stesse regole del server (fornitore_profilo_completezza). */
export function completezza(v) {
  const mancanti = CAMPI.map(([k]) => k).filter(k => String(v[k] ?? '').trim() === '').map(k => ETICHETTA_MANCANTE[k]);
  return { percentuale: Math.round((CAMPI.length - mancanti.length) / CAMPI.length * 100), mancanti, completo: mancanti.length === 0 };
}

export function pivaNormalizza(p) {
  let s = String(p || '').toUpperCase().replace(/[\s.\-]/g, '');
  if (s.startsWith('IT')) s = s.slice(2);
  if (!/^\d{11}$/.test(s)) return null;
  let somma = 0;
  for (let i = 0; i < 10; i++) { let c = parseInt(s[i], 10); if (i % 2 === 1) { c *= 2; if (c > 9) c -= 9; } somma += c; }
  return ((10 - somma % 10) % 10) === parseInt(s[10], 10) ? s : null;
}

const LUNGHEZZE = { IT: 27, SM: 27, DE: 22, FR: 27, ES: 24, GB: 22, NL: 18, BE: 16, AT: 20, CH: 21, PT: 25, IE: 22, LU: 20 };
export const ibanNormalizza = (s) => String(s || '').replace(/\s+/g, '').toUpperCase();

export function ibanValido(s) {
  const i = ibanNormalizza(s);
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(i) || i.length < 15 || i.length > 34) return false;
  const paese = i.slice(0, 2);
  if (LUNGHEZZE[paese] && i.length !== LUNGHEZZE[paese]) return false;
  if (paese === 'IT' && !/^IT\d{2}[A-Z]\d{10}[A-Z0-9]{12}$/.test(i)) return false;
  let resto = 0;
  for (const c of i.slice(4) + i.slice(0, 4)) {
    const v = /[A-Z]/.test(c) ? String(c.charCodeAt(0) - 55) : c;
    for (const cifra of v) resto = (resto * 10 + parseInt(cifra, 10)) % 97;
  }
  return resto === 1;
}

/** IBAN a gruppi di 4 per leggerlo meglio. */
export const ibanFormattato = (s) => ibanNormalizza(s).replace(/(.{4})/g, '$1 ').trim();

/** Errori per campo (vuoto se tutto ok). */
export function validaAzienda(v) {
  const e = {};
  const nome = String(v.nome_azienda || '').trim();
  if (nome.length < 2 || nome.length > LIMITI.nome_azienda || /[<>]/.test(nome)) e.nome_azienda = 'Scrivi la ragione sociale (da 2 a 200 caratteri)';
  if (String(v.piva || '').trim() !== '' && pivaNormalizza(v.piva) === null) e.piva = 'Partita IVA non valida (11 cifre con cifra di controllo)';
  for (const k of ['categoria', 'referente', 'indirizzo_magazzino', 'orari_carico']) {
    const t = String(v[k] || '').trim();
    if (t.length > LIMITI[k]) e[k] = `Al massimo ${LIMITI[k]} caratteri`;
    else if (/[<>]/.test(t)) e[k] = 'Caratteri non ammessi';
  }
  const mail = String(v.email_contatto || '').trim();
  if (mail !== '' && !/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]{2,}$/.test(mail)) e.email_contatto = 'Email non valida';
  const tel = String(v.telefono || '').trim();
  if (tel !== '' && !/^[0-9 +().\/-]{6,30}$/.test(tel)) e.telefono = 'Telefono non valido (da 6 a 30 tra cifre, spazi e + - ( ) /)';
  if (String(v.iban || '').trim() !== '' && !ibanValido(v.iban)) e.iban = 'IBAN non valido: controlla le cifre';
  return e;
}

export function barraHtml(c) {
  return `<div class="frn-compl" id="frn-compl"><div class="frn-compl-testa"><strong>Profilo completo al ${Number(c.percentuale)}%</strong></div>
    <span class="adm-barra frn-barra" role="img" aria-label="Profilo completo al ${Number(c.percentuale)}%"><span style="width:${Number(c.percentuale)}%"></span></span>
    ${c.completo ? '<p class="adm-sub">Hai compilato tutto.</p>' : `<p class="adm-sub">Mancano: ${esc(c.mancanti.join(', '))}.</p>`}</div>`;
}

const campo = (k, et, v, e, extra = '') => `<div class="adm-campo"><label for="frn-az-${k}">${esc(et)}</label>
  <input id="frn-az-${k}" name="${k}" type="${k === 'email_contatto' ? 'email' : 'text'}" value="${esc(v[k] ?? '')}" ${LIMITI[k] ? `maxlength="${LIMITI[k]}"` : ''} autocomplete="off" ${extra}>
  <span class="frn-errore" data-err="${k}" role="alert">${e[k] ? esc(e[k]) : ''}</span></div>`;

/** Pagina completa. `f` = risposta di GET /fornitore/io; `errori` = errori per campo. */
export function aziendaHtml(f, errori = {}) {
  const v = { ...f, iban: ibanFormattato(f.iban) };
  return `<div class="content-area adm-pagina">
    <div><h1 class="adm-h1">Azienda</h1><p class="adm-frase">I dati che BuyPool usa per ordini, consegne e pagamenti.</p></div>
    ${barraHtml(f.completezza || completezza(f))}
    <form class="adm-card adm-sezione frn-form" id="frn-azienda" novalidate>
      <h2 class="adm-h2">Dati dell'azienda</h2>
      <div class="frn-due-colonne">${campo('nome_azienda', 'Ragione sociale', v, errori)}${campo('piva', 'P.IVA', v, errori)}${campo('categoria', 'Categoria', v, errori)}${campo('referente', 'Referente', v, errori)}
        ${campo('email_contatto', 'Email', v, errori)}${campo('telefono', 'Telefono', v, errori, 'inputmode="tel"')}</div>
      <h2 class="adm-h2">Pagamenti e consegne</h2>
      <div class="frn-due-colonne">${campo('iban', 'IBAN', v, errori, 'spellcheck="false"')}${campo('indirizzo_magazzino', 'Indirizzo del magazzino', v, errori)}</div>
      ${campo('orari_carico', 'Orari di carico', v, errori, 'placeholder="Es. Lun-Ven 8:00-17:00"')}
      <p class="frn-errore frn-errore-generale" id="frn-errore-generale" role="alert"></p>
      <div class="frn-form-azioni"><span></span><button type="submit" class="adm-btn adm-btn-pri" id="frn-az-salva">Salva</button></div>
    </form>
  </div>`;
}
