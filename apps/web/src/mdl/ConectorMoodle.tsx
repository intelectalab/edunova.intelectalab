import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Conexion = {
  configurado: boolean;
  url: string | null;
  tiene_token: boolean;
  sso_activo: boolean;
  frecuencia_minutos: number;
  estado: string | null;
  ultima_sincronizacion_at: string | null;
};

/** EDUN-26 · Configuración del conector de Moodle (solo si el colegio eligió Moodle o ambas). */
export function ConectorMoodle() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<Conexion>('/moodle/conexion'));

  return (
    <>
      <Titulo
        migas={`${colegio.nombre} · Aula virtual`}
        titulo="Moodle"
        descripcion="Conecta el Moodle del colegio: Edunova creará los cursos y matriculará a docentes y alumnos, y traerá las notas."
      />
      <Aviso tipo="error">{error}</Aviso>
      {datos && <FormularioMoodle datos={datos} editable={puede(colegio, 'mdl.conector.configurar')} />}
    </>
  );
}

function FormularioMoodle({ datos, editable }: { datos: Conexion; editable: boolean }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ url: datos.url ?? '', token: '', sso_activo: datos.sso_activo, frecuencia_minutos: datos.frecuencia_minutos });
  const [guardado, setGuardado] = useState(datos);

  async function guardar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      setGuardado(await pedir<Conexion>('/moodle/conexion', { metodo: 'PUT', cuerpo: { ...f, token: f.token || null } }));
      setF({ ...f, token: '' });
    }, 'Conector guardado.');
  }

  return (
    <>
      <div className="tarjeta" style={{ maxWidth: 720 }}>
        <h2>Conector</h2>
        <p className="ayuda">
          {guardado.configurado ? (
            <>
              Configurado · token {guardado.tiene_token ? 'guardado' : 'pendiente'} · última sincronización {fechaHora(guardado.ultima_sincronizacion_at)}
            </>
          ) : (
            'Aún no está configurado.'
          )}
        </p>
        <form onSubmit={guardar} noValidate>
          <div className="campos">
            <Campo etiqueta="Dirección de Moodle" ancho ayuda="Por ejemplo https://aula.tucolegio.edu.pe">
              <input type="url" value={f.url} disabled={!editable} onChange={(e) => setF({ ...f, url: e.target.value })} />
            </Campo>
            <Campo etiqueta="Token del servicio web" ancho ayuda={guardado.tiene_token ? 'Ya hay un token guardado. Escribe uno solo para cambiarlo.' : 'Se guarda cifrado y no se vuelve a mostrar.'}>
              <input type="password" autoComplete="off" value={f.token} disabled={!editable} onChange={(e) => setF({ ...f, token: e.target.value })} />
            </Campo>
            <Campo etiqueta="Sincronizar cada">
              <select value={f.frecuencia_minutos} disabled={!editable} onChange={(e) => setF({ ...f, frecuencia_minutos: Number(e.target.value) })}>
                {[15, 30, 60, 180, 720, 1440].map((m) => (
                  <option key={m} value={m}>
                    {m < 60 ? `${m} minutos` : m === 60 ? '1 hora' : m === 1440 ? '1 día' : `${m / 60} horas`}
                  </option>
                ))}
              </select>
            </Campo>
            <label className="casilla" style={{ alignSelf: 'end' }}>
              <input type="checkbox" checked={f.sso_activo} disabled={!editable} onChange={(e) => setF({ ...f, sso_activo: e.target.checked })} />
              Entrar a Moodle sin otra contraseña
            </label>
          </div>
          <Aviso tipo="error">{accion.error}</Aviso>
          <Aviso tipo="ok">{accion.ok}</Aviso>
          {editable && (
            <div className="acciones">
              <button type="submit" className="boton" disabled={accion.enviando}>
                {accion.enviando ? 'Guardando…' : 'Guardar'}
              </button>
            </div>
          )}
        </form>
      </div>
    </>
  );
}
