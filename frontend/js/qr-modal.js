import { apiGet } from './api.js';

/**
 * Modale QR condivisa: QR code REALE generato dal token (libreria qrcode-generator,
 * caricata in app.html). Il token non viene mai mostrato.
 */
export async function showQrModal(prenotazioneId) {
  try {
    const res = await apiGet(`/mie/assegnazioni/${prenotazioneId}/qr`);
    const qr = res.dati;
    if (!qr || !qr.token) return;

    const modalHtml = `
      <div id="qr-modal" class="modal-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;">
        <div class="modal-content card" style="max-width:400px;width:90%;">
          <div class="card-content" style="text-align:center;">
            <h3 style="margin-bottom:var(--space-4);">Il tuo QR Code</h3>
            <div id="qr-code-box" style="background:#fff;border-radius:var(--radius-md);padding:var(--space-4);margin-bottom:var(--space-4);display:flex;justify-content:center;"></div>
            <p class="text-secondary" style="margin-bottom:var(--space-4);font-size:var(--text-sm);">Mostra questo QR per ritirare il tuo ordine al punto di ritiro.</p>
            <div style="display:flex;justify-content:space-between;margin-bottom:var(--space-4);font-size:var(--text-sm);">
              <div><div class="text-secondary">Quantita</div><div class="font-medium">${qr.quantita_assegnata} unità</div></div>
              <div><div class="text-secondary">Stato</div><div class="font-medium">${qr.stato === 'scansionato' ? 'Ritirato' : qr.stato}</div></div>
            </div>
            <button class="btn btn-outline w-full" onclick="document.getElementById('qr-modal').remove()">Chiudi</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    const box = document.getElementById('qr-code-box');
    if (box && typeof qrcode !== 'undefined') {
      const code = qrcode(0, 'M');
      code.addData(qr.token);
      code.make();
      box.innerHTML = code.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
      const svg = box.querySelector('svg');
      if (svg) {
        svg.style.width = '200px';
        svg.style.height = '200px';
      }
    }
  } catch (err) {
    console.error('QR fetch error:', err);
  }
}
