import { getState } from '../state.js';
import { apiGet, apiPost } from '../api.js';
import { showToast } from '../components/toast.js';
import { percorsoOrdine, PERCORSO_PASSI } from '../stato-ordine.js';
import { showQrModal } from '../qr-modal.js';
import { apriShare } from '../components/share.js';
import { esc } from '../utils.js';
import { API_URL } from '../constants.js';
import { euro, pezzi, voti, scadenzaTesto, dataRelativa, oraHHMM } from '../format.js';
import { etichettaNotifica } from '../notifiche-tipo.js';
import { fraseRiepilogo, modelloDaFare } from '../dashboard-testi.js';

const ICONA = {
  pin: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
  qr: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>',
  freccia: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M13 6l6 6-6 6"/>',
  orologio: '<circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2"/>',
  pacco: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
  condividi: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>',
  spunta: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>',
  carta: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>'
};
const svg = (nome, dim = 20) =>
  `<svg width="${dim}" height="${dim}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${ICONA[nome]}</svg>`;

async function riprovaPagamento(prenotazioneId, bottone) {
  bottone.disabled = true;
  try {
    const res = await apiPost('/pagamento/riprova', { prenotazione_id: prenotazioneId });
    if (res.dati?.checkout_url) { window.location.href = res.dati.checkout_url; return; }
    bottone.disabled = false;
  } catch (err) {
    bottone.disabled = false;
    showToast({ title: 'Pagamento non aggiornato', description: esc(err.message), variant: 'error' });
  }
}

