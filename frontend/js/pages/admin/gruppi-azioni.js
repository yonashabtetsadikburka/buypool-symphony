import { apiGet, apiPost, apiPostForm, apiDelete } from '../../api.js';
import { API_URL } from '../../constants.js';
import { esc } from '../../utils.js';
import { euro, pezzi, plurale, dataGGMMAAAA, oraHHMM } from '../../format.js';
import { apriModale } from '../../components/modal.js';
import { showToast } from '../../components/toast.js';

/* Azioni admin sui gruppi, condivise da lista e scheda. Il server decide cosa e' consentito: qui si mostrano
   solo i riepiloghi (calcolati dal server) e si inviano le richieste. */

export const STATO_CHIP = {
  bozza: 'adm-chip-grigio', aperto: 'adm-chip-teal', attivo: 'adm-chip-verde', scaduto_da_chiudere: 'adm-chip-ambra',
  concluso_da_ordinare: 'adm-chip-ambra', ordine_inviato: 'adm-chip-blu', pronto_ritiro: 'adm-chip-teal', ritirato: 'adm-chip-verde',
  non_attivato: 'adm-chip-grigio', annullato: 'adm-chip-rosso'
};

export const chipStato = (codice, etichetta) => `<span class="adm-chip ${STATO_CHIP[codice] || 'adm-chip-grigio'}">${esc(etichetta)}</span>`;

export function imgUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  return API_URL.replace(/\/api\/?$/, '') + '/api/' + String(path).replace(/^\//, '');
}

const scheda = async (id) => (await apiGet(`/admin/gruppi/${id}`)).dati;
const toast = (titolo, descrizione = '', variant = 'success') => showToast({ title: esc(titolo), description: esc(descrizione), variant });

/** "2026-10-23T18:30" (campo datetime-local) -> "2026-10-23 18:30:00" */
const perServer = (v) => String(v).replace('T', ' ') + (String(v).length === 16 ? ':00' : '');
/** "2026-10-23 18:30:00" -> "2026-10-23T18:30" */
const perCampo = (v) => String(v || '').replace(' ', 'T').slice(0, 16);

const riga = (k, v) => `<div class="adm-riepilogo-riga"><span>${esc(k)}</span><strong>${esc(v)}</strong></div>`;

/* ----------------------------- Prolunga scadenza ----------------------------- */
export async function apriProlunga(id, fatto) {
  const s = await scheda(id);
  const attuale = s.prolunga.attuale;
  const n = s.prolunga.da_avvisare;
  const proposta = new Date(String(attuale).replace(' ', 'T'));
  proposta.setDate(proposta.getDate() + 7);
  const propostaCampo = `${proposta.getFullYear()}-${String(proposta.getMonth() + 1).padStart(2, '0')}-${String(proposta.getDate()).padStart(2, '0')}T${String(proposta.getHours()).padStart(2, '0')}:${String(proposta.getMinutes()).padStart(2, '0')}`;
  apriModale({
    titolo: 'Prolunga la scadenza',
    introHtml: `<p>${esc(s.gruppo.prodotto)}: scadenza attuale <strong>${esc(dataGGMMAAAA(attuale))} alle ${esc(oraHHMM(attuale))}</strong>.</p>`,
    corpoHtml: `<div class="adm-campo"><label for="adm-nuova-scadenza">Nuova scadenza</label>
        <input id="adm-nuova-scadenza" type="datetime-local" min="${esc(perCampo(attuale))}" value="${esc(propostaCampo)}"></div>
      <p class="adm-nota">La scadenza si può solo prolungare, mai anticipare.</p>
      <p class="adm-avviso">${n === 0 ? 'Nessun partecipante da avvisare.' : `Verranno avvisati ${plurale(n, 'partecipante', 'partecipanti')} per email e notifica.`}</p>`,
    etichettaConferma: 'Prolunga',
    onConferma: async (box) => {
      const v = box.querySelector('#adm-nuova-scadenza').value;
      if (!v) throw new Error('Scegli la nuova scadenza.');
      if (new Date(v) <= new Date(String(attuale).replace(' ', 'T'))) throw new Error('La nuova scadenza deve essere successiva a quella attuale.');
      const r = (await apiPost(`/admin/gruppi/${id}/prolunga`, { data_limite: perServer(v) })).dati;
      toast('Scadenza prolungata', `Nuova scadenza: ${dataGGMMAAAA(r.data_limite)} alle ${oraHHMM(r.data_limite)}. ${r.notificati === 1 ? 'Avvisato 1 partecipante.' : `Avvisati ${r.notificati} partecipanti.`}`);
      fatto?.();
    }
  });
}

