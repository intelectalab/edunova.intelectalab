import { useEffect, useState, type FormEvent } from 'react';
import { api } from '../api/cliente';

type Colegio = { nombre: string; razon_social: string | null; ruc: string | null; direccion: string | null; plazo_dias: number };

/**
 * EDUN-66 · Libro de reclamaciones virtual del colegio (Indecopi): formulario público, sin
 * sesión, con número correlativo y copia al correo del consumidor.
 */
export function LibroPublico({ slug }: { slug: string }) {
  const [colegio, setColegio] = useState<Colegio | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [f, setF] = useState({ tipo: 'reclamo', nombre: '', documento: '', email: '', celular: '', detalle: '', pedido: '', monto: '', acepta: false });
  const [enviando, setEnviando] = useState(false);
  const [hecho, setHecho] = useState<{ numero: string; fecha_limite: string } | null>(null);

  useEffect(() => {
    api<Colegio>(`/colegios/${slug}/libro-reclamaciones`).then(setColegio).catch(() => setError('No encontramos el libro de reclamaciones de este colegio.'));
  }, [slug]);

  async function enviar(e: FormEvent) {
    e.preventDefault();
    setEnviando(true);
    setError(null);
    try {
      setHecho(await api<{ numero: string; fecha_limite: string }>(`/colegios/${slug}/libro-reclamaciones`, { metodo: 'POST', cuerpo: { ...f, monto: f.monto ? Number(f.monto) : null, celular: f.celular || null } }));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo registrar.');
    } finally {
      setEnviando(false);
    }
  }

  const campo = (k: keyof typeof f, etiqueta: string, tipo = 'text') => (
    <label className="campo"><span>{etiqueta}</span><input type={tipo} value={String(f[k])} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></label>
  );

  return (
    <main style={{ maxWidth: 760, margin: '24px auto', padding: '0 16px', fontFamily: 'system-ui, sans-serif' }}>
      <h1>Libro de Reclamaciones</h1>
      {colegio && <p>{colegio.razon_social || colegio.nombre}{colegio.ruc ? ` · RUC ${colegio.ruc}` : ''}{colegio.direccion ? ` · ${colegio.direccion}` : ''}</p>}
      {error && <p role="alert" style={{ color: '#b91c1c' }}>{error}</p>}
      {hecho ? (
        <div role="status" style={{ border: '1px solid #16a34a', borderRadius: 8, padding: 16 }}>
          <h2>Registrado con el N.° {hecho.numero}</h2>
          <p>Te enviamos una copia a tu correo. El colegio te responderá a más tardar el {hecho.fecha_limite.split('-').reverse().join('/')}.</p>
        </div>
      ) : colegio && (
        <form onSubmit={enviar} noValidate style={{ display: 'grid', gap: 12 }}>
          <p style={{ fontSize: 14 }}><b>Reclamo:</b> disconformidad con el servicio. <b>Queja:</b> malestar por la atención. Plazo de respuesta: {colegio.plazo_dias} días.</p>
          <label className="campo"><span>Tipo</span><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}><option value="reclamo">Reclamo</option><option value="queja">Queja</option></select></label>
          {campo('nombre', 'Nombre completo')}
          {campo('documento', 'Documento de identidad')}
          {campo('email', 'Correo (recibirás la copia)', 'email')}
          {campo('celular', 'Celular (opcional)', 'tel')}
          <label className="campo"><span>Detalle</span><textarea rows={5} value={f.detalle} onChange={(e) => setF({ ...f, detalle: e.target.value })} /></label>
          <label className="campo"><span>Pedido</span><textarea rows={3} value={f.pedido} onChange={(e) => setF({ ...f, pedido: e.target.value })} /></label>
          {campo('monto', 'Monto reclamado (opcional)', 'number')}
          <label><input type="checkbox" checked={f.acepta} onChange={(e) => setF({ ...f, acepta: e.target.checked })} /> Declaro que los datos son verdaderos.</label>
          <button type="submit" disabled={enviando} style={{ padding: '12px 16px', fontSize: 16 }}>{enviando ? 'Enviando…' : 'Registrar'}</button>
        </form>
      )}
    </main>
  );
}
