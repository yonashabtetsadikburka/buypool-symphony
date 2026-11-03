import { apiGet } from '../../api.js';
import { esc } from '../../utils.js';
import { euro, pezzi, plurale, dataGGMMAAAA, oraHHMM } from '../../format.js';
import { chipStato } from './gruppi-azioni.js';
import { impostaDaFare } from '../../components/header.js';

/* Dashboard admin: coda "Da fare" calcolata dagli stati (GET /admin/dashboard), indicatori e gruppi in scadenza.
   Gli stessi conteggi alimentano la campanella del menu e l'email di riepilogo: una sola fonte nel server. */

function voceHtml(v) {
  return `<li class="adm-dafare-riga">
    <span class="adm-dafare-tipo">${esc(v.tipo)}</span>
    <span class="adm-dafare-testo">${esc(v.testo)}</span>
    <a class="adm-btn adm-btn-pri adm-btn-piccolo" href="${esc(v.link)}">${esc(v.pulsante)}</a>
  </li>`;
}

function indicatoriHtml(i) {
  const k = (et, val, sub = '') => `<div class="adm-indicatore"><span class="adm-ind-label">${et}</span><strong class="adm-ind-valore">${val}</strong>${sub ? `<span class="adm-sub">${sub}</span>` : ''}</div>`;
  return `<div class="adm-indicatori">
    ${k('Gruppi aperti', i.gruppi_aperti, `${i.con_minimo} con il minimo raggiunto`)}
    ${k('Incasso totale', esc(euro(i.incasso_totale)))}
    ${k('Commissioni incassate', esc(euro(i.commissioni)))}
    ${k('Rimborsi in sospeso', i.rimborsi_sospesi, i.rimborsi_sospesi === 0 ? 'Tutto in ordine' : 'Da controllare')}
  </div>`;
}

function scadenzaHtml(g) {
  const quando = `${dataGGMMAAAA(g.data_limite)} ${oraHHMM(g.data_limite)}`;
  if (g.scaduto) return `<span class="adm-scaduto">${esc(quando)}</span><span class="adm-sub adm-scaduto">${g.giorni_scaduto < 1 ? 'scaduto oggi' : `scaduto da ${plurale(g.giorni_scaduto, 'giorno', 'giorni')}`}</span>`;
  return esc(quando);
}

function scadenzaTabella(lista) {
  if (!lista.length) return '<p class="adm-vuoto">Nessun gruppo in scadenza.</p>';
  return `<div class="adm-tabella-scroll"><table class="adm-tabella">
    <thead><tr><th scope="col">Gruppo</th><th scope="col">Stato</th><th scope="col">Pezzi / minimo</th><th scope="col">Scadenza</th></tr></thead>
    <tbody>${lista.map(g => `<tr><td data-label="Gruppo"><a class="adm-titolo-link" href="#/admin/campagne/${g.id}">${esc(g.prodotto)}</a></td>
      <td data-label="Stato">${chipStato(g.stato, g.stato_etichetta)}</td><td data-label="Pezzi / minimo">${g.pezzi} / ${g.minimo}</td><td data-label="Scadenza">${scadenzaHtml(g)}</td></tr>`).join('')}</tbody></table></div>`;
}

function rimborsiTabella(r) {
  if (!r.length) return '';
  const tipo = { sotto_minimo: 'Minimo non raggiunto', saldo_scaduto: 'Saldo non pagato', annullo_gruppo: 'Gruppo annullato' };
  return `<section class="adm-card adm-sezione"><h2 class="adm-h2">Rimborsi in sospeso</h2>
    <p class="adm-nota">Rimborsi che Stripe non ha completato. Il sistema riprova da solo; se restano bloccati controlla la dashboard di Stripe.</p>
    <div class="adm-tabella-scroll"><table class="adm-tabella adm-tabella-compatta">
      <thead><tr><th scope="col">Gruppo</th><th scope="col">Cliente</th><th scope="col">Motivo</th><th scope="col" class="adm-num">Importo</th><th scope="col" class="adm-num">Fermo da</th></tr></thead>
      <tbody>${r.map(x => `<tr><td><a class="adm-titolo-link" href="#/admin/campagne/${x.id_colletta}">${esc(x.prodotto)}</a></td><td>${esc(x.cliente)}</td><td>${esc(tipo[x.tipo] || x.tipo)}</td>
        <td class="adm-num">${esc(euro(x.importo))}</td><td class="adm-num">${plurale(x.ore, 'ora', 'ore')}</td></tr>`).join('')}</tbody></table></div></section>`;
}

export async function AdminDashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area adm-pagina"><div class="loading-spinner">Caricamento...</div></div>';
  try {
    const d = (await apiGet('/admin/dashboard')).dati;
    impostaDaFare(d.totale);
    content.innerHTML = `<div class="content-area adm-pagina">
      <div><h1 class="adm-h1">Dashboard admin</h1><p class="adm-frase" id="adm-frase" role="status">${esc(d.frase)}</p></div>
      ${d.voci.length ? `<section class="adm-card adm-dafare" aria-labelledby="adm-dafare-t"><h2 class="adm-h2" id="adm-dafare-t">Da fare</h2>
        <ul class="adm-dafare-lista">${d.voci.map(voceHtml).join('')}</ul></section>`
        : '<section class="adm-card adm-dafare adm-dafare-ok"><h2 class="adm-h2">Da fare</h2><p class="adm-vuoto">Niente da fare: è tutto in ordine.</p></section>'}
      ${indicatoriHtml(d.indicatori)}
      <section class="adm-card adm-sezione"><h2 class="adm-h2">Gruppi in scadenza</h2>${scadenzaTabella(d.in_scadenza)}</section>
      ${rimborsiTabella(d.rimborsi)}
    </div>`;
  } catch (err) {
    content.innerHTML = `<div class="content-area adm-pagina"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${esc(err.message)}</p></div></div>`;
  }
}