/* ----------------------------- Chiudi ora (anticipata) ----------------------------- */
export async function apriChiudiOra(id, fatto) {
  const s = await scheda(id);
  const r = s.riepilogo_chiusura;
  apriModale({
    titolo: 'Chiudi ora il gruppo',
    introHtml: `<p>${esc(s.gruppo.prodotto)}: il minimo è raggiunto e il gruppo è ancora aperto.</p>`,
    corpoHtml: `<div class="adm-riepilogo">
        ${riga('Pezzi', pezzi(r.pezzi))}${riga('Prezzo finale', euro(r.prezzo_finale))}
        ${riga('Clienti da addebitare', String(r.clienti))}${riga('Totale dei saldi', euro(r.totale_saldi))}
      </div>
      <p class="adm-avviso adm-avviso-ambra">Nessun altro potrà unirsi e il prossimo scaglione non sarà raggiungibile. I saldi vengono addebitati subito. L'operazione non si può annullare.</p>`,
    etichettaConferma: 'Chiudi ora e addebita',
    variante: 'pericolo',
    parolaConferma: 'CHIUDI',
    onConferma: async () => {
      const res = (await apiPost(`/admin/gruppi/${id}/chiudi-ora`, { conferma: 'CHIUDI' })).dati;
      toast('Gruppo chiuso', `Saldi addebitati: ${res.pagate ?? 0}. Pagamenti da sistemare: ${res.azione_richiesta ?? 0}.`);
      fatto?.();
    }
  });
}

/* ----------------------------- Chiudi e addebita (gruppo scaduto) ----------------------------- */
export async function apriChiudiEAddebita(id, fatto) {
  const s = await scheda(id);
  const r = s.riepilogo_chiusura;
  apriModale({
    titolo: 'Chiudi e addebita',
    introHtml: `<p>${esc(s.gruppo.prodotto)}: la scadenza è passata e il minimo è raggiunto.</p>`,
    corpoHtml: r ? `<div class="adm-riepilogo">
        ${riga('Pezzi', pezzi(r.pezzi))}${riga('Prezzo finale', euro(r.prezzo_finale))}
        ${riga('Clienti da addebitare', String(r.clienti))}${riga('Totale dei saldi', euro(r.totale_saldi))}
      </div>` : '',
    etichettaConferma: 'Chiudi e addebita',
    onConferma: async () => {
      const res = (await apiPost(`/campagne/${id}/chiusura`, {})).dati;
      if (res.gia_chiusa) toast('Gruppo già chiuso', 'Niente da elaborare.', 'default');
      else toast('Chiusura completata', `Saldi addebitati: ${res.pagate ?? 0}. Pagamenti da sistemare: ${res.azione_richiesta ?? 0}. Rimborsati: ${res.rimborsate ?? 0}.`);
      fatto?.();
    }
  });
}

