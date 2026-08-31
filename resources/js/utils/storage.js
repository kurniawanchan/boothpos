// sessionStorage (not memory-only, not localStorage) per the project brief:
// memory-only forces re-login on every refresh; localStorage silently
// persists across browser restarts on a shared terminal. sessionStorage is
// the deliberate middle ground.
const TOKEN_KEY = 'boothpos_token';

export function getToken() {
  return sessionStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
  sessionStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
  sessionStorage.removeItem(TOKEN_KEY);
}
