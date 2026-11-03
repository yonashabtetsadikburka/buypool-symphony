/* Modulo "Nuova proposta" del fornitore, in 3 passi (parte PURA: regole e HTML, testata in tests/fornitore_proposte_front_test.mjs).
   1 Prodotto · 2 Prezzi e scaglioni · 3 Foto e invio. Le regole di validazione ripetono quelle del server (che resta l'autorita'):
   scaglioni crescenti in pezzi, prezzi decrescenti e tutti sotto il listino; il primo scaglione parte dalla quantita' minima.
   Il prezzo e' sempre quello del fornitore: la commissione BuyPool non compare mai. */
import { esc } from './utils.js';
import { euro, plurale } from './format.js';

export const NOME_MAX = 80;
export const DESCRIZIONE_MAX = 1000;
export const SCAGLIONI_MAX = 5;
export const FOTO_MAX_BYTES = 5 * 1024 * 1024;
export const FOTO_TIPI = ['image/jpeg', 'image/png', 'image/webp'];
export const PASSI = ['Prodotto', 'Prezzi e scaglioni', 'Foto e invio'];

/** "12,50" / "12.5" / "1.234,50" -> numero; null se non lo e'. */
export function parseNumero(v) {
  if (typeof v === 'number') return Number.isFinite(v) ? v : null;
  let s = String(v ?? '').trim().replace(/\s/g, '');
  if (s === '') return null;
  if (s.includes(',')) s = s.replace(/\./g, '');
  s = s.replace(',', '.');
  return /^-?\d+(\.\d+)?$/.test(s) ? parseFloat(s) : null;
}

export function scontoPct(listino, prezzo) {
  const l = parseNumero(listino), p = parseNumero(prezzo);
  return l && l > 0 && p !== null && p > 0 ? Math.round((1 - p / l) * 100) : null;
}

export function statoIniziale(p = null) {
  if (!p) return { passo: 1, nome: '', descrizione: '', id_categoria: '', listino: '', minimo: '', scaglioni: [{ soglia: '', prezzo: '' }], foto: null, fotoEsistente: null };
  const sc = (p.scaglioni || []).map(s => ({ soglia: String(s.soglia), prezzo: String(s.prezzo).replace('.', ',') }));
  return {
    passo: 1, nome: p.nome_prodotto || '', descrizione: p.descrizione || '', id_categoria: p.id_categoria ? String(p.id_categoria) : '',
    listino: p.listino != null ? String(p.listino).replace('.', ',') : '', minimo: p.moq_richiesto ? String(p.moq_richiesto) : '',
    scaglioni: sc.length ? sc : [{ soglia: '', prezzo: '' }], foto: null, fotoEsistente: p.foto || null,
  };
}

/** Scaglioni come li legge il server: il primo parte sempre dalla quantita' minima. */
export function scaglioniPerInvio(st) {
  return st.scaglioni.map((s, i) => ({ soglia: i === 0 ? st.minimo : s.soglia, prezzo: s.prezzo }));
}

