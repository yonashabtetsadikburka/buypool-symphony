import { apiGet } from '../../api.js';
import { esc } from '../../utils.js';
import { euro, pezzi, plurale, dataGGMMAAAA, oraHHMM } from '../../format.js';
import { attivaMenuAzioni, menuAzioniHtml, chiudiMenuAzioni } from '../../components/menu-azioni.js';
import { showToast } from '../../components/toast.js';
import { chipStato, eseguiAzione, vociMenu, ETICHETTA_PRINCIPALE, imgUrl } from './gruppi-azioni.js';

/* Scheda del gruppo (GET /admin/gruppi/{id}): intestazione, indicatori, scaglioni, partecipanti, soldi, cronologia. */

const CONSEGNA = { ritiro: 'Ritiro in sede', domicilio: 'Spedizione a domicilio' };
const STATO_ADESIONE = {
  prenotata: ['In attesa del minimo', 'adm-chip-grigio'], confermata: ['Confermata', 'adm-chip-teal'], pagata: ['Pagata', 'adm-chip-verde'],
  azione_richiesta: ['Saldo non riuscito', 'adm-chip-ambra'], rimborsata: ['Rimborsata', 'adm-chip-grigio'], annullata: ['Annullata', 'adm-chip-grigio']
};

const quando = (v) => `${dataGGMMAAAA(v)} alle ${oraHHMM(v)}`;
const barra = (pct) => `<span class="adm-barra" role="img" aria-label="${pct}%"><span style="width:${pct}%"></span></span>`;

function indicatori(s) {
  const g = s.gruppo, p = s.pezzi;
  const pct = Math.min(100, Math.round((p.attuali / Math.max(1, p.prossimo ? p.prossimo.soglia : p.minimo)) * 100));
  const prossimo = p.prossimo
    ? `Mancano ${plurale(p.prossimo.mancano, 'pezzo', 'pezzi')} a ${esc(euro(p.prossimo.prezzo))} (${p.prossimo.soglia} pezzi)`
    : (s.scaglioni.length ? 'Ultimo scaglione raggiunto' : `Minimo: ${p.minimo} pezzi`);
  const scaduto = new Date(String(g.data_limite).replace(' ', 'T')) <= new Date();
  return `<div class="adm-indicatori">
    <div class="adm-indicatore"><span class="adm-ind-label">Pezzi</span><strong class="adm-ind-valore">${p.attuali} / ${p.minimo}</strong>${barra(pct)}<span class="adm-sub">${prossimo}</span></div>
    <div class="adm-indicatore"><span class="adm-ind-label">Scadenza</span><strong class="adm-ind-valore${scaduto ? ' adm-scaduto' : ''}">${esc(dataGGMMAAAA(g.data_limite))}</strong><span class="adm-sub${scaduto ? ' adm-scaduto' : ''}">${scaduto ? 'scaduto' : ''} ore ${esc(oraHHMM(g.data_limite))}</span></div>
    <div class="adm-indicatore"><span class="adm-ind-label">Clienti</span><strong class="adm-ind-valore">${s.clienti.totale}</strong><span class="adm-sub">${s.clienti.ritiro} in sede · ${s.clienti.domicilio} a domicilio</span></div>
    <div class="adm-indicatore"><span class="adm-ind-label">Acconti incassati</span><strong class="adm-ind-valore">${esc(euro(s.soldi.acconti_incassati))}</strong></div>
  </div>`;
}

function scaglioniHtml(s) {
  if (!s.scaglioni.length) return '<p class="adm-vuoto">Nessuno scaglione: il prezzo è quello scontato.</p>';
  return `${s.scaglioni_bloccati ? '<p class="adm-nota adm-nota-lock">Minimo, scaglioni e prezzi sono bloccati perché ci sono già adesioni.</p>' : ''}
    <div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta">
      <thead><tr><th scope="col">Scaglione</th><th scope="col">Da (pezzi)</th><th scope="col" class="adm-num">Prezzo</th><th scope="col">Stato</th></tr></thead>
      <tbody>${s.scaglioni.map((x, i) => `<tr><td>${i === 0 ? 'Minimo' : `Scaglione ${i + 1}`}</td><td>${x.soglia}</td><td class="adm-num">${esc(euro(x.prezzo))}</td>
        <td>${x.raggiunto ? '<span class="adm-chip adm-chip-verde">Raggiunto</span>' : '<span class="adm-chip adm-chip-grigio">Da raggiungere</span>'}</td></tr>`).join('')}</tbody></table></div>`;
}