/** "DD/MM" da una data MySQL. */
function giornoMese(data) {
  const d = new Date(String(data).replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return '';
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

// ---------- 1) Saluto con sintesi ----------
function salutoHtml(user, dash) {
  // Senza dati (errore di caricamento) niente frase: meglio nulla di una frase sbagliata
  const frase = dash ? fraseRiepilogo(dash.ritiri_pronti, dash.n_gruppi_in_corso) : '';
  return `
    <header class="dsh-head">
      <div>
        <h1>Ciao ${esc(user?.nome || '')}</h1>
        ${frase ? `<p>${frase}</p>` : ''}
      </div>
    </header>`;
}

// ---------- 2) Striscia di 4 numeri ----------
function cellaHtml(classe, etichetta, valore, spiegazione, extra = '') {
  return `
    <div class="dsh-cell ${classe}">
      <div class="dsh-cell-label">${etichetta}${extra}</div>
      <div class="dsh-cell-value">${valore}</div>
      <div class="dsh-cell-sub">${spiegazione}</div>
    </div>`;
}

function stripHtml(dash) {
  if (!dash) {
    return `<section class="dsh-strip-errore" aria-label="I tuoi numeri"><p>Non riesco a caricare i tuoi numeri. Riprova tra poco.</p></section>`;
  }
  const st = dash.statistiche || {};
  const saldo = dash.saldo_in_arrivo || { importo: 0, n_gruppi: 0, prossima_scadenza: null };
  const pct = st.risparmio_percentuale;
  const infoId = 'dsh-info-saldo';
  const infoSaldo = `
    <span class="dsh-info-wrap">
      <button type="button" class="dsh-info" aria-describedby="${infoId}" aria-label="Informazioni sul saldo in arrivo">i</button>
      <span class="dsh-tip" id="${infoId}" role="tooltip">Stima: il prezzo finale può essere più basso se il gruppo cresce.</span>
    </span>`;
  const subSaldo = saldo.importo > 0 && saldo.n_gruppi > 0
    ? `addebito automatico il ${giornoMese(saldo.prossima_scadenza)}, solo se i gruppi raggiungono il minimo`
    : 'Nessun addebito in programma';
  return `
    <section class="dsh-strip" aria-label="I tuoi numeri">
      ${cellaHtml('dsh-cell-risparmio', 'Risparmiato', euro(st.risparmio_stimato),
        pct !== null && pct !== undefined ? `${pct}% rispetto al listino, sui pagamenti già effettuati` : 'rispetto al listino')}
      ${cellaHtml('', 'Speso finora', euro(st.spesa_totale), 'pagamenti già effettuati')}
      ${cellaHtml('', 'Saldo in arrivo', euro(saldo.importo), subSaldo, infoSaldo)}
      ${cellaHtml('', 'Gruppi in corso', String(dash.n_gruppi_in_corso ?? 0), 'a cui stai partecipando adesso')}
    </section>`;
}

// ---------- 3) Da fare ----------
function rigaHtml(titolo, sottotitolo, azione) {
  return `
    <li class="dsh-todo-row">
      <div class="dsh-todo-text">
        <div class="dsh-todo-title">${titolo}</div>
        <div class="dsh-todo-sub">${sottotitolo}</div>
      </div>
      ${azione}
    </li>`;
}

function daFareHtml(dash) {
  if (!dash) {
    return '<section class="dsh-strip-errore"><p>Non riesco a caricare i tuoi ritiri e pagamenti. Riprova tra poco.</p></section>';
  }
  const m = modelloDaFare(dash.da_fare);
  if (m.vuoto) {
    return `
      <section class="dsh-ok" aria-label="Da fare">
        <span class="dsh-ok-icon">${svg('spunta', 20)}</span>
        <span>Tutto in ordine: non hai nulla da fare.</span>
      </section>`;
  }
  const righe = m.righe.map(r => r.tipo === 'saldo'
    ? rigaHtml(`Pagamento del saldo non riuscito &middot; ${esc(r.prodotto)}`,
        r.scadenza ? `Aggiorna il metodo di pagamento entro il ${esc(giornoMese(r.scadenza))} alle ${esc(oraHHMM(r.scadenza))}` : pezzi(r.quantita),
        `<button type="button" class="dsh-btn-sec dsh-todo-btn" data-azione="riprova" data-id="${parseInt(r.id)}">${svg('carta', 18)} Aggiorna pagamento</button>`)
    : rigaHtml(esc(r.prodotto), `${pezzi(r.quantita)} &middot; pronto per il ritiro`,
        `<button type="button" class="dsh-btn-sec dsh-todo-btn" data-azione="qr" data-id="${parseInt(r.id)}">${svg('qr', 18)} Mostra QR</button>`)
  ).join('');
  const sediHtml = m.sedi.map(t => `
    <div class="dsh-sede"><span class="dsh-sede-icon">${svg('pin', 18)}</span><span>Sede di ritiro: ${esc(t)}</span></div>`).join('');
  return `
    <section class="dsh-todo" aria-labelledby="dsh-todo-titolo">
      <div class="dsh-todo-head">
        <h2 id="dsh-todo-titolo">Da fare</h2>
        <span class="dsh-badge-amb" aria-label="${m.righe.length} ${m.righe.length === 1 ? 'elemento' : 'elementi'}">${m.righe.length}</span>
      </div>
      <ul class="dsh-todo-list">${righe}</ul>
      ${sediHtml}
    </section>`;
}


// ---------- 4) I miei gruppi in corso ----------
function urlImmagine(percorso) {
  if (!percorso) return '';
  if (/^https?:\/\//i.test(percorso)) return percorso;
  return `${API_URL}/${String(percorso).replace(/^\//, '')}`;
}

/** Messaggio della barra: un solo obiettivo alla volta (X/Y sono i pezzi TOTALI del gruppo). */
function messaggioObiettivo(o) {
  if (o.tipo === 'massimo') return 'Prezzo più basso raggiunto';
  const mancano = o.mancano === 1 ? 'manca 1 unità' : `mancano ${o.mancano} unità`;
  return o.tipo === 'attivazione'
    ? `${mancano} per attivare il gruppo`
    : `${mancano} per far scendere il prezzo a ${euro(o.prezzo)}`;
}

function gruppoCardHtml(g) {
  const o = g.obiettivo;
  const att = parseInt(g.quantita_attuale) || 0;
  const pct = Math.max(0, Math.min(100, Math.round((o.pct || 0) * 100)));
  const foto = g.immagine
    ? `<img src="${esc(urlImmagine(g.immagine))}" alt="" loading="lazy">`
    : svg('pacco', 32);
  const conteggio = o.tipo === 'massimo' ? `${att} unità` : `${att} / ${o.soglia} unità`;
  return `
    <article class="dsh-gc" data-id="${g.id_colletta}">
      <div class="dsh-gc-foto">${foto}</div>
      <div class="dsh-gc-corpo">
        <h3 class="dsh-gc-titolo"><a class="dsh-gc-link" href="#/campagne/${g.id_colletta}">${esc(g.prodotto)}</a></h3>
        <p class="dsh-gc-miei">Hai nel gruppo ${pezzi(g.miei_pezzi)}</p>
        <div class="dsh-gc-riga"><strong>${conteggio}</strong><span>${esc(messaggioObiettivo(o))}</span></div>
        <div class="dsh-bar" role="progressbar" aria-label="Avanzamento verso il prossimo traguardo"
             aria-valuemin="0" aria-valuemax="${o.soglia}" aria-valuenow="${Math.min(att, o.soglia)}">
          <div class="dsh-bar-fill" style="width:${pct}%"></div>
        </div>
        <div class="dsh-gc-piede">
          <span class="dsh-gc-scade">${svg('orologio', 16)} ${esc(scadenzaTesto(g.data_limite))}</span>
          <button type="button" class="dsh-btn-inv" data-azione="invita" data-id="${g.id_colletta}" aria-haspopup="dialog">${svg('condividi', 18)} Invita amici</button>
        </div>
      </div>
    </article>`;
}

/**
 * `nonPartecipaMai`: vero solo se sappiamo con certezza che l'utente non ha nessuna partecipazione
 * (anche chiusa). Con partecipazioni ma nessun gruppo aperto la sezione semplicemente non compare.
 */
function gruppiInCorsoHtml(dash, nonPartecipaMai) {
  if (!dash) {
    return `<section class="dsh-strip-errore"><p>Non riesco a caricare i tuoi gruppi in corso. Riprova tra poco.</p></section>`;
  }
  const lista = dash.gruppi_in_corso || [];
  if (!lista.length) {
    if (!nonPartecipaMai) return '';
    return `
      <section class="dsh-vuoto" aria-labelledby="dsh-gruppi-titolo">
        <h2 id="dsh-gruppi-titolo">Non partecipi a nessun gruppo</h2>
        <p>Unisciti a un gruppo d'acquisto: più persone partecipano, meno si paga.</p>
        <a href="#/" class="dsh-btn-sec">Esplora i gruppi ${svg('freccia', 18)}</a>
      </section>`;
  }
  return `
    <section aria-labelledby="dsh-gruppi-titolo">
      <div class="dsh-sez-head">
        <h2 id="dsh-gruppi-titolo">I miei acquisti in corso</h2>
        <a href="#/acquisti?tab=in-corso" class="dsh-link-sez">I miei acquisti ${svg('freccia', 16)}</a>
      </div>
      <div class="dsh-gc-griglia">${lista.map(gruppoCardHtml).join('')}</div>
    </section>`;
}

function condividiGruppo(g, ancora) {
  const url = `${location.origin}${location.pathname}#/campagne/${g.id_colletta}`;
  apriShare(ancora, {
    url,
    testo: `Unisciti al gruppo d'acquisto "${g.prodotto}" su BuyPool: più siamo, meno si paga!`,
    etichetta: 'Invita amici al gruppo'
  });
}



// ---------- 6) Gruppi consigliati per te ----------
/** Versione breve per le card dei consigliati. */
function messaggioBreve(o) {
  if (o.tipo === 'massimo') return 'Prezzo più basso raggiunto';
  return o.mancano === 1 ? 'manca 1 unità' : `mancano ${o.mancano} unità`;
}

function consigliatoCardHtml(g) {
  const o = g.obiettivo;
  const att = parseInt(g.quantita_attuale) || 0;
  const pct = Math.max(0, Math.min(100, Math.round((o.pct || 0) * 100)));
  const foto = g.immagine
    ? `<img src="${esc(urlImmagine(g.immagine))}" alt="" loading="lazy">`
    : svg('pacco', 40);
  const conteggio = o.tipo === 'massimo' ? `${att} unità` : `${att} / ${o.soglia} unità`;
  return `
    <article class="dsh-cons">
      <div class="dsh-cons-foto">
        ${foto}
        <span class="dsh-cons-motivo">${esc(g.motivo)}</span>
        ${g.sconto_max > 0 ? `<span class="dsh-cons-sconto">fino a -${parseInt(g.sconto_max)}%</span>` : ''}
      </div>
      <div class="dsh-cons-corpo">
        <h3 class="dsh-cons-titolo">${esc(g.prodotto)}</h3>
        <div class="dsh-gc-riga"><strong>${conteggio}</strong><span>${esc(messaggioBreve(o))}</span></div>
        <div class="dsh-bar" role="progressbar" aria-label="Avanzamento verso il prossimo traguardo"
             aria-valuemin="0" aria-valuemax="${o.soglia}" aria-valuenow="${Math.min(att, o.soglia)}">
          <div class="dsh-bar-fill" style="width:${pct}%"></div>
        </div>
        <div class="dsh-cons-piede">
          <span class="dsh-gc-scade">${svg('orologio', 16)} ${esc(scadenzaTesto(g.data_limite))}</span>
          <a class="dsh-btn-pri" href="#/campagne/${parseInt(g.id_colletta)}">Unisciti al gruppo</a>
        </div>
      </div>
    </article>`;
}

/** Nascosta se non ci sono consigli (o se i dati non sono arrivati: l'errore e' gia' mostrato altrove). */
function consigliatiHtml(dash) {
  const lista = dash?.consigliati || [];
  if (!lista.length) return '';
  return `
    <section aria-labelledby="dsh-cons-titolo">
      <div class="dsh-sez-head">
        <div>
          <h2 id="dsh-cons-titolo">Gruppi consigliati per te</h2>
          <p class="dsh-sez-sub">Scelti in base ai tuoi acquisti e a quanto sono vicini al traguardo.</p>
        </div>
        <a href="#/" class="dsh-link-sez">Vedi tutti i gruppi ${svg('freccia', 16)}</a>
      </div>
      <div class="dsh-cons-griglia">${lista.slice(0, 3).map(consigliatoCardHtml).join('')}</div>
    </section>`;
}

// ---------- 5) Ordini recenti e Ultime notifiche ----------
function puntiniHtml(perc) {
  const colore = perc.concluso ? 'dsh-dot-verde' : 'dsh-dot-blu';
  let h = '';
  for (let i = 1; i <= PERCORSO_PASSI; i++) h += `<span class="dsh-dot ${i <= perc.passo ? colore : ''}"></span>`;
  return `<span class="dsh-dots" aria-hidden="true">${h}</span>`;
}

function ordiniHtml(mie) {
  let corpo;
  if (!mie) {
    corpo = '<p class="dsh-card-msg">Non riesco a caricare gli ordini. Riprova tra poco.</p>';
  } else if (!mie.length) {
    corpo = '<p class="dsh-card-msg">Non hai ancora ordini. <a href="#/">Esplora i gruppi</a></p>';
  } else {
    corpo = `<ul class="dsh-ord-list">${mie.slice(0, 3).map(p => {
      const perc = percorsoOrdine(p);
      return `
        <li class="dsh-ord-row">
          <div class="dsh-ord-text">
            <div class="dsh-ord-nome">${esc(p.prodotto)}</div>
            <div class="dsh-ord-sub">${pezzi(parseInt(p.quantita) || 0)}</div>
          </div>
          <div class="dsh-ord-stato">
            <span class="dsh-pill dsh-pill-${perc.tono}">${esc(perc.etichetta)}</span>
            ${puntiniHtml(perc)}
          </div>
        </li>`;
    }).join('')}</ul>
    <p class="dsh-nota">I puntini seguono i 6 passi: gruppo, acconto, prezzo, saldo, invio, ritiro.</p>`;
  }
  return `
    <section class="dsh-card" aria-labelledby="dsh-ord-titolo">
      <div class="dsh-card-head"><h2 id="dsh-ord-titolo">Ordini recenti</h2><a href="#/acquisti" class="dsh-link-sez">I miei acquisti ${svg('freccia', 16)}</a></div>
      ${corpo}
    </section>`;
}

function notificheHtml(notifiche) {
  let corpo;
  if (!notifiche) {
    corpo = '<p class="dsh-card-msg">Non riesco a caricare le notifiche. Riprova tra poco.</p>';
  } else if (!notifiche.length) {
    corpo = '<p class="dsh-card-msg">Nessuna notifica per ora.</p>';
  } else {
    corpo = `<ul class="dsh-not-list">${notifiche.slice(0, 4).map(n => { const et = etichettaNotifica(n); return `
      <li class="dsh-not-row">
        <div class="dsh-not-text">
          <span class="dsh-tipo ${et.classe}">${et.testo}</span>
          <div class="dsh-not-titolo">${esc(n.titolo)}${n.letta ? '' : ' <span class="dsh-nuova" title="Non letta"><span class="dsh-sr">non letta</span></span>'}</div>
          <div class="dsh-not-msg">${esc(n.messaggio || '')}</div>
        </div>
        <time class="dsh-not-data">${esc(dataRelativa(n.data_creazione))}</time>
      </li>`; }).join('')}</ul>`;
  }
  return `
    <section class="dsh-card" aria-labelledby="dsh-not-titolo">
      <div class="dsh-card-head"><h2 id="dsh-not-titolo">Ultime notifiche</h2><a href="#/notifiche" class="dsh-link-sez">Tutte ${svg('freccia', 16)}</a></div>
      <p class="dsh-not-sotto">Pagamenti, rimborsi e novità dai tuoi gruppi. Le stesse informazioni arrivano anche per email.</p>
      ${corpo}
    </section>`;
}

// ---------- 7) Le mie proposte ----------
/** Stati coerenti con la pagina Proposte (GET /proposte/bacheca restituisce gia' lo stato effettivo). */
const PROPOSTA_STATO = {
  in_attesa:      { etichetta: 'In attesa', tono: 'grigio' },
  in_votazione:   { etichetta: 'In votazione', tono: 'ambra' },
  in_valutazione: { etichetta: 'In valutazione', tono: 'ambra' },
  pubblicata:     { etichetta: 'Gruppo aperto', tono: 'verde' },
  scaduta:        { etichetta: 'Scaduta', tono: 'grigio' },
  rifiutata:      { etichetta: 'Non accolta', tono: 'grigio' }
};

function proposteHtml(bacheca) {
  const head = `
    <div class="dsh-card-head">
      <h2 id="dsh-prop-titolo">Le mie proposte</h2>
      <a href="#/proposte" class="dsh-link-sez">+ Nuova proposta</a>
    </div>`;
  let corpo;
  if (!bacheca) {
    corpo = '<p class="dsh-card-msg">Non riesco a caricare le tue proposte. Riprova tra poco.</p>';
  } else if (!(bacheca.proposte || []).length) {
    corpo = `<p class="dsh-card-msg">Hai un'idea? Proponi un prodotto da comprare in gruppo. <a href="#/proposte">Nuova proposta</a></p>`;
  } else {
    const soglia = bacheca.soglia;
    corpo = `<ul class="dsh-prop-griglia">${bacheca.proposte.slice(0, 3).map(p => {
      const st = PROPOSTA_STATO[p.stato] || { etichetta: p.stato, tono: 'grigio' };
      return `
        <li class="dsh-prop">
          <div class="dsh-prop-testo">
            <a class="dsh-prop-nome" href="#/proposte?p=${parseInt(p.id_proposta)}">${esc(p.nome_prodotto)}</a>
            <div class="dsh-ord-sub">${voti(p.tot_voti)} su ${soglia}</div>
          </div>
          <span class="dsh-pill dsh-pill-${st.tono}">${esc(st.etichetta)}</span>
        </li>`;
    }).join('')}</ul>`;
  }
  return `<section class="dsh-card" aria-labelledby="dsh-prop-titolo">${head}${corpo}</section>`;
}

export async function DashboardPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;
  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  const { user } = getState();
  // Ogni sezione dipende dalla sua chiamata: se una fallisce le altre funzionano
  const [dashR, mieR, notR, propR] = await Promise.allSettled([
    apiGet('/mie/dashboard'),
    apiGet('/mie/partecipazioni'),
    apiGet('/notifiche'),
    apiGet('/proposte/bacheca?tab=mie&ordina=recenti')
  ]);
  const dash = dashR.status === 'fulfilled' ? (dashR.value.dati || {}) : null;
  const mie = mieR.status === 'fulfilled' ? (mieR.value.dati || []) : null;
  const notifiche = notR.status === 'fulfilled' ? (notR.value.dati || []) : null;
  const bacheca = propR.status === 'fulfilled' ? (propR.value.dati || {}) : null;

  const nGruppi = dash ? (dash.n_gruppi_in_corso || 0) : 0;
  const senzaGruppi = !!dash && nGruppi === 0;
  // Partecipazione "viva": non annullata/rimborsata e con gruppo non fallito/annullato (anche gia' chiusa o ritirata)
  const haPartecipazioni = !!mie && mie.some(p =>
    !['annullata', 'rimborsata'].includes(p.stato) && !['fallita', 'annullata'].includes(p.stato_colletta));
  // Se non abbiamo potuto leggere le partecipazioni (errore) non possiamo dire che non partecipa
  const nonPartecipaMai = senzaGruppi && !!mie && !haPartecipazioni;

  content.innerHTML = `
    <div class="content-area dsh-page" id="dsh-root">
      ${salutoHtml(user, dash)}
      ${stripHtml(dash)}
      ${daFareHtml(dash)}
      ${senzaGruppi ? consigliatiHtml(dash) : ''}
      ${gruppiInCorsoHtml(dash, nonPartecipaMai)}
      <div class="dsh-duo">
        ${ordiniHtml(mie)}
        ${notificheHtml(notifiche)}
      </div>
      ${senzaGruppi ? '' : consigliatiHtml(dash)}
      ${proposteHtml(bacheca)}
    </div>`;

  document.getElementById('dsh-root').addEventListener('click', (e) => {
    const b = e.target.closest('[data-azione]');
    if (!b) return;
    const id = parseInt(b.dataset.id);
    if (b.dataset.azione === 'qr') showQrModal(id);
    if (b.dataset.azione === 'riprova') riprovaPagamento(id, b);
    if (b.dataset.azione === 'invita') {
      const g = (dash?.gruppi_in_corso || []).find(x => x.id_colletta === id);
      if (g) condividiGruppo(g, b);
    }
  });
}
