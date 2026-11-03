export function Table({ columns = [], rows = [], caption = '' }) {
  let html = '<div class="table-container"><table class="table">';

  if (columns.length > 0) {
    html += '<thead><tr>';
    columns.forEach(col => {
      html += `<th>${col.label || col}</th>`;
    });
    html += '</tr></thead>';
  }

  html += '<tbody>';
  if (rows.length === 0) {
    html += `<tr><td colspan="${columns.length}" class="table-caption">Nessun dato disponibile</td></tr>`;
  } else {
    rows.forEach(row => {
      html += '<tr>';
      columns.forEach(col => {
        const key = col.key || col;
        const value = row[key] !== undefined ? row[key] : '';
        html += `<td>${value}</td>`;
      });
      html += '</tr>';
    });
  }
  html += '</tbody>';

  if (caption) {
    html += `<caption class="table-caption">${caption}</caption>`;
  }

  html += '</table></div>';
  return html;
}
