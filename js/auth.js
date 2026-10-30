// ================================================================
// PONTODOT — Auth
// ================================================================
const Auth = {
  async getSession() {
    const { data: { session } } = await sb.auth.getSession();
    return session;
  },
  async getProfile() {
    const session = await this.getSession();
    if (!session) return null;
    const { data } = await sb.from('profiles').select('*').eq('id', session.user.id).single();
    return data;
  },
  async signInWithEmail(email, password) {
    const { error } = await sb.auth.signInWithPassword({ email, password });
    if (error) { showToast(error.message, 'error'); return false; }
    return true;
  },
  async signOut() {
    await sb.auth.signOut();
    window.location.href = '/index.html';
  },
  async requireAuth(allowedRoles = []) {
    const { data: { session } } = await sb.auth.getSession();
    if (!session) { window.location.href = '/index.html'; return null; }
    const profile = await this.getProfile();
    if (!profile) { window.location.href = '/index.html'; return null; }
    if (profile.active === false) {   // acesso desabilitado pelo admin
      try { sessionStorage.setItem('sd_sem_acesso', '1'); } catch (e) {}
      await sb.auth.signOut();
      window.location.href = '/index.html';
      return null;
    }
    if (allowedRoles.length && !allowedRoles.includes(profile.role)) {
      await sb.auth.signOut();
      window.location.href = '/index.html';
      return null;
    }
    return { session, profile };
  },
  onAuthChange(callback) {
    sb.auth.onAuthStateChange((event, session) => callback(event, session));
  }
};
window.Auth = Auth;