/* ----------------------------- Annulla gruppo e rimborsa tutti ----------------------------- */
export async function apriAnnulla(id, fatto) {
  const s = await scheda(id);
  const a = s.riepilogo_annullo;
  apriModale({
    titolo: 'Annulla gruppo e rimborsa tutti',
    introHtml: `<p>${esc(s.gruppo.prodotto)}</p>`,
    corpoHtml: `<div class="adm-riepilogo">
        ${riga('Adesioni da annullare', String(a.adesioni))}${riga('Importo da rimborsare', euro(a.rimborso_totale))}
      </div>
      <p class="adm-nota">Vengono rimborsati tutti gli acconti e gli eventuali saldi già addebitati (commissione e spedizione comprese). Ogni cliente riceve email e notifica con il motivo.</p>
      <div class="adm-campo"><label for="adm-motivo">Motivo (lo vedranno i clienti)</label>
        <textarea id="adm-motivo" rows="3" maxlength="255" required placeholder="Per esempio: il fornitore non può consegnare il prodotto"></textarea>
        <span class="adm-contatore" id="adm-motivo-n">0 / 255</span></div>`,
    etichettaConferma: 'Annulla gruppo e rimborsa',
    variante: 'pericolo',
    parolaConferma: 'ANNULLA',
    onConferma: async (box) => {
      const motivo = box.querySelector('#adm-motivo').value.trim();
      if (motivo.length < 5) throw new Error('Scrivi il motivo dell\'annullamento (almeno 5 caratteri).');
      const r = (await apiPost(`/admin/gruppi/${id}/annulla`, { motivo, conferma: 'ANNULLA' })).dati;
      if (r.completo) toast('Gruppo annullato', `Rimborsate ${plurale(r.rimborsate, 'adesione', 'adesioni')}.`);
      else toast('Gruppo annullato, rimborsi da completare', `${plurale(r.falliti, 'rimborso non riuscito', 'rimborsi non riusciti')}: verranno ripresi automaticamente.`, 'error');
      fatto?.();
    }
  }).el.querySelector('#adm-motivo').addEventListener('input', (e) => {
    document.getElementById('adm-motivo-n').textContent = `${e.target.value.length} / 255`;
  });
}

/* ----------------------------- Elimina / Pubblica / Duplica ----------------------------- */
export function apriElimina(id, titolo, fatto) {
  apriModale({
    titolo: 'Elimina gruppo',
    introHtml: `<p>Eliminare definitivamente <strong>${esc(titolo)}</strong>? Si può fare solo per bozze e gruppi senza adesioni.</p>`,
    etichettaConferma: 'Elimina',
    variante: 'pericolo',
    onConferma: async () => { await apiDelete(`/campagne/${id}`); toast('Gruppo eliminato'); fatto?.(); }
  });
}

export function apriPubblica(id, titolo, fatto) {
  apriModale({
    titolo: 'Pubblica gruppo',
    introHtml: `<p><strong>${esc(titolo)}</strong> diventerà visibile ai clienti e aperto alle adesioni.</p>`,
    etichettaConferma: 'Pubblica',
    onConferma: async () => { await apiPost(`/admin/gruppi/${id}/pubblica`, {}); toast('Gruppo pubblicato'); fatto?.(); }
  });
}

export async function duplicaGruppo(id) {
  try {
    const r = (await apiPost(`/admin/gruppi/${id}/duplica`, {})).dati;
    toast('Bozza creata', 'Controlla la scadenza e pubblica il nuovo gruppo.');
    window.location.hash = `#/admin/campagne/${r.id}`;
  } catch (err) {
    toast('Impossibile duplicare', err.message, 'error');
  }
}

/* ----------------------------- Scaglioni (creazione / modifica) ----------------------------- */
const rigaScaglione = (s = {}, bloccata = false) => `<div class="adm-scaglione-riga">
  <input type="number" name="soglia" min="1" step="1" placeholder="Da quanti pezzi" aria-label="Da quanti pezzi" value="${esc(s.soglia ?? '')}" ${bloccata ? 'disabled' : ''}>
  <input type="number" name="prezzo" min="0.01" step="0.01" placeholder="Prezzo €" aria-label="Prezzo unitario in euro" value="${esc(s.prezzo ?? '')}" ${bloccata ? 'disabled' : ''}>
  ${bloccata ? '' : '<button type="button" class="adm-btn-icona" data-rimuovi-scaglione aria-label="Rimuovi scaglione">&times;</button>'}
</div>`;

