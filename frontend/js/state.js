let state = {
  user: null,
  token: null,
  isLoading: true
};

const listeners = new Set();

export function getState() {
  return { ...state };
}

export function setState(newState) {
  state = { ...state, ...newState };
  listeners.forEach(cb => cb(state));
}

export function subscribe(callback) {
  listeners.add(callback);
  return () => listeners.delete(callback);
}

export function initSession() {
  const token = localStorage.getItem('buypool_token');
  const userJson = localStorage.getItem('buypool_user');

  if (token && userJson) {
    try {
      const user = JSON.parse(userJson);
      setState({ user, token, isLoading: false });
    } catch (e) {
      localStorage.removeItem('buypool_token');
      localStorage.removeItem('buypool_user');
      setState({ user: null, token: null, isLoading: false });
    }
  } else {
    setState({ user: null, token: null, isLoading: false });
  }
}

export function saveSession(user, token) {
  localStorage.setItem('buypool_token', token);
  localStorage.setItem('buypool_user', JSON.stringify(user));
  setState({ user, token });
}

export function clearSession() {
  localStorage.removeItem('buypool_token');
  localStorage.removeItem('buypool_user');
  setState({ user: null, token: null });
}
