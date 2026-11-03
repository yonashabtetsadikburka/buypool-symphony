export function Progress({ value = 0, max = 100, class: extraClass = '', successOnly = false }) {
  const percentage = Math.min(Math.max((value / max) * 100, 0), 100);

  let barClass = 'progress-bar';
  if (successOnly) {
    if (percentage >= 100) barClass += ' progress-bar-success';
  } else if (percentage >= 100) barClass += ' progress-bar-success';
  else if (percentage >= 60) barClass += '';
  else if (percentage >= 30) barClass += ' progress-bar-warning';
  else barClass += ' progress-bar-error';
  return `
    <div class="progress-container ${extraClass}">
      <div class="${barClass}" style="width: ${percentage}%"></div>
    </div>
  `;
}
