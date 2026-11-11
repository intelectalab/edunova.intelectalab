import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { useAccion, useCarga } from '../panel/utilidades';
import { soles } from '../pag/tipos';

type Hijo = {
  alumno_id: number; alumno: string; actual: string; anio: number; seccion: { id: number; nombre: string } | null;
  condiciones: { version: number; contenido: string } | null; matricula: { id: number; estado: string } | null;
  cargo_matricula: { id: number; estado: string; saldo: number } | null;
};
type Datos = { hijos: Hijo[]; datos: { celular: string | null; email: string | null; direccion: string | null } | null };

/** EDUN-55 · ADM-H13 Ratificar la matrícula del próximo año (web y app). */
export function Ratificacion() {
  const { pedir, estado, token } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<Datos>('/familia/ratificacion'));
  const accion = useAccion();
  const [acepta, setAcepta] = useState<Record<number, boolean>>({});
  const [f, setF] = useState<{ celular: string; email: string; direccion: string } | null>(null);
  const form = f ?? { celular: datos?.datos?.celular ?? '', email: datos?.datos?.email ?? '', direccion: datos?.datos?.direccion ?? '' };

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Ratificar matrícula" descripcion="Confirma la vacante de tus hijos para el próximo año, acepta las condiciones y paga la matrícula." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos?.hijos.length === 0 && <p className="vacio">No hay ratificaciones pendientes.</p>}
      <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)', maxWidth: 900 }}>
        {datos?.hijos.map((h) => (
          <div key={h.alumno_id} className="tarjeta">
            <h2>{h.alumno} · {h.anio}</h2>
            <p className="ayuda">Este año: {h.actual}. {h.seccion ? `Vacante propuesta: ${h.seccion.nombre}.` : 'No hay vacante disponible en el grado siguiente: comunícate con el colegio.'}</p>
            {h.matricula ? (
              h.matricula.estado === 'matriculado' ? (
                <>
                  <Aviso tipo="ok">Matrícula {h.anio} confirmada.</Aviso>
                  <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => guardarArchivo(await apiArchivo(`/familia/constancias/${h.matricula!.id}`, token), `constancia-matricula-${h.anio}.pdf`))}>Descargar constancia</button>
                </>
              ) : (
                <Aviso tipo="ambar">
                  Vacante reservada. {h.cargo_matricula && h.cargo_matricula.saldo > 0 ? <>Falta pagar la matrícula ({soles(h.cargo_matricula.saldo)}). <a href="#/mis-pagos">Pagar ahora</a></> : 'Se confirmará al registrar el pago.'}
                </Aviso>
              )
            ) : h.condiciones && h.seccion ? (
              <>
                <h3>Condiciones económicas {h.anio} (versión {h.condiciones.version})</h3>
                <div style={{ whiteSpace: 'pre-wrap', maxHeight: 220, overflow: 'auto', border: '1px solid var(--en-borde)', borderRadius: 8, padding: 10 }}>{h.condiciones.contenido}</div>
                <label className="casilla" style={{ marginTop: 8 }}>
                  <input type="checkbox" checked={!!acepta[h.alumno_id]} onChange={(e) => setAcepta({ ...acepta, [h.alumno_id]: e.target.checked })} /> Leí y acepto las condiciones económicas
                </label>
                <h3>Actualiza tus datos</h3>
                <div className="campos">
                  <Campo etiqueta="Celular"><input inputMode="tel" maxLength={9} value={form.celular} onChange={(e) => setF({ ...form, celular: e.target.value })} /></Campo>
                  <Campo etiqueta="Correo"><input type="email" value={form.email} onChange={(e) => setF({ ...form, email: e.target.value })} /></Campo>
                  <Campo etiqueta="Dirección" ancho><input value={form.direccion} onChange={(e) => setF({ ...form, direccion: e.target.value })} /></Campo>
                </div>
                <div className="acciones">
                  <button type="button" className="boton" disabled={!acepta[h.alumno_id] || accion.enviando} onClick={() => accion.ejecutar(async () => {
                    const r = await pedir<{ estado: string }>('/familia/ratificacion', { metodo: 'POST', cuerpo: { alumno_id: h.alumno_id, acepta_condiciones: true, celular: form.celular || null, email: form.email || null, direccion: form.direccion || null } });
                    recargar();
                    accion.setOk(r.estado === 'matriculado' ? 'Matrícula confirmada.' : 'Vacante reservada. Paga la matrícula para confirmarla.');
                  })}>Ratificar matrícula</button>
                </div>
              </>
            ) : <p className="ayuda">El colegio aún no publica las condiciones económicas del próximo año.</p>}
          </div>
        ))}
      </div>
    </>
  );
}
