let timers = [];

export function setPageInterval(fn, ms) {
  const id = setInterval(fn, ms);
  timers.push(id);
  return id;
}

/** Timeout di pagina: stesso registro, così clearPageTimers() lo cancella anche lui. */
export function setPageTimeout(fn, ms) {
  const id = setTimeout(fn, ms);
  timers.push(id);
  return id;
}

export function clearPageTimers() {
  timers.forEach(clearInterval);
  timers = [];
}