function partecipantiHtml(s) {
  if (!s.partecipanti.length) return '<p class="adm-vuoto">Ancora nessuna adesione.</p>';
  return `<div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta">
    <thead><tr><th scope="col">Cliente</th><th scope="col">Pezzi</th><th scope="col">Consegna</th><th scope="col" class="adm-num">Acconto</th><th scope="col" class="adm-num">Saldo</th><th scope="col">Stato</th></tr></thead>
    <tbody>${s.partecipanti.map(p => {
      const [et, cls] = STATO_ADESIONE[p.stato] || [p.stato, 'adm-chip-grigio'];
      const saldo = ['rimborsata', 'annullata'].includes(p.stato) ? '—' : `${esc(euro(p.saldo))}${p.saldo_pagato ? '' : '<span class="adm-sub">previsto</span>'}`;
      return `<tr><td>${esc(p.cliente)}</td><td>${p.quantita}</td><td>${esc(CONSEGNA[p.consegna])}</td><td class="adm-num">${esc(euro(p.acconto))}</td><td class="adm-num">${saldo}</td><td><span class="adm-chip ${cls}">${esc(et)}</span></td></tr>`;
    }).join('')}</tbody></table></div>`;
}

function soldiHtml(s) {
  const m = s.soldi;
  const riga = (k, v, extra = '') => `<div class="adm-riepilogo-riga"><span>${k}</span><strong>${esc(v)}</strong></div>${extra}`;
  return `<div class="adm-riepilogo">
    ${riga('Acconti incassati', euro(m.acconti_incassati))}
    ${riga(`Prezzo ${m.stimato ? 'stimato' : 'finale'} × pezzi (${esc(euro(m.prezzo))} × ${m.pezzi})`, euro(m.merce))}
    ${riga('Commissione', euro(m.commissione))}
    ${riga('Spedizioni', euro(m.spedizioni))}
    ${riga('Saldo previsto', euro(m.saldo_previsto))}
    ${riga('Saldi già incassati', euro(m.saldi_incassati))}
  </div>
  ${m.stimato ? '<p class="adm-nota">Il saldo è una stima: lo scaglione può ancora cambiare finché il gruppo è aperto.</p>' : ''}
  ${m.costo_buypool > 0 ? `<p class="adm-avviso adm-avviso-ambra" role="status"><strong>Costo a carico di BuyPool: ${esc(euro(m.costo_buypool))}</strong><br>Un cliente non ha pagato il saldo: il prezzo degli altri resta quello fissato alla chiusura, ma il fornitore applica uno scaglione più caro. Decidi a mano se intervenire (per esempio recuperando pezzi).</p>` : ''}`;
}

function cronologiaHtml(s) {
  if (!s.cronologia.length) return '<p class="adm-vuoto">Nessun evento registrato.</p>';
  return `<ol class="adm-cronologia">${s.cronologia.slice().reverse().map(e => `<li><span class="adm-cron-data">${esc(quando(e.data))}</span> <span>${esc(e.testo)}${e.chi ? ` <span class="adm-sub">— ${esc(e.chi)}</span>` : ''}</span></li>`).join('')}</ol>`;
}

