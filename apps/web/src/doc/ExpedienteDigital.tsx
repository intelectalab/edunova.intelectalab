import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';

type Documento = {
  id: number; categoria: string; categoria_nombre: string; nombre: string; vence: string | null; created_at: string; nombre_original: string;
  vencimiento: { codigo: 'vigente' | 'por_vencer' | 'vencido'; texto: string } | null; verificado_por: string | null;
};
const CLASE = { vigente: 'insignia-ok', por_vencer: 'insignia-ambar', vencido: 'insignia-rojo' };

/**
 * EDUN-119 / EDUN-120 · Expediente digital (alumno o personal): carga por categoría, vencimiento
 * con alerta, verificación y descarga con enlace que vence.
 */
export function ExpedienteDigital({ tipo, titular }: { tipo: 'alumno' | 'personal'; titular: number }) {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const edita = puede(colegio, 'doc.expedientes.editar');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Documento[]; categorias: Record<string, string> }>(`/expedientes/${tipo}/${titular}`), [tipo, titular]);
  const [f, setF] = useState({ categoria: '', nombre: '', vence: '' });
  const [archivo, setArchivo] = useState<File | null>(null);
  const accion = useAccion();
  if (!datos) return error ? <Aviso tipo="error">{error}</Aviso> : <p role="status">Cargando…</p>;
  const categoria = f.categoria || Object.keys(datos.categorias)[0];
  return (
    <>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos.data.length === 0 ? <p className="vacio">Sin documentos en el expediente.</p> : (
        <div className="tabla-caja"><table className="tabla"><thead><tr><th>Documento</th><th>Vence</th><th>Verificado</th><th /></tr></thead><tbody>
          {datos.data.map((d) => (
            <tr key={d.id}>
              <td>{d.nombre}<br /><small className="ayuda">{d.categoria_nombre} · {d.nombre_original}</small></td>
              <td>{d.vence ? <>{fecha(d.vence)}<br />{d.vencimiento && <span className={`insignia ${CLASE[d.vencimiento.codigo]}`}>{d.vencimiento.texto}</span>}</> : 'No vence'}</td>
              <td>{d.verificado_por ?? (edita ? <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/expedientes/documentos/${d.id}`, { metodo: 'PATCH', cuerpo: { vence: d.vence, verificar: true } }); recargar(); })}>Marcar verificado</button> : '—')}</td>
              <td><div className="acciones">
                <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(`/expedientes/documentos/${d.id}/archivo`)).url, '_blank', 'noopener'))}>Ver</button>
                {edita && <button type="button" className="boton boton-borde boton-chico" onClick={() => window.confirm(`¿Quitar «${d.nombre}» del expediente? Queda registrado en la auditoría.`) && accion.ejecutar(async () => { await pedir(`/expedientes/documentos/${d.id}`, { metodo: 'DELETE' }); recargar(); })}>Quitar</button>}
              </div></td>
            </tr>
          ))}
        </tbody></table></div>
      )}
      {edita && (
        <form className="filtros" onSubmit={(e) => {
          e.preventDefault();
          if (!archivo) return;
          void accion.ejecutar(async () => {
            const cuerpo = new FormData();
            cuerpo.append('categoria', categoria);
            if (f.nombre.trim()) cuerpo.append('nombre', f.nombre.trim());
            if (f.vence) cuerpo.append('vence', f.vence);
            cuerpo.append('archivo', archivo);
            await pedir(`/expedientes/${tipo}/${titular}`, { metodo: 'POST', cuerpo });
            setF({ categoria, nombre: '', vence: '' });
            setArchivo(null);
            (e.target as HTMLFormElement).reset();
            recargar();
            accion.setOk('Documento agregado al expediente.');
          });
        }}>
          <Campo etiqueta="Categoría"><select value={categoria} onChange={(e) => setF({ ...f, categoria: e.target.value })}>{Object.entries(datos.categorias).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
          <Campo etiqueta="Nombre (opcional)"><input value={f.nombre} onChange={(e) => setF({ ...f, nombre: e.target.value })} maxLength={150} /></Campo>
          <Campo etiqueta="Vence (opcional)" ayuda="Te avisamos 30 y 7 días antes."><input type="date" value={f.vence} onChange={(e) => setF({ ...f, vence: e.target.value })} /></Campo>
          <Campo etiqueta="Archivo (PDF o foto)"><input type="file" accept="application/pdf,image/*" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} required /></Campo>
          <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando || !archivo}>Agregar</button></div>
        </form>
      )}
    </>
  );
}
