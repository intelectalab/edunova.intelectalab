import { useEffect, useState, type FormEvent } from 'react';
import { api } from '../api/cliente';

type Resultado = { colegio: string; tipo: string; emitido: string | null; vigente: boolean };

/**
 * EDUN-118 · Verificación pública de documentos (sin sesión). Muestra solo el colegio, el tipo de
 * documento, la fecha y si sigue vigente o fue anulado; nunca datos del alumno.
 */
export function VerificarDocumento({ codigo: inicial }: { codigo: string }) {
  const [codigo, setCodigo] = useState(inicial);
  const [resultado, setResultado] = useState<Resultado | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(Boolean(inicial));

  const verificar = async (c: string) => {
    setCargando(true);
    setError(null);
    setResultado(null);
    try {
      setResultado(await api<Resultado>(`/verificar/${encodeURIComponent(c.trim())}`));
    } catch {
      setError('No encontramos un documento con ese código. Revisa que esté bien escrito.');
    } finally {
      setCargando(false);
    }
  };
  useEffect(() => {
    if (!inicial) return;
    let vigente = true;
    api<Resultado>(`/verificar/${encodeURIComponent(inicial)}`)
      .then((r) => vigente && setResultado(r))
      .catch(() => vigente && setError('No encontramos un documento con ese código. Revisa que esté bien escrito.'))
      .finally(() => vigente && setCargando(false));
    return () => {
      vigente = false;
    };
  }, [inicial]);

  const enviar = (e: FormEvent) => {
    e.preventDefault();
    if (codigo.trim().length >= 6) {
      window.history.replaceState(null, '', `#/verificar/${codigo.trim().toUpperCase()}`);
      void verificar(codigo);
    }
  };

  return (
    <main style={{ maxWidth: 560, margin: '32px auto', padding: '0 16px', fontFamily: 'system-ui, sans-serif', color: '#111827' }}>
      <h1 style={{ fontSize: 24 }}>Verificar un documento</h1>
      <p>Escribe el código que aparece al pie del documento o escanea su QR.</p>
      <form onSubmit={enviar} style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <label style={{ flex: 1, minWidth: 220 }}>
          <span style={{ display: 'block', fontSize: 14, marginBottom: 4 }}>Código de verificación</span>
          <input value={codigo} onChange={(e) => setCodigo(e.target.value.toUpperCase())} maxLength={24} placeholder="DXXXX-XXXX-XXXX" style={{ width: '100%', padding: 10, fontSize: 16, borderRadius: 8, border: '1px solid #d1d5db' }} />
        </label>
        <button type="submit" disabled={cargando || codigo.trim().length < 6} style={{ alignSelf: 'end', padding: '10px 18px', fontSize: 16, borderRadius: 8, border: 0, background: '#27496f', color: '#fff' }}>Verificar</button>
      </form>
      {cargando && <p role="status">Verificando…</p>}
      {error && <p role="alert" style={{ color: '#b91c1c' }}>{error}</p>}
      {resultado && (
        <div role="status" style={{ marginTop: 20, padding: 16, borderRadius: 10, border: `2px solid ${resultado.vigente ? '#16a34a' : '#b91c1c'}`, background: resultado.vigente ? '#f0fdf4' : '#fef2f2' }}>
          <h2 style={{ marginTop: 0, color: resultado.vigente ? '#15803d' : '#b91c1c' }}>{resultado.vigente ? 'Documento válido' : 'Documento anulado: ya no es válido'}</h2>
          <p style={{ margin: '4px 0' }}><b>Colegio:</b> {resultado.colegio}</p>
          <p style={{ margin: '4px 0' }}><b>Documento:</b> {resultado.tipo}</p>
          {resultado.emitido && <p style={{ margin: '4px 0' }}><b>Emitido el:</b> {resultado.emitido.split('-').reverse().join('/')}</p>}
          <p style={{ fontSize: 13, color: '#4b5563', marginBottom: 0 }}>Por privacidad, aquí no se muestran datos del alumno. Compáralos con el documento que tienes.</p>
        </div>
      )}
    </main>
  );
}
