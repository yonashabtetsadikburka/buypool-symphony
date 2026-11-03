import { apiGet } from '../api.js';

export function PagamentoRisultatoPage({ successo = true } = {}) {
  const content = document.getElementById('content-area') || document.querySelector('.main-content');
  if (!content) return;

  const params = new URLSearchParams(window.location.hash.split('?')[1] || '');
  const sessionId = params.get('session_id');

  const color = successo ? 'var(--color-success)' : 'var(--color-warning)';
  const icon = successo
    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>';
  const titolo = successo ? 'Pagamento completato!' : 'Pagamento annullato';
  const messaggio = successo
    ? 'Il tuo pagamento e\' stato ricevuto con successo. Verrai reindirizzato ai tuoi acquisti...'
    : 'Il pagamento non e\' stato completato. Puoi riprovare in qualsiasi momento dalla pagina dell\'ordine.';

  content.innerHTML = `
    <div class="content-area" style="max-width: 500px; margin: 0 auto; text-align: center; padding: var(--space-8) 0;">
      <div style="margin-bottom: var(--space-6);">
        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2" style="margin: 0 auto;">
          ${icon}
        </svg>
      </div>
      <h1 style="font-size: var(--text-2xl); font-weight: var(--font-bold); margin-bottom: var(--space-3); color: ${color};">
        ${titolo}
      </h1>
      <p class="text-secondary" style="margin-bottom: var(--space-6); line-height: 1.6;">
        ${messaggio}
      </p>
      <div style="display: flex; gap: var(--space-3); justify-content: center;">
        <a href="#/acquisti" class="btn btn-default">I miei acquisti</a>
        <a href="#/" class="btn btn-outline">Torna ai gruppi</a>
      </div>
    </div>
  `;

  if (successo && sessionId) {
    apiGet(`/pagamento/stato?session_id=${sessionId}`).catch(() => {}).finally(() => {
      setTimeout(() => { window.location.hash = '#/acquisti'; }, 2000);
    });
  } else if (successo) {
    setTimeout(() => { window.location.hash = '#/acquisti'; }, 3000);
  }
}

export function PagamentoSuccessoPage() {
  return PagamentoRisultatoPage({ successo: true });
}

export function PagamentoAnnullatoPage() {
  return PagamentoRisultatoPage({ successo: false });
}