function leggiScaglioni(box) {
  const out = [];
  for (const r of box.querySelectorAll('.adm-scaglione-riga')) {
    const sRaw = r.querySelector('[name=soglia]').value.trim();
    const pRaw = r.querySelector('[name=prezzo]').value.trim();
    if (!sRaw && !pRaw) continue;
    const soglia = parseInt(sRaw, 10), prezzo = parseFloat(pRaw);
    if (!Number.isFinite(soglia) || soglia < 1 || !Number.isFinite(prezzo) || prezzo <= 0) throw new Error('Scaglioni non validi: in ogni riga servono i pezzi (almeno 1) e un prezzo maggiore di 0.');
    out.push({ soglia, prezzo });
  }
  out.sort((a, b) => a.soglia - b.soglia);
  for (let i = 1; i < out.length; i++) {
    if (out[i].soglia === out[i - 1].soglia) throw new Error('Scaglioni non validi: due righe hanno gli stessi pezzi.');
    if (out[i].prezzo >= out[i - 1].prezzo) throw new Error('I prezzi degli scaglioni devono diminuire al crescere dei pezzi.');
  }
  return out;
}

function ascoltaScaglioni(box) {
  const wrap = box.querySelector('#adm-scaglioni');
  box.querySelector('[data-aggiungi-scaglione]')?.addEventListener('click', () => {
    wrap.insertAdjacentHTML('beforeend', rigaScaglione());
    wrap.lastElementChild.querySelector('input').focus();
  });
  wrap.addEventListener('click', (e) => { if (e.target.closest('[data-rimuovi-scaglione]')) e.target.closest('.adm-scaglione-riga').remove(); });
}

