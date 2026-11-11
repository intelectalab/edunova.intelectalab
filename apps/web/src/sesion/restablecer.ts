/** Enlace del correo: #/restablecer?token=…&correo=… */
export function datosRestablecer(): { token: string; correo: string } | null {
  const m = /^#\/restablecer\?(.*)$/.exec(window.location.hash);
  if (!m) return null;
  const p = new URLSearchParams(m[1]);
  const token = p.get('token') ?? '';
  const correo = p.get('correo') ?? '';
  return /^[A-Za-z0-9]{64}$/.test(token) && correo.includes('@') ? { token, correo } : null;
}
