/* Larghezza dei <select> pari al testo scelto (la freccia resta accanto alla scritta).
   Dove il browser supporta `field-sizing: content` (vedi layout.css) non serve nulla; altrimenti si misura il testo
   dell'opzione selezionata con un elemento nascosto e si imposta la larghezza al cambio. I select che hanno già una
   larghezza esplicita (campi dei moduli a tutta colonna: width:100%) non vengono toccati. */

const SENZA_SUPPORTO = !(typeof CSS !== 'undefined' && CSS.supports && CSS.supports('field-sizing', 'content'));
const PADDING_E_FRECCIA = 38 + 12 + 3; // padding-destro + padding-sinistro + bordi

let misuratore = null;

/** Larghezza (px) del testo `testo` con il font di `select`. */
export function misuraTesto(select, testo) {
  if (!misuratore) {
    misuratore = document.createElement('span');
    misuratore.setAttribute('aria-hidden', 'true');
    misuratore.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;left:-9999px;top:0;';
    document.body.appendChild(misuratore);
  }
  const st = getComputedStyle(select);
  misuratore.style.font = st.font;
  misuratore.style.letterSpacing = st.letterSpacing;
  misuratore.textContent = testo;
  return Math.ceil(misuratore.getBoundingClientRect().width);
}

function adatta(select) {
  if (select.dataset.larghezzaFissa === 'true') return;
  if (select.style.width === '' && select.offsetWidth > 0 && select.parentElement && select.offsetWidth >= select.parentElement.clientWidth - 2) return; // gia' a tutta colonna
  const opz = select.options[select.selectedIndex];
  if (!opz) return;
  select.style.width = `${misuraTesto(select, opz.text) + PADDING_E_FRECCIA}px`;
}

export function attivaSelectAuto(radice = document) {
  if (!SENZA_SUPPORTO) return;
  radice.querySelectorAll('select').forEach(s => {
    if (s.dataset.selectAuto) return;
    s.dataset.selectAuto = '1';
    adatta(s);
    s.addEventListener('change', () => adatta(s));
  });
}

/** Avvia l'osservazione: le pagine sono generate via JS, quindi i select compaiono dopo il caricamento. */
export function avviaSelectAuto() {
  if (!SENZA_SUPPORTO) return;
  attivaSelectAuto();
  new MutationObserver(() => attivaSelectAuto()).observe(document.body, { childList: true, subtree: true });
}
