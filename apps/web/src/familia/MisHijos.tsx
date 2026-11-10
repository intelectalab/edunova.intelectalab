import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Dialogo, Titulo } from '../panel/ui';
import { useAccion, useCarga } from '../panel/utilidades';

type Estado = { estado: string; version: string | null; desactualizado: boolean };
type Hijo = { id: number; nombres: string; apellido_paterno: string; apellido_materno: string | null; seccion: string | null; consentimientos: Record<string, Estado> };
type Texto = { version: string; contenido: string } | null;
type Familia = { hijos: Hijo[]; textos: Record<string, Texto>; finalidades: Record<string, string>; condiciones: { anio: number; version: number; contenido: string } | null };
type Carne = { codigo: string; qr: string; alumno: string; seccion: string | null; colegio: string; foto: string | null };

const ESTADO: Record<string, string> = { otorgado: 'Autorizado', negado: 'No autorizado', revocado: 'Revocado', pendiente: 'Pendiente' };

function CarneHijo({ id }: { id: number }) {
  const { pedir } = useSesion();
  const { datos, error } = useCarga(() => pedir<Carne>(`/me/carne/${id}`), [id]);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  return (
    <div style={{ textAlign: 'center' }}>
      <p>
        <b>{datos.alumno}</b>
        <br />
        <span className="ayuda">
          {datos.colegio} · {datos.seccion ?? ''}
        </span>
      </p>
      <img src={datos.qr} alt="Código QR del carné" width={220} height={220} />
      <p className="ayuda">Muéstralo en portería, asistencia o comedor. Código {datos.codigo}</p>
    </div>
  );
}

/**
 * Familia (apoderado) en la web: sus hijos, los consentimientos por aceptar o revocar
 * (EDUN-41), las condiciones económicas del año (EDUN-54) y el carné con QR (EDUN-59).
 */
export function MisHijos() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, setDatos, error } = useCarga(() => pedir<Familia>('/me/familia'));
  const accion = useAccion();
  const [leyendo, setLeyendo] = useState<{ hijo: Hijo; finalidad: string } | null>(null);
  const [carne, setCarne] = useState<number | null>(null);

  async function decidir(hijo: Hijo, finalidad: string, otorgado: boolean) {
    const ok = await accion.ejecutar(async () => {
      const r = await pedir<{ consentimientos: Record<string, Estado> }>('/me/consentimientos', { metodo: 'POST', cuerpo: { alumno_id: hijo.id, finalidad, otorgado } });
      setDatos({ ...datos!, hijos: datos!.hijos.map((h) => (h.id === hijo.id ? { ...h, consentimientos: r.consentimientos } : h)) });
    }, otorgado ? 'Gracias: autorización registrada.' : 'Listo: registramos tu decisión.');
    if (ok) setLeyendo(null);
  }

  return (
    <>
      <Titulo migas={colegio.nombre} titulo="Mis hijos" descripcion="Autorizaciones, carné y condiciones del colegio." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos?.hijos.length === 0 && <p className="vacio">Aún no hay alumnos vinculados a tu cuenta.</p>}
      <div className="rejilla">
        {datos?.hijos.map((h) => (
          <div key={h.id} className="tarjeta">
            <h2>
              {h.nombres} {h.apellido_paterno}
            </h2>
            <p className="ayuda">{h.seccion ?? 'Sin matrícula vigente'}</p>
            {Object.entries(datos.finalidades).map(([f, nombre]) => {
              const c = h.consentimientos[f];
              return (
                <div key={f} className="interruptor">
                  <span>
                    {nombre}
                    <br />
                    <span className={`insignia ${c.estado === 'otorgado' ? 'insignia-ok' : c.estado === 'pendiente' ? 'insignia-ambar' : 'insignia-rojo'}`}>{ESTADO[c.estado]}</span>
                    {c.desactualizado && <small className="ayuda"> · hay un texto nuevo</small>}
                  </span>
                  <span style={{ display: 'flex', gap: 6 }}>
                    {datos.textos[f] && (c.estado !== 'otorgado' || c.desactualizado) && (
                      <button type="button" className="boton boton-chico" onClick={() => setLeyendo({ hijo: h, finalidad: f })}>
                        Leer y decidir
                      </button>
                    )}
                    {c.estado === 'otorgado' && (
                      <button type="button" className="boton boton-chico boton-borde" disabled={accion.enviando} onClick={() => void decidir(h, f, false)}>
                        Revocar
                      </button>
                    )}
                  </span>
                </div>
              );
            })}
            <div className="acciones">
              <button type="button" className="boton boton-claro" onClick={() => setCarne(h.id)}>
                Ver carné
              </button>
            </div>
          </div>
        ))}
      </div>
      {datos?.condiciones && (
        <details className="tarjeta" style={{ marginTop: 18 }}>
          <summary style={{ cursor: 'pointer' }}>
            <b>Condiciones económicas {datos.condiciones.anio}</b> <span className="ayuda">(versión {datos.condiciones.version})</span>
          </summary>
          <p style={{ whiteSpace: 'pre-wrap' }}>{datos.condiciones.contenido}</p>
        </details>
      )}
      {leyendo && datos && (
        <Dialogo titulo={datos.finalidades[leyendo.finalidad]} alCerrar={() => setLeyendo(null)}>
          <p className="ayuda">
            Para {leyendo.hijo.nombres} · versión {datos.textos[leyendo.finalidad]?.version}
          </p>
          <p style={{ whiteSpace: 'pre-wrap', fontSize: 15 }}>{datos.textos[leyendo.finalidad]?.contenido}</p>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando} onClick={() => void decidir(leyendo.hijo, leyendo.finalidad, true)}>
              Sí, autorizo
            </button>
            <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => void decidir(leyendo.hijo, leyendo.finalidad, false)}>
              No autorizo
            </button>
          </div>
        </Dialogo>
      )}
      {carne && (
        <Dialogo titulo="Carné" alCerrar={() => setCarne(null)}>
          <CarneHijo id={carne} />
        </Dialogo>
      )}
    </>
  );
}
