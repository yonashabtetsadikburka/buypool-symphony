import { getState } from '../state.js';
import { apiGet, apiPost, apiPostForm, apiDelete } from '../api.js';
import { isAdmin, isFornitore } from '../auth.js';
import { showToast } from '../components/toast.js';
import { apriShare, chiudiShare, shareGridHtml, attivaShare } from '../components/share.js';
import { ACCONTO_PERCENT, API_URL } from '../constants.js';
import { esc, tempoRelativo, dominioDa } from '../utils.js';

const TABS = [
  ['votazione', 'In votazione'],
  ['valutazione', 'In valutazione'],
  ['gruppi', 'Diventate gruppi'],
  ['mie', 'Le mie proposte']
];
const ORDINAMENTI = [
  ['votate', 'Più votate'],
  ['recenti', 'Più recenti'],
  ['quasi', 'Quasi a 5 voti'],
  ['scadenza', 'In scadenza']
];
const STATO_TAB = { in_votazione: 'votazione', in_valutazione: 'valutazione', pubblicata: 'gruppi' };
const EMPTY = {
  votazione: 'Nessuna proposta in votazione. Sii il primo a proporre qualcosa.',
  valutazione: 'Nessuna proposta in valutazione al momento.',
  gruppi: 'Nessuna proposta è ancora diventata un gruppo d\'acquisto.',
  mie: 'Non hai ancora proposto nulla.'
};

const ICONA = {
  plus: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>',
  pollice: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"/>',
  spunta: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>',
  cerca: '<circle cx="11" cy="11" r="7" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M21 21l-4.35-4.35"/>',
  gruppo: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
  orologio: '<circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2"/>',
  pacco: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
  condividi: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>',
  filtri: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6h18M7 12h10M10 18h4"/>'
};
const svg = (nome, dim = 18) =>
  `<svg width="${dim}" height="${dim}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${ICONA[nome]}</svg>`;

let dati = null;       // risposta di /proposte/bacheca
let tab = 'votazione';
let ordina = 'votate';
let evidenzia = null;  // id proposta aperta da un link condiviso

function leggiParametri() {
  const q = new URLSearchParams((location.hash.split('?')[1]) || '');
  const t = q.get('tab');
  const o = q.get('ordina');
  tab = TABS.some(([k]) => k === t) ? t : '';
  ordina = ORDINAMENTI.some(([k]) => k === o) ? o : 'votate';
  evidenzia = parseInt(q.get('p')) || null;
}

function scriviParametri() {
  history.replaceState(null, '', `#/proposte?tab=${tab}&ordina=${ordina}`);
}

function annuncia(testo) {
  const el = document.getElementById('pp-live');
  if (!el) return;
  el.textContent = '';
  setTimeout(() => { el.textContent = testo; }, 30);
}

function mancano(p) {
  const n = Math.max(0, dati.soglia - p.tot_voti);
  return n === 1 ? 'manca 1 voto' : `mancano ${n} voti`;
}

function etichettaStato(p) {
  if (p.stato === 'in_attesa') return ['In attesa', 'gray'];
  if (p.mia && p.stato === 'in_votazione') return ['Tua proposta', 'teal'];
  switch (p.stato) {
    case 'in_valutazione': return ['In valutazione', 'amber'];
    case 'pubblicata': return ['Gruppo aperto', 'green'];
    case 'scaduta': return ['Scaduta', 'gray'];
    case 'rifiutata': return ['Non accolta', 'gray'];
    default: return null;
  }
}

