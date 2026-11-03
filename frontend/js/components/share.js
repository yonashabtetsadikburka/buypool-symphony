import { showToast } from './toast.js';

let chiudiCorrente = null;
let ancoraCorrente = null;

/** Chiude il popover di condivisione aperto, se c'e'. */
export function chiudiShare() {
  if (chiudiCorrente) chiudiCorrente();
}

/** Griglia pulsanti di condivisione (WhatsApp, Telegram, Copia link, Altro). */
export function shareGridHtml(url, testo) {
  const eu = encodeURIComponent(url);
  const et = encodeURIComponent(testo);
  return `
    <div class="bp-share-grid">
      <a class="bp-share-btn" href="https://wa.me/?text=${et}%20${eu}" target="_blank" rel="noopener noreferrer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#25D366" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
        WhatsApp
      </a>
      <a class="bp-share-btn" href="https://t.me/share/url?url=${eu}&text=${et}" target="_blank" rel="noopener noreferrer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#26A5E4" aria-hidden="true"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
        Telegram
      </a>
      <button type="button" class="bp-share-btn" data-share="copia">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
        Copia link
      </button>
      ${typeof navigator.share === 'function' ? `
      <button type="button" class="bp-share-btn" data-share="altro">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
        Altro
      </button>` : ''}
    </div>`;
}

/** Collega Copia link / Altro dentro `contenitore`; `dopo` viene chiamata a azione conclusa. */
export function attivaShare(contenitore, url, testo, dopo = () => {}) {
  contenitore.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-share]');
    if (!b) return;
    if (b.dataset.share === 'copia') {
      try {
        await navigator.clipboard.writeText(url);
        showToast({ title: 'Link copiato!', variant: 'success', duration: 2500 });
      } catch (err) {
        window.prompt('Copia il link:', url);
      }
    } else {
      try { await navigator.share({ title: 'BuyPool', text: testo, url }); } catch (err) { /* annullato */ }
    }
    dopo();
  });
}

/**
 * Popover di condivisione (WhatsApp, Telegram, Copia link, Altro) accanto a `ancora`.
 * Stesse classi e icone .bp-share-* del dettaglio gruppo.
 */
export function apriShare(ancora, { url, testo, etichetta = 'Condividi la proposta' }) {
  // Secondo clic sullo stesso pulsante: chiude il popover (toggle)
  const eraAperto = chiudiCorrente !== null && ancoraCorrente === ancora;
  chiudiShare();
  if (eraAperto) return;
  const pop = document.createElement('div');
  pop.className = 'pp-share-pop';
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', etichetta);
  pop.innerHTML = shareGridHtml(url, testo);
  document.body.appendChild(pop);

  const r = ancora.getBoundingClientRect();
  const larg = Math.min(300, window.innerWidth - 24);
  pop.style.width = `${larg}px`;
  pop.style.left = `${Math.max(12, Math.min(window.innerWidth - larg - 12, r.right - larg))}px`;
  const sotto = r.bottom + 8;
  pop.style.top = `${sotto + pop.offsetHeight > window.innerHeight ? Math.max(12, r.top - pop.offsetHeight - 8) : sotto}px`;

  const fuori = (e) => { if (!pop.contains(e.target) && !ancora.contains(e.target)) chiudi(); };
  const tasto = (e) => { if (e.key === 'Escape') { chiudi(); ancora.focus(); } };
  function chiudi() {
    pop.remove();
    document.removeEventListener('click', fuori, true);
    document.removeEventListener('keydown', tasto);
    ancora.setAttribute('aria-expanded', 'false');
    chiudiCorrente = null;
    ancoraCorrente = null;
  }
  chiudiCorrente = chiudi;
  ancoraCorrente = ancora;
  ancora.setAttribute('aria-expanded', 'true');
  document.addEventListener('click', fuori, true);
  document.addEventListener('keydown', tasto);

  attivaShare(pop, url, testo, chiudi);
  pop.querySelector('a, button')?.focus();
}