/** Errori del passo (oggetto campo -> messaggio; vuoto se tutto ok). */
export function validaPasso(passo, st) {
  const e = {};
  if (passo === 1) {
    const nome = String(st.nome || '').trim().replace(/\s+/g, ' ');
    if (nome.length < 3) e.nome_prodotto = 'Scrivi il nome del prodotto (almeno 3 caratteri)';
    else if (nome.length > NOME_MAX) e.nome_prodotto = `Il nome può avere al massimo ${NOME_MAX} caratteri`;
    else if (/[<>]/.test(nome)) e.nome_prodotto = 'Il nome contiene caratteri non ammessi';
    if (String(st.descrizione || '').length > DESCRIZIONE_MAX) e.descrizione = `La descrizione può avere al massimo ${DESCRIZIONE_MAX} caratteri`;
  }
  if (passo === 2) {
    const listino = parseNumero(st.listino);
    if (listino === null || listino <= 0) e.prezzo_base = 'Indica il prezzo di listino (maggiore di zero)';
    const minimo = /^\d+$/.test(String(st.minimo || '').trim()) ? parseInt(st.minimo, 10) : null;
    if (minimo === null || minimo < 1) e.quantita_minima = 'Indica la quantità minima per attivare il gruppo (numero intero)';
    const righe = scaglioniPerInvio(st);
    if (righe.length < 1) e.scaglioni = 'Inserisci almeno uno scaglione di prezzo';
    if (righe.length > SCAGLIONI_MAX) e.scaglioni = `Al massimo ${SCAGLIONI_MAX} scaglioni`;
    let prec = null;
    righe.forEach((r, i) => {
      const n = i + 1;
      const soglia = /^\d+$/.test(String(r.soglia ?? '').trim()) ? parseInt(r.soglia, 10) : null;
      const prezzo = parseNumero(r.prezzo);
      if (soglia === null || soglia < 1) { e[`scaglione_${n}`] = `Scaglione ${n}: indica da quanti pezzi parte (numero intero)`; prec = null; return; }
      if (prezzo === null || prezzo <= 0) { e[`scaglione_${n}`] = `Scaglione ${n}: indica il tuo prezzo (maggiore di zero)`; prec = null; return; }
      if (prec && soglia <= prec.soglia) e[`scaglione_${n}`] = `Scaglione ${n}: i pezzi devono essere più di quelli dello scaglione precedente`;
      else if (prec && prezzo >= prec.prezzo) e[`scaglione_${n}`] = `Scaglione ${n}: il prezzo deve essere più basso di quello dello scaglione precedente`;
      else if (listino !== null && prezzo >= listino) e[`scaglione_${n}`] = `Scaglione ${n}: il prezzo deve essere più basso del listino`;
      prec = { soglia, prezzo };
    });
  }
  if (passo === 3 && st.foto) {
    if (!FOTO_TIPI.includes(st.foto.type)) e.foto = 'Formato non valido: ammessi JPG, PNG e WebP';
    else if (st.foto.size > FOTO_MAX_BYTES) e.foto = 'La foto può pesare al massimo 5 MB';
  }
  return e;
}

/** Passo a cui appartiene un campo in errore (per riaprire il passo giusto dopo un errore del server). */
export function passoDelCampo(campo) {
  if (['nome_prodotto', 'descrizione', 'id_categoria'].includes(campo)) return 1;
  if (['prezzo_base', 'quantita_minima', 'scaglioni'].includes(campo) || /^scaglione_\d+$/.test(campo)) return 2;
  return 3;
}

const err = (e, k) => `<span class="frn-errore" data-err="${k}" role="alert">${e[k] ? esc(e[k]) : ''}</span>`;

export function stepperHtml(passo) {
  const pct = Math.round((passo - 1) / (PASSI.length - 1) * 100);
  return `<nav class="frn-passi" aria-label="Avanzamento"><div class="frn-passi-barra" role="progressbar" aria-valuemin="1" aria-valuemax="${PASSI.length}" aria-valuenow="${passo}" aria-valuetext="Passo ${passo} di ${PASSI.length}: ${esc(PASSI[passo - 1])}"><span style="width:${pct}%"></span></div>
    <ol class="frn-passi-lista">${PASSI.map((n, i) => `<li${i + 1 === passo ? ' aria-current="step"' : ''} class="${i + 1 < passo ? 'fatto' : (i + 1 === passo ? 'attivo' : '')}"><span class="frn-passo-n">${i + 1}</span> ${esc(n)}</li>`).join('')}</ol></nav>`;
}