function intestazione(s) {
  const g = s.gruppo;
  const g2 = { id: g.id, prodotto: g.prodotto, azioni: s.azioni, azione_principale: s.azioni.includes('chiudi_e_addebita') ? 'chiudi_e_addebita' : 'apri' };
  const bott = [];
  if (s.azioni.includes('chiudi_e_addebita')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="chiudi_e_addebita" data-id="${g.id}">${ETICHETTA_PRINCIPALE.chiudi_e_addebita}</button>`);
  if (s.azioni.includes('pubblica')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="pubblica" data-id="${g.id}">Pubblica</button>`);
  if (s.azioni.includes('duplica')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="duplica" data-id="${g.id}">Duplica come nuovo gruppo</button>`);
  if (s.azioni.includes('segna_inviato')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="prepara_ordine" data-id="${g.id}">Prepara ordine</button>`);
  if (s.azioni.includes('segna_arrivato')) bott.push(`<button type="button" class="adm-btn adm-btn-pri" data-azione="merce_arrivata" data-id="${g.id}">Merce arrivata</button>`);
  if (s.azioni.includes('prolunga') || s.azioni.includes('modifica_tutto')) bott.push(`<button type="button" class="adm-btn adm-btn-sec" data-azione="prolunga" data-id="${g.id}">Prolunga scadenza</button>`);
  if (s.azioni.includes('chiudi_ora')) bott.push(`<button type="button" class="adm-btn adm-btn-sec" data-azione="chiudi_ora" data-id="${g.id}">Chiudi ora</button>`);
  if (s.azioni.includes('modifica_tutto') || s.azioni.includes('modifica_testi')) bott.push(`<button type="button" class="adm-btn adm-btn-sec" data-azione="modifica" data-id="${g.id}">Modifica testi e foto</button>`);
  const nelMenu = vociMenu(g2).filter(v => !['apri', 'modifica', 'prolunga', 'chiudi_ora', 'duplica'].includes(v.azione));
  return `<div class="adm-scheda-testa">
    <a class="adm-indietro" href="#/admin/campagne">← Tutti i gruppi</a>
    <div class="adm-intestazione adm-intestazione-scheda">
      <div class="adm-scheda-titolo">
        ${g.immagine ? `<img class="adm-scheda-foto" src="${esc(imgUrl(g.immagine))}" alt="">` : ''}
        <div class="adm-scheda-testo">
          <h1 class="adm-h1">${esc(g.prodotto)}</h1>
          <div>${chipStato(s.stato, s.stato_etichetta)}</div>
          <p class="adm-scheda-riga">Fornitore: ${esc(g.fornitore)}</p>
          <p class="adm-scheda-riga">Sede: ${esc(g.sede || '—')}</p>
          <p class="adm-scheda-riga adm-scheda-riga-sec">Pubblicato da BuyPool il ${esc(dataGGMMAAAA(g.data_inizio))}</p>
          ${g.motivo_annullamento ? `<p class="adm-avviso adm-avviso-rosso">Motivo dell'annullamento: ${esc(g.motivo_annullamento)}</p>` : ''}
        </div>
      </div>
      <div class="adm-azioni-scheda">${bott.join('')}${menuAzioniHtml(g.id, nelMenu, 'Altre azioni')}</div>
    </div>
  </div>`;
}

export async function AdminGruppoPage(params) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  const id = parseInt(params?.id);
  content.innerHTML = '<div class="content-area adm-pagina"><div class="loading-spinner">Caricamento...</div></div>';

  const carica = async () => {
    const s = (await apiGet(`/admin/gruppi/${id}`)).dati;
    window.__gruppoScheda = s;
    content.innerHTML = `<div class="content-area adm-pagina" id="adm-scheda">
      ${intestazione(s)}
      ${indicatori(s)}
      <div class="adm-griglia">
        <section class="adm-card adm-sezione"><h2 class="adm-h2">Scaglioni</h2>${scaglioniHtml(s)}</section>
        <section class="adm-card adm-sezione"><h2 class="adm-h2">Soldi</h2>${soldiHtml(s)}</section>
      </div>
      <section class="adm-card adm-sezione"><h2 class="adm-h2">Partecipanti</h2>${partecipantiHtml(s)}</section>
      <section class="adm-card adm-sezione"><h2 class="adm-h2">Cronologia</h2>${cronologiaHtml(s)}</section>
    </div>`;
  };
  const aggiorna = () => carica().catch(e => showToast({ title: 'Scheda non aggiornata', description: esc(e.message), variant: 'error' }));

  try {
    await carica();
  } catch (err) {
    content.innerHTML = `<div class="content-area adm-pagina"><a class="adm-indietro" href="#/admin/campagne">← Tutti i gruppi</a><div class="empty-state"><h2>Gruppo non disponibile</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
    return;
  }
  const root = document.getElementById('adm-scheda');
  attivaMenuAzioni(root);
  root.addEventListener('click', (e) => {
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    chiudiMenuAzioni();
    const s = window.__gruppoScheda;
    eseguiAzione(b.dataset.azione, { id, prodotto: s.gruppo.prodotto, azioni: s.azioni }, (r) => {
      // dopo un duplicato si passa alla nuova scheda (gestito in duplicaGruppo); dopo una cancellazione si torna alla lista
      if (b.dataset.azione === 'elimina') { window.location.hash = '#/admin/campagne'; return; }
      aggiorna();
    });
  });
}
