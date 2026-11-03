import { apiGet, apiPost, apiDelete } from '../api.js';
import { isFornitore } from '../auth.js';
import { Card } from '../components/card.js';
import { euro } from '../format.js';

let recVoto = 0;

window.recSetVoto = function(v) {
  recVoto = v;
  document.querySelectorAll('.rec-star').forEach((s, i) =>
    s.classList.toggle('active', i < v));
};

function recStars(voto, size) {
  size = size || 'var(--text-base)';
  let h = '';
  for (let i = 1; i <= 5; i++) {
    h += `<span style="font-size:${size};color:${i <= Math.round(voto) ? 'var(--color-warning)' : 'var(--color-border)'};">&#9733;</span>`;
  }
  return `<span style="white-space:nowrap;">${h}</span>`;
}

export async function FornitoriDettaglioPage(params) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  content.innerHTML = '<div class="content-area"><div class="loading-spinner">Caricamento...</div></div>';

  try {
    const [fornRes, prodRes, recRes, campRes] = await Promise.all([
      apiGet('/fornitori'),
      apiGet(`/prodotti?fornitore_id=${params.id}`),
      apiGet(`/fornitori/${params.id}/recensioni`).catch(() => ({ dati: null })),
      apiGet('/campagne').catch(() => ({ dati: [] }))
    ]);
    const recDati = recRes.dati || { media: null, totale: 0, mia: null, puo_recensire: false, recensioni: [] };
    recVoto = (recDati.mia && recDati.mia.voto) || 0;
    const fornitori = fornRes.dati || [];
    const fornitore = fornitori.find(f => f.id === parseInt(params.id));
    const prodotti = ((prodRes.dati && prodRes.dati.prodotti) || prodRes.dati || []).filter(p => p.id_fornitore === parseInt(params.id));
    const ultimaCampagnaPerProdotto = {};
    ((campRes && campRes.dati) || []).forEach(c => {
      const pid = parseInt(c.id_prodotto);
      if (!pid) return;
      if (!ultimaCampagnaPerProdotto[pid] || parseInt(c.id) > ultimaCampagnaPerProdotto[pid]) {
        ultimaCampagnaPerProdotto[pid] = parseInt(c.id);
      }
    });

    if (!fornitore) {
      const backHref = isFornitore() ? '#/fornitore/gruppi' : '#/';
      content.innerHTML = `<div class="empty-state"><h2>Fornitore non trovato</h2><a href="${backHref}" class="btn btn-default">Torna ai gruppi</a></div>`;
      return;
    }

    const productsHtml = prodotti.map(p => {
      const campId = ultimaCampagnaPerProdotto[parseInt(p.id)];
      const inner = `
        <div>
          <div class="font-medium"${campId ? ' style="color:var(--color-primary);"' : ''}>${p.nome}</div>
          <div class="text-sm text-secondary">Minimo: ${p.quantita_minima} pezzi</div>
        </div>
        <div class="font-bold">${euro(parseFloat(p.prezzo_unitario))}</div>`;
      return campId
        ? `<a href="#/campagne/${campId}" style="display:flex;justify-content:space-between;padding:var(--space-3) 0;border-bottom:1px solid var(--color-border);text-decoration:none;color:inherit;" title="Vedi gruppo">${inner}</a>`
        : `<div style="display: flex; justify-content: space-between; padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border);">${inner}</div>`;
    }).join('') || '<p class="text-secondary">Nessun prodotto disponibile</p>';

    const recensioniHtml = (recDati.recensioni || []).map(r => `
      <div style="padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-1);">
          <span class="font-medium text-sm">${r.autore || 'Utente'}</span>
          ${recStars(r.voto, 'var(--text-sm)')}
        </div>
        ${r.testo ? `<p class="text-sm" style="margin-bottom:var(--space-1);">${r.testo}</p>` : ''}
        <div class="text-xs text-secondary">${new Date(r.data_creazione).toLocaleDateString('it-IT')}</div>
      </div>
    `).join('') || '<p class="text-secondary">Nessuna recensione ancora.</p>';

    const recFormHtml = recDati.puo_recensire ? `
      <div style="margin-top:var(--space-4);border-top:1px solid var(--color-border);padding-top:var(--space-3);">
        <h4 style="font-size:var(--text-sm);font-weight:var(--font-semibold);margin-bottom:var(--space-2);">${recDati.mia ? 'La tua recensione' : 'Lascia una recensione'}</h4>
        <div style="margin-bottom:var(--space-2);font-size:var(--text-2xl);cursor:pointer;" id="rec-stars">
          ${[1, 2, 3, 4, 5].map(i => `<span class="rec-star${i <= recVoto ? ' active' : ''}" onclick="recSetVoto(${i})" style="color:var(--color-border);">&#9733;</span>`).join('')}
        </div>
        <textarea id="rec-testo" class="input" rows="3" maxlength="1000" placeholder="Racconta la tua esperienza (max 1000 caratteri)" style="height:auto;margin-bottom:var(--space-2);">${recDati.mia ? (recDati.mia.testo || '') : ''}</textarea>
        <div id="rec-error" style="color:var(--color-error);font-size:var(--text-sm);display:none;margin-bottom:var(--space-2);"></div>
        <div style="display:flex;gap:var(--space-2);">
          <button class="btn btn-default btn-sm" onclick="recInvia(${fornitore.id})">${recDati.mia ? 'Aggiorna' : 'Invia'}</button>
          ${recDati.mia ? `<button class="btn btn-ghost btn-sm" onclick="recElimina(${recDati.mia.id}, ${fornitore.id})">Elimina</button>` : ''}
        </div>
      </div>` : `
      <p class="text-xs text-secondary" style="margin-top:var(--space-3);">Puoi recensire dopo aver completato un acquisto presso questo fornitore.</p>`;

    content.innerHTML = `
      <div class="content-area">
        <div style="margin-bottom: var(--space-4);">
          <a href="${isFornitore() ? '#/fornitore/gruppi' : '#/'}" class="btn btn-ghost btn-sm">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Torna ai gruppi
          </a>
        </div>
        <div class="two-columns">
          <div>
            ${Card({ children: `
              <div class="card-content">
                <div style="display: flex; align-items: center; gap: var(--space-4); margin-bottom: var(--space-4);">
                  <div class="supplier-logo" style="width: 80px; height: 80px; font-size: var(--text-3xl);">${fornitore.nome[0]}</div>
                  <div>
                    <h2 style="font-size: var(--text-xl); font-weight: var(--font-bold);">${fornitore.nome}</h2>
                    <div class="text-sm text-secondary">${fornitore.indirizzo || ''}</div>
                  </div>
                </div>
                <p class="text-secondary" style="margin-bottom: var(--space-4);">${fornitore.descrizione || ''}</p>
                ${fornitore.sito_web ? `<a href="${fornitore.sito_web}" target="_blank" rel="noopener" class="btn btn-default w-full" style="margin-bottom: var(--space-4);">Vai al sito / catalogo
                  <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>` : ''}
                <h3 style="margin-bottom: var(--space-3);">Prodotti</h3>
                ${productsHtml}
              </div>
            ` })}
          </div>
          <div style="display:flex;flex-direction:column;gap:var(--space-6);">
            ${Card({ children: `
              <div class="card-content">
                <h3 style="margin-bottom: var(--space-3);">Contatti</h3>
                <div style="display: flex; flex-direction: column; gap: var(--space-3);">
                  <div class="text-sm">
                    <span class="text-secondary">Email:</span>
                    <span class="font-medium">${fornitore.email_contatto || 'N/A'}</span>
                  </div>
                  <div class="text-sm">
                    <span class="text-secondary">Telefono:</span>
                    <span class="font-medium">${fornitore.telefono || 'N/A'}</span>
                  </div>
                  <div class="text-sm">
                    <span class="text-secondary">Indirizzo:</span>
                    <span class="font-medium">${fornitore.indirizzo || 'N/A'}</span>
                  </div>
                </div>
              </div>
            ` })}
            ${Card({ children: `
              <div class="card-content">
                <h3 style="margin-bottom: var(--space-2);">Recensioni</h3>
                ${recDati.totale > 0
                  ? `<div style="display:flex;align-items:center;gap:var(--space-2);margin-bottom:var(--space-3);">${recStars(recDati.media, 'var(--text-xl)')} <span class="font-bold">${recDati.media}</span> <span class="text-sm text-secondary">(${recDati.totale} ${recDati.totale === 1 ? 'recensione' : 'recensioni'})</span></div>`
                  : ''}
                ${recensioniHtml}
                ${recFormHtml}
              </div>
            ` })}
          </div>
        </div>
      </div>
    `;
  } catch (err) {
    content.innerHTML = `<div class="content-area"><div class="empty-state"><h2>Errore</h2><p class="text-secondary">${err.message}</p></div></div>`;
  }
}

window.recInvia = async function(fornitoreId) {
  const errorEl = document.getElementById('rec-error');
  errorEl.style.display = 'none';
  if (!recVoto || recVoto < 1 || recVoto > 5) {
    errorEl.textContent = 'Seleziona un voto da 1 a 5 stelle.';
    errorEl.style.display = 'block';
    return;
  }
  try {
    const testo = document.getElementById('rec-testo')?.value.trim() || '';
    await apiPost(`/fornitori/${fornitoreId}/recensioni`, { voto: recVoto, testo });
    FornitoriDettaglioPage({ id: fornitoreId });
  } catch (err) {
    errorEl.textContent = err.message;
    errorEl.style.display = 'block';
  }
};

window.recElimina = async function(recId, fornitoreId) {
  if (!confirm('Eliminare la tua recensione?')) return;
  try {
    await apiDelete(`/fornitori/recensioni/${recId}`);
    FornitoriDettaglioPage({ id: fornitoreId });
  } catch (err) {
    alert(err.message);
  }
};
