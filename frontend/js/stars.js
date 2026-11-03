/**
 * Stelle di valutazione con supporto alle mezze stelle (overlay a larghezza percentuale).
 * Usa i colori del design system (warning per piene, border per sfondo).
 */
export function starsMedia(voto, size) {
  size = size || 'var(--text-base)';
  const pct = Math.max(0, Math.min(100, ((parseFloat(voto) || 0) / 5) * 100));
  return `<span class="stars-wrap" style="font-size:${size};"><span class="stars-bg">★★★★★</span><span class="stars-fg" style="width:${pct}%;">★★★★★</span></span>`;
}