/* ----------------------------- Modifica testi e foto / gruppo ----------------------------- */
export async function apriModifica(id, fatto) {
  const s = await scheda(id);
  const g = s.gruppo;
  const tutto = s.azioni.includes('modifica_tutto');
  const galleria = g.immagini.length ? g.immagini : (g.immagine ? [{ url: g.immagine, principale: 1 }] : []);
  const miniature = galleria.map(im => `<div class="adm-miniatura${parseInt(im.principale) ? ' adm-miniatura-main' : ''}">
      <img src="${esc(imgUrl(im.url))}" alt="">
      ${im.id ? `<button type="button" class="adm-miniatura-x" data-elimina-img="${im.id}" aria-label="Elimina immagine">&times;</button>` : ''}</div>`).join('') || '<span class="adm-nota">Nessuna immagine</span>';
  const blocco = tutto ? `
      <div class="adm-campo"><label for="adm-scadenza">Scadenza</label><input id="adm-scadenza" type="datetime-local" name="data_limite" value="${esc(perCampo(g.data_limite))}" required></div>
      <div class="adm-campo"><label for="adm-listino">Prezzo di listino (€)</label><input id="adm-listino" type="number" name="prezzo_base" min="0.01" step="0.01" value="${g.prezzo_base}" required></div>
      <div class="adm-campo"><label for="adm-pc">Prezzo scontato (€)</label><input id="adm-pc" type="number" name="prezzo_corrente" min="0.01" step="0.01" value="${g.prezzo_corrente}" required></div>
      <div class="adm-campo"><label for="adm-comm">Commissione (%)</label><input id="adm-comm" type="number" name="percentuale_commissione" min="0" max="100" step="0.01" value="${g.commissione}" required></div>
      <div class="adm-campo"><label for="adm-moq">Minimo (se non ci sono scaglioni)</label><input id="adm-moq" type="number" name="quantita_minima" min="1" value="${g.quantita_minima}"></div>
      <div class="adm-campo adm-campo-pieno"><span class="adm-etichetta">Scaglioni di prezzo</span>
        <p class="adm-nota">Il minimo del gruppo è la quantità del primo scaglione. I prezzi devono diminuire al crescere dei pezzi e il listino deve essere maggiore del primo scaglione.</p>
        <div id="adm-scaglioni">${s.scaglioni.map(x => rigaScaglione(x)).join('')}</div>
        <button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-aggiungi-scaglione>+ Aggiungi scaglione</button></div>`
    : `<p class="adm-nota adm-campo-pieno">Minimo, scaglioni, prezzi e commissione sono bloccati perché ci sono già adesioni. Per cambiare la scadenza usa «Prolunga la scadenza».</p>`;
  const m = apriModale({
    titolo: tutto ? 'Modifica gruppo' : 'Modifica testi e foto',
    introHtml: `<p>${esc(g.prodotto)}</p>`,
    corpoHtml: `<form class="adm-form" id="adm-form-modifica" onsubmit="return false">
      <div class="adm-campo adm-campo-pieno"><label for="adm-nome">Nome prodotto</label><input id="adm-nome" name="nome" maxlength="80" required value="${esc(g.prodotto)}"></div>
      <div class="adm-campo adm-campo-pieno"><label for="adm-descr">Descrizione</label><textarea id="adm-descr" name="descrizione" rows="3" maxlength="2000">${esc(g.descrizione || '')}</textarea></div>
      <div class="adm-campo adm-campo-pieno"><span class="adm-etichetta">Immagini</span><div class="adm-miniature" id="adm-miniature">${miniature}</div>
        <label class="adm-btn adm-btn-sec adm-btn-piccolo adm-file">Sostituisci immagine principale<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" hidden></label>
        <label class="adm-btn adm-btn-sec adm-btn-piccolo adm-file">Aggiungi immagini<input type="file" name="foto_extra" accept="image/jpeg,image/png,image/webp" multiple hidden></label>
        <span class="adm-nota" id="adm-file-scelti"></span></div>
      ${blocco}
    </form>`,
    etichettaConferma: 'Salva',
    onConferma: async (box) => {
      const f = box.querySelector('#adm-form-modifica');
      const fd = new FormData();
      fd.append('nome', f.nome.value.trim());
      fd.append('descrizione', f.descrizione.value.trim());
      if (tutto) {
        fd.append('data_limite', f.data_limite.value);
        fd.append('prezzo_base', f.prezzo_base.value);
        fd.append('prezzo_corrente', f.prezzo_corrente.value);
        fd.append('percentuale_commissione', f.percentuale_commissione.value);
        fd.append('quantita_minima', f.quantita_minima.value || '1');
        fd.append('scaglioni', JSON.stringify(leggiScaglioni(box)));
      }
      if (f.foto.files[0]) fd.append('foto', f.foto.files[0]);
      for (const file of f.foto_extra.files) fd.append('foto_extra[]', file);
      try {
        await apiPostForm(`/campagne/${id}/modifica`, fd);
      } catch (err) {
        if (err.codice === 'NESSUN_CAMPO') return;   // niente da salvare: si chiude
        throw err;
      }
      toast('Gruppo aggiornato');
      fatto?.();
    }
  });
  const box = m.el;
  if (tutto) ascoltaScaglioni(box);
  box.querySelectorAll('input[type=file]').forEach(inp => inp.addEventListener('change', () => {
    const n = [...box.querySelectorAll('input[type=file]')].reduce((t, i) => t + i.files.length, 0);
    box.querySelector('#adm-file-scelti').textContent = n ? `${plurale(n, 'file selezionato', 'file selezionati')}` : '';
  }));
  box.querySelector('#adm-miniature').addEventListener('click', (e) => {
    const b = e.target.closest('[data-elimina-img]');
    if (!b) return;
    apriModale({
      titolo: 'Elimina immagine', introHtml: '<p>Eliminare questa immagine del prodotto?</p>', etichettaConferma: 'Elimina', variante: 'pericolo',
      onConferma: async () => { await apiDelete(`/admin/immagini/${b.dataset.eliminaImg}`); b.closest('.adm-miniatura').remove(); fatto?.(); }
    });
  });
}