function azioniHtml(p) {
  const btnShare = `<button type="button" class="pp-btn-square" data-azione="share" aria-label="Chiedi voti ai tuoi amici" title="Chiedi voti ai tuoi amici">${svg('condividi')}</button>`;
  switch (p.stato) {
    case 'in_votazione':
      if (p.mia) {
        return `<p class="pp-note pp-note-plain">È la tua proposta: non puoi votarla.</p>
          <button type="button" class="pp-btn pp-btn-primary" data-azione="share">${svg('condividi')} Chiedi voti ai tuoi amici</button>`;
      }
      return `<div class="pp-actions-row">
        ${p.ho_votato
          ? `<button type="button" class="pp-btn pp-btn-voted" data-azione="vota" aria-pressed="true">${svg('spunta')} Hai votato</button>`
          : `<button type="button" class="pp-btn pp-btn-primary" data-azione="vota" aria-pressed="false">${svg('pollice')} Voto anch'io</button>`}
        ${btnShare}
      </div>`;
    case 'in_valutazione':
      return `<p class="pp-note pp-note-amber">In valutazione: BuyPool sta cercando il fornitore e definendo gli scaglioni di prezzo.</p>
        ${(p.mia || p.ho_votato) ? '<button type="button" class="pp-btn pp-btn-disabled" disabled>Ti avvisiamo noi</button>' : ''}`;
    case 'pubblicata':
      return `<p class="pp-note pp-note-green">La proposta è diventata un gruppo d'acquisto: ora puoi unirti.</p>
        ${p.campagna_id ? `<a class="pp-btn pp-btn-primary" href="#/campagne/${p.campagna_id}">Vedi il gruppo &rarr;</a>` : ''}`;
    case 'scaduta':
      return `<p class="pp-note pp-note-gray">Dopo ${dati.giorni_validita} giorni non ha raggiunto i ${dati.soglia} voti. Puoi riproporla quando vuoi.</p>
        ${p.mia ? '<button type="button" class="pp-btn pp-btn-primary" data-azione="nuova">Nuova proposta</button>' : ''}`;
    case 'rifiutata':
      return `<p class="pp-note pp-note-gray">${p.motivo ? esc(p.motivo) : 'Questa proposta non è stata accolta.'}</p>`;
    case 'in_attesa':
      return '<p class="pp-note pp-note-gray">BuyPool sta controllando la proposta: poi si aprirà alla votazione.</p>';
    default:
      return '';
  }
}

function cardHtml(p) {
  const et = etichettaStato(p);
  const pct = Math.min(100, Math.round((p.tot_voti / dati.soglia) * 100));
  const dominio = p.link ? dominioDa(p.link) : null;
  const foto = p.foto
    ? `<img src="${esc(API_URL + '/' + p.foto)}" alt="" loading="lazy">`
    : `<div class="pp-nophoto">${svg('pacco', 40)}<span>Nessuna foto</span></div>`;
  const destra = p.tot_voti >= dati.soglia ? 'Soglia raggiunta'
    : (p.stato === 'in_votazione' ? mancano(p) : '');
  const giorni = p.giorni_rimasti;
  const scade = giorni <= 0 ? 'Scade oggi' : (giorni === 1 ? 'Scade tra 1 giorno' : `Scade tra ${giorni} giorni`);
  return `
    <article class="pp-card" id="pp-card-${p.id_proposta}" data-id="${p.id_proposta}">
      <div class="pp-photo">
        ${foto}
        ${p.categoria ? `<span class="pp-cat">${esc(p.categoria)}</span>` : ''}
        ${et ? `<span class="pp-tag pp-tag-${et[1]}">${et[0]}</span>` : ''}
      </div>
      <div class="pp-body">
        <h3 class="pp-title">${esc(p.nome_prodotto)}</h3>
        ${p.descrizione ? `<p class="pp-desc">${esc(p.descrizione)}</p>` : ''}
        ${dominio ? `<a class="pp-link" href="${esc(p.link)}" target="_blank" rel="noopener noreferrer nofollow">Vedi il prodotto su ${esc(dominio)} &nearr;</a>` : ''}
        <div class="pp-author"><span class="pp-avatar" aria-hidden="true">${esc(p.iniziali)}</span>
          <span>${esc(p.proponente)} &middot; ${esc(tempoRelativo(p.data_proposta))}</span></div>
        <div class="pp-votes">
          <div class="pp-votes-row"><strong>${p.tot_voti} / ${dati.soglia} voti</strong><span>${destra}</span></div>
          <div class="pp-bar" role="progressbar" aria-label="Voti raccolti" aria-valuemin="0" aria-valuemax="${dati.soglia}" aria-valuenow="${Math.min(p.tot_voti, dati.soglia)}">
            <div class="pp-bar-fill" style="width:${pct}%"></div>
          </div>
        </div>
        ${p.stato === 'in_votazione' ? `<div class="pp-expiry">${svg('orologio', 16)}<span>${scade}</span></div>` : ''}
        <div class="pp-actions">${azioniHtml(p)}</div>
      </div>
    </article>`;
}

function filtriHtml() {
  const pill = ([k, label]) => `<button type="button" class="pp-pill${tab === k ? ' active' : ''}" data-tab="${k}" aria-pressed="${tab === k}">${label} &middot; ${dati.conteggi[k] ?? 0}</button>`;
  return `
    <div class="pp-filters">
      <div class="pp-pills" role="group" aria-label="Filtra per stato">${TABS.map(pill).join('')}</div>
      <button type="button" class="pp-filtri-btn" id="pp-filtri-btn" aria-expanded="false" aria-controls="pp-filtri-panel">${svg('filtri', 18)} Filtri</button>
      <div class="pp-filtri-panel" id="pp-filtri-panel">
        <label class="pp-select-label" for="pp-ordina">Ordina</label>
        <select id="pp-ordina" class="pp-select">
          ${ORDINAMENTI.map(([k, l]) => `<option value="${k}"${ordina === k ? ' selected' : ''}>${l}</option>`).join('')}
        </select>
      </div>
    </div>`;
}

function grigliaHtml() {
  if (!dati.proposte.length) {
    return `<div class="pp-empty"><p>${EMPTY[tab]}</p>
      <button type="button" class="pp-btn pp-btn-primary" data-azione="nuova">${svg('plus', 16)} Nuova proposta</button></div>`;
  }
  return `<div class="pp-grid">${dati.proposte.map(cardHtml).join('')}</div>`;
}

function passiHtml() {
  const passi = [
    ['plus', '1. Proponi', 'scegli un prodotto'],
    ['pollice', '2. La community vota', `bastano ${dati.soglia} voti`],
    ['cerca', '3. BuyPool valuta', 'cerca il fornitore'],
    ['gruppo', '4. Si apre il gruppo', `puoi unirti con il ${ACCONTO_PERCENT}%`]
  ];
  return `
    <section class="pp-steps" aria-label="Dalla proposta al gruppo">
      <ol class="pp-steps-list">
        ${passi.map(([ic, t, s]) => `<li><span class="pp-step-circle">${svg(ic, 20)}</span>
          <span><strong>${t}</strong><small>${s}</small></span></li>`).join('')}
      </ol>
      <div class="pp-steps-pill">${svg('orologio', 16)} Le proposte restano aperte ${dati.giorni_validita} giorni</div>
    </section>`;
}

function paginaHtml() {
  return `
    <div class="pp-head">
      <div>
        <h1>Proposte</h1>
        <p>Cosa vorresti comprare in gruppo? Proponilo: con ${dati.soglia} voti lo valutiamo e, se va bene, apriamo un gruppo d'acquisto.</p>
      </div>
      <button type="button" class="pp-btn pp-btn-primary pp-btn-new" data-azione="nuova">${svg('plus', 16)} Nuova proposta</button>
    </div>
    ${passiHtml()}
    <div id="pp-filtri">${filtriHtml()}</div>
    <div id="pp-griglia">${grigliaHtml()}</div>
    <div id="pp-live" class="pp-sr" aria-live="polite"></div>`;
}

function trova(id) {
  return dati.proposte.find(p => p.id_proposta === id);
}

function ridisegnaCard(p) {
  const el = document.getElementById(`pp-card-${p.id_proposta}`);
  if (!el) return;
  el.outerHTML = cardHtml(p);
  document.querySelector(`#pp-card-${p.id_proposta} [data-azione="vota"], #pp-card-${p.id_proposta} [data-azione="share"]`)?.focus();
}

async function vota(id, btn) {
  const p = trova(id);
  if (!p) return;
  const eraVotato = p.ho_votato;
  btn.disabled = true;
  try {
    const hdr = { 'X-CSRF-Token': dati.csrf };
    const res = eraVotato
      ? await apiDelete(`/proposte/${id}/vota`, hdr)
      : await apiPost(`/proposte/${id}/vota`, { valore_voto: 'favore' }, hdr);
    const r = res.dati;
    const vecchio = STATO_TAB[p.stato];
    p.tot_voti = r.tot_voti;
    p.ho_votato = r.ho_votato;
    p.stato = r.stato;
    const nuovo = STATO_TAB[p.stato];
    if (vecchio !== nuovo) {
      dati.conteggi[vecchio] = Math.max(0, (dati.conteggi[vecchio] || 0) - 1);
      dati.conteggi[nuovo] = (dati.conteggi[nuovo] || 0) + 1;
      document.getElementById('pp-filtri').innerHTML = filtriHtml();
    }
    ridisegnaCard(p);
    annuncia(r.stato === 'in_valutazione'
      ? `Voto registrato. La proposta ha raggiunto ${dati.soglia} voti ed è ora in valutazione.`
      : (eraVotato ? 'Voto rimosso.' : `Voto registrato: ${r.tot_voti} su ${dati.soglia}.`));
  } catch (err) {
    showToast({ title: 'Impossibile completare l\'azione', description: esc(err.message), variant: 'error' });
    annuncia(err.message);
    ridisegnaCard(p);   // ripristina lo stato precedente
    if (err.codice && err.codice !== 'CSRF_NON_VALIDO') carica();   // riallinea con il server
  }
}

function condividi(p, ancora) {
  const url = `${location.origin}${location.pathname}#/proposte?p=${p.id_proposta}`;
  apriShare(ancora, { url, testo: `Aiutami a far partire un gruppo d'acquisto su BuyPool: vota "${p.nome_prodotto}"!` });
}

async function carica() {
  const grigliaEl = document.getElementById('pp-griglia');
  const q = new URLSearchParams({ ordina });
  if (tab) q.set('tab', tab);
  if (evidenzia && !tab) q.set('p', evidenzia);
  try {
    const res = await apiGet(`/proposte/bacheca?${q}`);
    dati = res.dati;
    tab = dati.tab;
    scriviParametri();
    chiudiShare();
    if (!document.getElementById('pp-live')) {
      document.getElementById('pp-root').innerHTML = paginaHtml();
    } else {
      document.getElementById('pp-filtri').innerHTML = filtriHtml();
      grigliaEl.innerHTML = grigliaHtml();
    }
    if (evidenzia) {
      const el = document.getElementById(`pp-card-${evidenzia}`);
      if (el) { el.classList.add('pp-card-evidenza'); el.scrollIntoView({ block: 'center' }); }
      evidenzia = null;
    }
  } catch (err) {
    document.getElementById('pp-root').innerHTML =
      `<div class="pp-empty"><p>${esc(err.message)}</p></div>`;
  }
}

function cambiaTab(nuovo) {
  tab = nuovo;
  carica().then(() => document.querySelector(`.pp-pill[data-tab="${nuovo}"]`)?.focus());
}
window.switchProposteTab = cambiaTab;

function gestisciClick(e) {
  const pill = e.target.closest('.pp-pill');
  if (pill) { cambiaTab(pill.dataset.tab); return; }
  if (e.target.closest('#pp-filtri-btn')) {
    const b = document.getElementById('pp-filtri-btn');
    const aperto = document.getElementById('pp-filtri-panel').classList.toggle('open');
    b.setAttribute('aria-expanded', String(aperto));
    return;
  }
  const btn = e.target.closest('[data-azione]');
  if (!btn) return;
  const card = btn.closest('.pp-card');
  const p = card ? trova(parseInt(card.dataset.id)) : null;
  switch (btn.dataset.azione) {
    case 'nuova': window.apriPropostaModal(); break;
    case 'vota': if (p) vota(p.id_proposta, btn); break;
    case 'share': if (p) condividi(p, btn); break;
  }
}

// ---------- Modulo "Nuova proposta" ----------
const FOTO_TIPI = ['image/jpeg', 'image/png', 'image/webp'];
const FOTO_MAX_BYTE = 5 * 1024 * 1024;
const CAMPI_MODAL = {
  nome_prodotto: 'pm-nome', descrizione: 'pm-descr', id_categoria: 'pm-cat',
  link_riferimento: 'pm-link', prezzo_indicativo: 'pm-prezzo', foto: 'pm-foto'
};
let modalUrlAnteprima = null;
let modalTimerSimili = null;
let modalSeqSimili = 0;
let modalInviata = false;

function modalErrore(campo, testo) {
  const input = document.getElementById(CAMPI_MODAL[campo]);
  const box = document.getElementById(`${CAMPI_MODAL[campo]}-err`);
  if (!input || !box) return;
  box.textContent = testo || '';
  box.hidden = !testo;
  if (testo) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
}

function modalPulisciErrori() {
  Object.keys(CAMPI_MODAL).forEach(c => modalErrore(c, ''));
  const g = document.getElementById('proposta-modal-error');
  if (g) g.hidden = true;
}

/** Stessi controlli del server sui campi (il server li rifà comunque). */
function modalValida(form) {
  const e = {};
  const nome = form.nome_prodotto.value.trim().replace(/\s+/g, ' ');
  if (nome.length < 3) e.nome_prodotto = 'Il nome deve avere almeno 3 caratteri';
  else if (nome.length > 80) e.nome_prodotto = 'Il nome può avere al massimo 80 caratteri';
  const descr = form.descrizione.value.trim();
  if (!descr) e.descrizione = 'La descrizione è obbligatoria';
  else if (descr.length > 300) e.descrizione = 'La descrizione può avere al massimo 300 caratteri';
  const link = form.link_riferimento.value.trim();
  if (link) {
    if (link.length > 500) e.link_riferimento = 'Il link può avere al massimo 500 caratteri';
    else if (!/^https?:\/\/[^\s/$.?#][^\s]*$/i.test(link)) e.link_riferimento = 'Inserisci un link valido che inizi con http:// o https://';
  }
  const prezzo = form.prezzo_indicativo.value.trim().replace(',', '.');
  if (prezzo) {
    if (prezzo.startsWith('-')) e.prezzo_indicativo = 'Il prezzo non può essere negativo';
    else if (!/^\d{1,7}(\.\d{1,2})?$/.test(prezzo)) e.prezzo_indicativo = 'Inserisci un prezzo valido (es. 49,90)';
  }
  return e;
}

function modalMostraErrori(campi) {
  modalPulisciErrori();
  let primo = null;
  Object.entries(campi).forEach(([c, t]) => {
    if (!CAMPI_MODAL[c]) return;
    modalErrore(c, t);
    if (!primo) primo = CAMPI_MODAL[c];
  });
  document.getElementById(primo)?.focus();
}

function modalRimuoviFoto() {
  const input = document.getElementById('pm-foto');
  if (input) input.value = '';
  if (modalUrlAnteprima) { URL.revokeObjectURL(modalUrlAnteprima); modalUrlAnteprima = null; }
  const box = document.getElementById('pm-anteprima');
  if (box) { box.hidden = true; box.innerHTML = ''; }
}

function modalFotoScelta(input) {
  modalErrore('foto', '');
  const f = input.files && input.files[0];
  if (!f) { modalRimuoviFoto(); return; }
  // Controllo solo visivo: il server rifà tutte le verifiche sul contenuto
  if (!FOTO_TIPI.includes(f.type)) {
    modalRimuoviFoto();
    modalErrore('foto', 'Formato non supportato: usa JPEG, PNG o WebP');
    return;
  }
  if (f.size > FOTO_MAX_BYTE) {
    modalRimuoviFoto();
    modalErrore('foto', 'Il file supera 5 MB');
    return;
  }
  if (modalUrlAnteprima) URL.revokeObjectURL(modalUrlAnteprima);
  modalUrlAnteprima = URL.createObjectURL(f);
  const box = document.getElementById('pm-anteprima');
  box.innerHTML = `<img src="${modalUrlAnteprima}" alt="Anteprima della foto scelta">
    <button type="button" class="pp-btn pp-btn-outline" data-modal="rimuovi-foto">Rimuovi foto</button>`;
  box.hidden = false;
}

function modalCercaSimili(valore) {
  clearTimeout(modalTimerSimili);
  const box = document.getElementById('pm-simili');
  const q = valore.trim();
  if (q.length < 3) { modalSeqSimili++; box.hidden = true; box.innerHTML = ''; return; }
  modalTimerSimili = setTimeout(async () => {
    const mio = ++modalSeqSimili;
    try {
      const res = await apiGet(`/proposte/simili?q=${encodeURIComponent(q)}`);
      if (mio !== modalSeqSimili || !document.getElementById('pm-simili')) return;   // risposta superata
      const lista = res.dati || [];
      if (!lista.length) { box.hidden = true; box.innerHTML = ''; return; }
      box.innerHTML = `<p class="pp-simili-titolo">Esiste già una proposta simile: vota quella</p>
        <ul>${lista.map(s => `<li><span class="pp-simili-nome">${esc(s.nome)}</span>
          <span class="pp-simili-voti">${s.tot_voti} ${s.tot_voti === 1 ? 'voto' : 'voti'}</span>
          <a href="${esc(s.link)}" data-simile="${s.id_proposta}">Vai alla proposta &rarr;</a></li>`).join('')}</ul>`;
      box.hidden = false;
    } catch (err) { box.hidden = true; }   // l'avviso è solo di cortesia: errori ignorati
  }, 400);
}

window.chiudiPropostaModal = function() {
  clearTimeout(modalTimerSimili);
  modalSeqSimili++;
  if (modalUrlAnteprima) { URL.revokeObjectURL(modalUrlAnteprima); modalUrlAnteprima = null; }
  document.getElementById('proposta-modal')?.remove();
  if (modalInviata) { modalInviata = false; cambiaTab('mie'); }
};

window.apriPropostaModal = async function() {
  document.getElementById('proposta-modal')?.remove();
  chiudiShare();
  modalInviata = false;
  const soglia = dati ? dati.soglia : '';
  const campo = (id, etichetta, controllo, extra = '') => `
    <div class="input-group">
      <label class="input-label" for="${id}">${etichetta}</label>
      ${controllo}
      ${extra}
      <p class="pp-field-error" id="${id}-err" role="alert" hidden></p>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', `
    <div id="proposta-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="pm-titolo">
      <div class="modal-content card" style="max-width:520px;width:92%;max-height:90vh;overflow-y:auto;">
        <div class="card-content" id="pm-contenuto">
          <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:var(--space-1);">
            <h3 id="pm-titolo" style="font-size:var(--text-lg);font-weight:var(--font-semibold);">Nuova proposta</h3>
            <button type="button" class="btn btn-ghost btn-sm" data-modal="chiudi" aria-label="Chiudi">&#10005;</button>
          </div>
          <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">Servono almeno ${soglia} voti dalla community perché venga considerata.</p>
          <p class="pp-field-error" id="proposta-modal-error" role="alert" hidden></p>
          <form id="proposta-modal-form" novalidate style="display:flex;flex-direction:column;gap:var(--space-3);">
            ${campo('pm-nome', 'Nome prodotto *',
              '<input type="text" id="pm-nome" name="nome_prodotto" class="input" placeholder="Es: Smartwatch XYZ" maxlength="80" required aria-describedby="pm-nome-err pm-simili" autocomplete="off">',
              '<div id="pm-simili" class="pp-simili" aria-live="polite" hidden></div>')}
            ${campo('pm-descr', 'Descrizione breve * <span class="pp-counter" id="pm-conta">0/300</span>',
              '<textarea id="pm-descr" name="descrizione" class="input" rows="3" maxlength="300" placeholder="Descrivi il prodotto..." style="height:auto;" required aria-describedby="pm-descr-err"></textarea>')}
            <div id="pm-cat-wrap" hidden>
              ${campo('pm-cat', 'Categoria', '<select id="pm-cat" name="id_categoria" class="input"><option value="">Nessuna</option></select>')}
            </div>
            ${campo('pm-link', 'Link di riferimento (facoltativo)',
              '<input type="url" id="pm-link" name="link_riferimento" class="input" placeholder="https://..." maxlength="500" aria-describedby="pm-link-err">')}
            ${campo('pm-prezzo', 'Prezzo indicativo in € (facoltativo)',
              '<input type="text" id="pm-prezzo" name="prezzo_indicativo" class="input" inputmode="decimal" placeholder="Es: 49,90" aria-describedby="pm-prezzo-err">')}
            ${campo('pm-foto', 'Foto del prodotto (facoltativa)',
              '<input type="file" id="pm-foto" name="foto" class="input" accept="image/jpeg,image/png,image/webp" aria-describedby="pm-foto-err pm-foto-hint">',
              '<p class="text-xs text-secondary" id="pm-foto-hint">JPEG, PNG o WebP, massimo 5 MB, almeno 300×300 px.</p><div id="pm-anteprima" class="pp-anteprima" hidden></div>')}
            <div style="display:flex;gap:var(--space-2);">
              <button type="submit" class="btn btn-default" style="flex:1;" id="pm-invia">Invia proposta</button>
              <button type="button" class="btn btn-outline" style="flex:1;" data-modal="chiudi">Annulla</button>
            </div>
          </form>
        </div>
      </div>
    </div>`);

  const modal = document.getElementById('proposta-modal');
  const form = document.getElementById('proposta-modal-form');
  document.getElementById('pm-nome').focus();

  modal.addEventListener('click', (e) => {
    if (e.target === modal) { window.chiudiPropostaModal(); return; }
    const b = e.target.closest('[data-modal]');
    if (b?.dataset.modal === 'chiudi') window.chiudiPropostaModal();
    if (b?.dataset.modal === 'rimuovi-foto') modalRimuoviFoto();
    const s = e.target.closest('[data-simile]');
    if (s) {   // porta alla proposta simile senza ricaricare la pagina
      e.preventDefault();
      const id = parseInt(s.dataset.simile);
      window.chiudiPropostaModal();
      tab = '';
      evidenzia = id;
      carica();
    }
  });
  modal.addEventListener('keydown', (e) => { if (e.key === 'Escape') window.chiudiPropostaModal(); });
  document.getElementById('pm-nome').addEventListener('input', (e) => modalCercaSimili(e.target.value));
  document.getElementById('pm-descr').addEventListener('input', (e) => {
    document.getElementById('pm-conta').textContent = `${e.target.value.length}/300`;
  });
  document.getElementById('pm-foto').addEventListener('change', (e) => modalFotoScelta(e.target));

  apiGet('/categorie').then(res => {
    const cat = res.dati || [];
    if (!cat.length || !document.getElementById('pm-cat')) return;
    const sel = document.getElementById('pm-cat');
    cat.forEach(c => sel.add(new Option(c.nome, String(c.id))));   // Option: nessun HTML interpretato
    document.getElementById('pm-cat-wrap').hidden = false;
  }).catch(() => {});

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    modalPulisciErrori();
    const errori = modalValida(form);
    if (Object.keys(errori).length) { modalMostraErrori(errori); return; }
    const btn = document.getElementById('pm-invia');
    btn.disabled = true;
    btn.textContent = 'Invio in corso...';
    try {
      const res = await apiPostForm('/proposte', new FormData(form));
      modalInviata = true;
      modalPropostaInviata(res.dati.id_proposta);
    } catch (err) {
      btn.disabled = false;
      btn.textContent = 'Invia proposta';
      if (err.campi) {
        modalMostraErrori(err.campi);
      } else {
        const g = document.getElementById('proposta-modal-error');
        g.textContent = err.message;
        g.hidden = false;
        g.scrollIntoView({ block: 'nearest' });
      }
    }
  });
};

function modalPropostaInviata(id) {
  const url = `${location.origin}${location.pathname}#/proposte?p=${id}`;
  const testo = 'Aiutami a far partire un gruppo d\'acquisto su BuyPool: vota la mia proposta!';
  if (modalUrlAnteprima) { URL.revokeObjectURL(modalUrlAnteprima); modalUrlAnteprima = null; }
  const box = document.getElementById('pm-contenuto');
  box.innerHTML = `
    <h3 id="pm-titolo" style="font-size:var(--text-lg);font-weight:var(--font-semibold);margin-bottom:var(--space-2);">Proposta inviata!</h3>
    <p style="margin-bottom:var(--space-2);">Condividila per raccogliere i ${dati ? dati.soglia : ''} voti.</p>
    <p class="text-sm text-secondary" style="margin-bottom:var(--space-4);">I voti partono appena BuyPool l'avrà approvata: ti avvisiamo con una notifica.</p>
    <div id="pm-share">${shareGridHtml(url, testo)}</div>
    <button type="button" class="btn btn-default w-full" style="margin-top:var(--space-4);" data-modal="chiudi">Fatto</button>`;
  attivaShare(document.getElementById('pm-share'), url, testo);
  annuncia('Proposta inviata.');
  box.querySelector('a, button')?.focus();
}

export async function PropostePage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  if (isFornitore() || isAdmin() || !getState().user) {
    content.innerHTML = `<div class="content-area pp-page"><div class="pp-empty"><p>Questa pagina è riservata ai clienti.</p>
      <a class="pp-btn pp-btn-primary" href="#/">Torna alla home</a></div></div>`;
    return;
  }
  leggiParametri();
  content.innerHTML = `<div class="content-area pp-page"><div id="pp-root"><div class="loading-spinner">Caricamento...</div></div></div>`;
  const root = document.getElementById('pp-root');
  root.addEventListener('click', gestisciClick);
  root.addEventListener('change', (e) => {
    if (e.target.id === 'pp-ordina') { ordina = e.target.value; carica(); }
  });
  carica();
}
