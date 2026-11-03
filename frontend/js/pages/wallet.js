import { getState } from '../state.js';
import { apiGet } from '../api.js';
import { euro } from '../format.js';
import { Card } from '../components/card.js';

let saldo = 0;
let movimenti = [];
let loading = true;
let error = null;

export async function WalletPage() {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const { user } = getState();
  if (!user) {
    content.innerHTML = '<div class="content-area"><p class="text-secondary">Devi effettuare l\'accesso.</p></div>';
    return;
  }

  loading = true;
  error = null;
  render(content, user);

  try {
    const [wallet, movements] = await Promise.all([
      apiGet('/wallet'),
      apiGet('/wallet/movimenti')
    ]);
    saldo = wallet.dati?.saldo || 0;
    movimenti = movements.dati || [];
  } catch (e) {
    error = e.message || 'Errore nel caricamento';
    movimenti = [];
  } finally {
    loading = false;
    render(content, user);
  }
}

function render(content, user) {
  if (loading) {
    content.innerHTML = '<div class="content-area"><p class="text-secondary">Caricamento...</p></div>';
    return;
  }

  if (error) {
    content.innerHTML = `<div class="content-area"><p style="color:var(--color-error)">${error}</p></div>`;
    return;
  }

  const movimentiHtml = movimenti.length > 0 ? movimenti.map(m => `
    <div class="wallet-movement">
      <div class="wallet-movement-info">
        <div class="wallet-movement-desc">${m.descrizione}</div>
        <div class="wallet-movement-date">${new Date(m.data_creazione).toLocaleDateString('it-IT')}</div>
      </div>
      <div class="wallet-movement-amount ${m.tipo === 'ricarica' ? 'wallet-movement-positive' : 'wallet-movement-negative'}">
        ${m.tipo === 'ricarica' ? '+' : ''}${euro(Math.abs(m.importo))}
      </div>
    </div>
  `).join('') : `
    <div class="empty-state" style="padding: var(--space-6);">
      <p class="text-sm text-secondary">Nessun movimento registrato.</p>
    </div>
  `;

  content.innerHTML = `
    <div class="content-area">
      <div class="page-header">
        <h1>Wallet</h1>
      </div>
      <div class="wallet-card">
        <div class="wallet-balance-label">Saldo disponibile</div>
        <div class="wallet-balance">${euro(saldo)}</div>
      </div>

      ${Card({ children: `
        <div class="card-content">
          <h3 style="margin-bottom: var(--space-4); font-size: var(--text-lg); font-weight: var(--font-semibold);">Movimenti</h3>
          <div class="wallet-movements">
            ${movimentiHtml}
          </div>
        </div>
      ` })}
    </div>
  `;
}