/* ----------------------------- Nuovo gruppo ----------------------------- */
export async function apriNuovo(fatto) {
  const [forn, lista] = await Promise.all([apiGet('/admin/fornitori'), apiGet('/campagne')]);
  const categorie = new Map();
  (lista.dati || []).forEach(c => { if (c.id_categoria) categorie.set(c.id_categoria, c.categoria || `Categoria ${c.id_categoria}`); });
  const d = new Date(Date.now() + 30 * 86400000);
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  const m = apriModale({
    titolo: 'Nuovo gruppo',
    corpoHtml: `<form class="adm-form" id="adm-form-nuovo" onsubmit="return false">
      <div class="adm-campo adm-campo-pieno"><label for="n-nome">Nome prodotto</label><input id="n-nome" name="nome" maxlength="80" required></div>
      <div class="adm-campo"><label for="n-forn">Fornitore</label><select id="n-forn" name="id_fornitore" required>${(forn.dati?.fornitori || []).map(f => `<option value="${f.id}">${esc(f.nome_azienda)}</option>`).join('')}</select></div>
      <div class="adm-campo"><label for="n-cat">Categoria</label><select id="n-cat" name="id_categoria"><option value="">—</option>${[...categorie].map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('')}</select></div>
      <div class="adm-campo"><label for="n-listino">Prezzo di listino (€)</label><input id="n-listino" type="number" name="prezzo_base" min="0.01" step="0.01" required></div>
      <div class="adm-campo"><label for="n-pc">Prezzo scontato (€)</label><input id="n-pc" type="number" name="prezzo_corrente" min="0.01" step="0.01" required></div>
      <div class="adm-campo"><label for="n-moq">Minimo (se non ci sono scaglioni)</label><input id="n-moq" type="number" name="quantita_minima" min="1" value="1"></div>
      <div class="adm-campo"><label for="n-comm">Commissione (%)</label><input id="n-comm" type="number" name="percentuale_commissione" min="0" max="100" step="0.5" value="10"></div>
      <div class="adm-campo adm-campo-pieno"><label for="n-scad">Scadenza</label><input id="n-scad" type="datetime-local" name="scadenza" required value="${d.toISOString().slice(0, 16)}"></div>
      <div class="adm-campo adm-campo-pieno"><label for="n-descr">Descrizione</label><textarea id="n-descr" name="descrizione" rows="2" maxlength="2000"></textarea></div>
      <div class="adm-campo adm-campo-pieno"><label for="n-foto">Immagini</label><input id="n-foto" type="file" name="foto" accept="image/jpeg,image/png,image/webp" multiple></div>
      <div class="adm-campo adm-campo-pieno"><span class="adm-etichetta">Scaglioni di prezzo</span>
        <p class="adm-nota">Il minimo del gruppo è la quantità del primo scaglione. Il listino deve essere maggiore del suo prezzo.</p>
        <div id="adm-scaglioni">${rigaScaglione()}</div>
        <button type="button" class="adm-btn adm-btn-sec adm-btn-piccolo" data-aggiungi-scaglione>+ Aggiungi scaglione</button></div>
      <label class="adm-spunta adm-campo-pieno"><input type="checkbox" name="bozza"> Salva come bozza (non visibile ai clienti)</label>
    </form>`,
    etichettaConferma: 'Crea gruppo',
    onConferma: async (box) => {
      const f = box.querySelector('#adm-form-nuovo');
      if (!f.reportValidity()) throw new Error('Controlla i campi evidenziati.');
      const fd = new FormData();
      fd.append('nome', f.nome.value.trim());
      fd.append('id_fornitore', f.id_fornitore.value);
      if (f.id_categoria.value) fd.append('id_categoria', f.id_categoria.value);
      fd.append('prezzo_base', f.prezzo_base.value);
      fd.append('prezzo_corrente', f.prezzo_corrente.value);
      fd.append('quantita_minima', f.quantita_minima.value || '1');
      fd.append('percentuale_commissione', f.percentuale_commissione.value);
      fd.append('scadenza', f.scadenza.value);
      fd.append('descrizione', f.descrizione.value.trim());
      const sc = leggiScaglioni(box);
      if (sc.length) fd.append('scaglioni', JSON.stringify(sc));
      if (f.bozza.checked) fd.append('bozza', '1');
      for (const file of f.foto.files) fd.append('foto[]', file);
      const r = (await apiPostForm('/campagne', fd)).dati;
      toast(f.bozza.checked ? 'Bozza creata' : 'Gruppo creato');
      fatto?.(r);
    }
  });
  ascoltaScaglioni(m.el);
}