export function passo1Html(st, categorie, e = {}) {
  return `<div class="adm-campo"><label for="frn-nome">Nome del prodotto</label>
      <input id="frn-nome" type="text" maxlength="${NOME_MAX}" value="${esc(st.nome)}" data-campo="nome" autocomplete="off">
      <span class="adm-contatore" id="frn-nome-n">${[...String(st.nome)].length} / ${NOME_MAX}</span>${err(e, 'nome_prodotto')}</div>
    <div class="adm-campo"><label for="frn-descr">Descrizione</label>
      <textarea id="frn-descr" rows="5" maxlength="${DESCRIZIONE_MAX}" data-campo="descrizione">${esc(st.descrizione)}</textarea>
      <span class="adm-contatore" id="frn-descr-n">${[...String(st.descrizione)].length} / ${DESCRIZIONE_MAX}</span>${err(e, 'descrizione')}</div>
    <div class="adm-campo"><label for="frn-cat">Categoria</label>
      <select id="frn-cat" data-campo="id_categoria"><option value="">Nessuna</option>${(categorie || []).map(c => `<option value="${Number(c.id)}"${String(c.id) === String(st.id_categoria) ? ' selected' : ''}>${esc(c.nome)}</option>`).join('')}</select>${err(e, 'id_categoria')}</div>`;
}

export function rigaScaglioneHtml(st, i, e = {}) {
  const r = st.scaglioni[i];
  const pct = scontoPct(st.listino, r.prezzo);
  const primo = i === 0;
  return `<tr data-riga="${i}"><td data-label="Scaglione">${i + 1}</td>
    <td data-label="Da (pezzi)"><input type="text" inputmode="numeric" aria-label="Scaglione ${i + 1}: da quanti pezzi" data-scaglione="${i}" data-k="soglia" value="${esc(primo ? st.minimo : r.soglia)}"${primo ? ' readonly aria-readonly="true" title="Coincide con la quantità minima"' : ''}></td>
    <td data-label="Tuo prezzo"><input type="text" inputmode="decimal" aria-label="Scaglione ${i + 1}: tuo prezzo in euro" data-scaglione="${i}" data-k="prezzo" value="${esc(r.prezzo)}" placeholder="0,00"></td>
    <td data-label="Sconto sul listino" class="adm-num" id="frn-sconto-${i}">${pct !== null ? `${pct}%` : '—'}</td>
    <td class="adm-azioni-cella">${primo ? '' : `<button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-rimuovi="${i}" aria-label="Rimuovi lo scaglione ${i + 1}">Rimuovi</button>`}</td></tr>
    ${e[`scaglione_${i + 1}`] ? `<tr class="frn-riga-err"><td colspan="5">${err(e, `scaglione_${i + 1}`)}</td></tr>` : ''}`;
}

export function passo2Html(st, e = {}) {
  return `<div class="frn-due-colonne">
      <div class="adm-campo"><label for="frn-listino">Prezzo di listino (€)</label><input id="frn-listino" type="text" inputmode="decimal" data-campo="listino" value="${esc(st.listino)}" placeholder="0,00">${err(e, 'prezzo_base')}</div>
      <div class="adm-campo"><label for="frn-minimo">Quantità minima per attivare il gruppo</label><input id="frn-minimo" type="text" inputmode="numeric" data-campo="minimo" value="${esc(st.minimo)}" placeholder="Es. 10">${err(e, 'quantita_minima')}</div>
    </div>
    <h3 class="frn-sottotitolo">Scaglioni di prezzo</h3>
    <p class="adm-nota">Più pezzi, prezzo più basso. Il primo scaglione parte dalla quantità minima. Inserisci solo il tuo prezzo: BuyPool aggiunge la sua parte a parte.</p>
    <div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta frn-tab-scaglioni">
      <thead><tr><th scope="col">Scaglione</th><th scope="col">Da (pezzi)</th><th scope="col">Tuo prezzo</th><th scope="col" class="adm-num">Sconto sul listino</th><th scope="col"><span class="adm-solo-sr">Azioni</span></th></tr></thead>
      <tbody id="frn-scaglioni-body">${st.scaglioni.map((_, i) => rigaScaglioneHtml(st, i, e)).join('')}</tbody></table></div>
    ${err(e, 'scaglioni')}
    <div><button type="button" class="adm-btn adm-btn-sec" id="frn-aggiungi"${st.scaglioni.length >= SCAGLIONI_MAX ? ' disabled' : ''}>Aggiungi scaglione</button></div>`;
}