/** Esegue una voce di menu / azione principale su un gruppo. `g` = { id, prodotto }. */
export function eseguiAzione(azione, g, fatto) {
  const ok = fatto;
  const errore = (e) => toast('Operazione non riuscita', e.message, 'error');
  switch (azione) {
    case 'apri': window.location.hash = `#/admin/campagne/${g.id}`; break;
    case 'modifica': apriModifica(g.id, ok).catch(errore); break;
    case 'prolunga': apriProlunga(g.id, ok).catch(errore); break;
    case 'chiudi_ora': apriChiudiOra(g.id, ok).catch(errore); break;
    case 'chiudi_e_addebita': apriChiudiEAddebita(g.id, ok).catch(errore); break;
    case 'annulla': apriAnnulla(g.id, ok).catch(errore); break;
    case 'elimina': apriElimina(g.id, g.prodotto, ok); break;
    case 'pubblica': apriPubblica(g.id, g.prodotto, ok); break;
    case 'duplica': duplicaGruppo(g.id); break;
    case 'prepara_ordine': window.location.hash = '#/admin/ordini?scheda=da_ordinare'; break;
    case 'merce_arrivata': window.location.hash = '#/admin/ordini?scheda=inviati'; break;
    default: break;
  }
}

/** Voci del menu "⋯" in base alle azioni consentite dal server. */
export function vociMenu(g) {
  const a = g.azioni || [];
  const v = [{ azione: 'apri', etichetta: 'Apri scheda' }];
  const modifica = a.includes('modifica_tutto');
  if (modifica || a.includes('modifica_testi')) v.push({ azione: 'modifica', etichetta: modifica ? 'Modifica gruppo' : 'Modifica testi e foto' });
  if (a.includes('prolunga') || modifica) v.push({ azione: 'prolunga', etichetta: 'Prolunga la scadenza…' });
  if (a.includes('chiudi_ora')) v.push({ azione: 'chiudi_ora', etichetta: 'Chiudi ora…' });
  if (a.includes('duplica') && g.azione_principale !== 'duplica') v.push({ azione: 'duplica', etichetta: 'Duplica come nuovo gruppo' });
  if (a.includes('annulla')) v.push({ azione: 'annulla', etichetta: 'Annulla gruppo e rimborsa tutti…', pericolo: true });
  if (a.includes('elimina')) v.push({ azione: 'elimina', etichetta: 'Elimina…', pericolo: true });
  return v.filter(x => !(x.azione === g.azione_principale));
}

export const ETICHETTA_PRINCIPALE = {
  chiudi_e_addebita: 'Chiudi e addebita', prepara_ordine: 'Prepara ordine', merce_arrivata: 'Merce arrivata',
  apri: 'Apri', duplica: 'Duplica', pubblica: 'Pubblica'
};