export function riepilogoHtml(st, categorie) {
  const cat = (categorie || []).find(c => String(c.id) === String(st.id_categoria));
  const righe = scaglioniPerInvio(st);
  return `<dl class="adm-dati-compatti frn-riepilogo">
    <dt>Prodotto</dt><dd>${esc(String(st.nome).trim())}</dd>
    <dt>Categoria</dt><dd>${cat ? esc(cat.nome) : 'Nessuna'}</dd>
    <dt>Prezzo di listino</dt><dd>${parseNumero(st.listino) !== null ? esc(euro(parseNumero(st.listino))) : '—'}</dd>
    <dt>Quantità minima</dt><dd>${esc(plurale(parseInt(st.minimo, 10) || 0, 'unità', 'unità'))}</dd>
    <dt>Scaglioni</dt><dd>${righe.map(r => `da ${esc(plurale(parseInt(r.soglia, 10) || 0, 'unità', 'unità'))}: ${parseNumero(r.prezzo) !== null ? esc(euro(parseNumero(r.prezzo))) : '—'}`).join('<br>')}</dd></dl>`;
}

export function passo3Html(st, categorie, urlFoto = (x) => x, e = {}) {
  return `<div class="adm-campo"><label for="frn-foto">Foto del prodotto</label>
      <input id="frn-foto" type="file" accept="image/jpeg,image/png,image/webp" data-campo="foto">
      <span class="adm-contatore">JPG, PNG o WebP, al massimo 5 MB. Facoltativa, ma aiuta i clienti a scegliere.</span>${err(e, 'foto')}
      <div class="frn-anteprima" id="frn-anteprima">${st.fotoEsistente ? `<img src="${esc(urlFoto(st.fotoEsistente))}" alt="Foto attuale del prodotto">` : ''}</div></div>
    <h3 class="frn-sottotitolo">Riepilogo</h3>${riepilogoHtml(st, categorie)}`;
}

/** Pannello del passo corrente + pulsanti. */
export function moduloHtml(st, categorie, { modifica = false, urlFoto, errori = {} } = {}) {
  const corpo = st.passo === 1 ? passo1Html(st, categorie, errori) : st.passo === 2 ? passo2Html(st, errori) : passo3Html(st, categorie, urlFoto, errori);
  const ultimo = st.passo === PASSI.length;
  return `${stepperHtml(st.passo)}
    <form class="adm-card adm-sezione frn-form" id="frn-form" novalidate>
      <h2 class="adm-h2">${esc(PASSI[st.passo - 1])}</h2>
      ${corpo}
      <p class="frn-errore frn-errore-generale" id="frn-errore-generale" role="alert"></p>
      <div class="frn-form-azioni">
        ${st.passo > 1 ? '<button type="button" class="adm-btn adm-btn-sec" id="frn-indietro">Indietro</button>' : '<a class="adm-btn adm-btn-sec" href="#/fornitore/proposte">Annulla</a>'}
        ${ultimo ? `<button type="submit" class="adm-btn adm-btn-pri" id="frn-invia">${modifica ? 'Salva le modifiche' : 'Invia la proposta'}</button>`
                 : '<button type="button" class="adm-btn adm-btn-pri" id="frn-avanti">Avanti</button>'}
      </div></form>`;
}

/** FormData da inviare al server (multipart). */
export function formDataProposta(st, riproponiDa = null) {
  const fd = new FormData();
  fd.append('nome_prodotto', String(st.nome).trim());
  fd.append('descrizione', String(st.descrizione).trim());
  fd.append('id_categoria', st.id_categoria || '');
  fd.append('prezzo_base', st.listino);
  fd.append('quantita_minima', st.minimo);
  fd.append('scaglioni', JSON.stringify(scaglioniPerInvio(st)));
  if (st.foto) fd.append('foto', st.foto);
  if (riproponiDa) fd.append('riproponi_da', String(riproponiDa));
  return fd;
}
