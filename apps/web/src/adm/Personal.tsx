import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fecha, NOMBRE_ROL, useAccion, useCarga } from '../panel/utilidades';

type Persona = {
  id: number;
  tipo_documento: string;
  numero_documento: string;
  apellidos: string;
  nombres: string;
  cargo: string;
  especialidad: string | null;
  tipo_contrato: string | null;
  contrato_fin: string | null;
  colegiatura: string | null;
  email: string | null;
  celular: string | null;
  estado: string;
  usuario: 'activo' | 'invitado' | 'sin_usuario';
  contrato: 'vigente' | 'por_vencer' | 'vencido' | null;
};
type Invitacion = { mensaje: string; invitacion: { whatsapp_url: string | null } | null };

const CONTRATOS: Record<string, string> = { nombrado: 'Nombrado', plazo_fijo: 'Plazo fijo', indeterminado: 'Indeterminado', locacion: 'Locación de servicios', practicas: 'Prácticas' };
const ROLES_PERSONAL = ['docente', 'tutor', 'coordinador', 'subdirector', 'secretaria', 'tesoreria', 'auxiliar', 'psicologia', 'enfermeria', 'bibliotecario', 'admision', 'porteria'];

function EstadoContrato({ p }: { p: Persona }) {
  if (p.contrato === 'vencido') return <span className="insignia insignia-rojo">Vencido</span>;
  if (p.contrato === 'por_vencer') return <span className="insignia insignia-ambar">Vence {fecha(p.contrato_fin)}</span>;
  return <span className="ayuda">{p.contrato_fin ? `Hasta ${fecha(p.contrato_fin)}` : '—'}</span>;
}

function FormPersona({ p, alTerminar }: { p: Persona | null; alTerminar: (mensaje?: string, wa?: string | null) => void }) {
  const { pedir, estado } = useSesion();
  const puedeInvitar = puede(estado!.colegio_activo, 'seg.usuarios.crear');
  const accion = useAccion();
  const [f, setF] = useState({
    numero_documento: p?.numero_documento ?? '',
    apellidos: p?.apellidos ?? '',
    nombres: p?.nombres ?? '',
    cargo: p?.cargo ?? 'Docente',
    especialidad: p?.especialidad ?? '',
    tipo_contrato: p?.tipo_contrato ?? '',
    contrato_fin: p?.contrato_fin ?? '',
    colegiatura: p?.colegiatura ?? '',
    email: p?.email ?? '',
    celular: p?.celular ?? '',
    estado: p?.estado ?? 'activo',
  });
  const [invitar, setInvitar] = useState(!p && puedeInvitar);
  const [roles, setRoles] = useState<string[]>(['docente']);
  const [canal, setCanal] = useState('correo');
  const campo = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value });

  async function guardar(e: FormEvent) {
    e.preventDefault();
    const cuerpo = { ...Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v.trim() === '' ? null : v.trim()])), ...(invitar && !p ? { invitar: true, roles, canal } : {}) };
    await accion.ejecutar(async () => {
      const r = await pedir<{ invitacion: Invitacion | null }>(p ? `/personal/${p.id}` : '/personal', { metodo: p ? 'PUT' : 'POST', cuerpo });
      alTerminar(r.invitacion?.mensaje ?? 'Datos guardados.', r.invitacion?.invitacion?.whatsapp_url ?? null);
    });
  }

  return (
    <form onSubmit={guardar} noValidate>
      <div className="campos">
        <Campo etiqueta="DNI">
          <input inputMode="numeric" maxLength={8} value={f.numero_documento} onChange={campo('numero_documento')} />
        </Campo>
        <Campo etiqueta="Apellidos">
          <input value={f.apellidos} onChange={campo('apellidos')} />
        </Campo>
        <Campo etiqueta="Nombres">
          <input value={f.nombres} onChange={campo('nombres')} />
        </Campo>
        <Campo etiqueta="Cargo">
          <input value={f.cargo} onChange={campo('cargo')} />
        </Campo>
        <Campo etiqueta="Especialidad">
          <input value={f.especialidad} onChange={campo('especialidad')} />
        </Campo>
        <Campo etiqueta="Colegiatura">
          <input value={f.colegiatura} onChange={campo('colegiatura')} />
        </Campo>
        <Campo etiqueta="Tipo de contrato">
          <select value={f.tipo_contrato} onChange={campo('tipo_contrato')}>
            <option value="">—</option>
            {Object.entries(CONTRATOS).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </select>
        </Campo>
        <Campo etiqueta="Vence el">
          <input type="date" value={f.contrato_fin} onChange={campo('contrato_fin')} />
        </Campo>
        <Campo etiqueta="Correo">
          <input type="email" value={f.email} onChange={campo('email')} />
        </Campo>
        <Campo etiqueta="Celular">
          <input inputMode="tel" value={f.celular} onChange={campo('celular')} />
        </Campo>
        {p && (
          <Campo etiqueta="Estado">
            <select value={f.estado} onChange={campo('estado')}>
              <option value="activo">Activo</option>
              <option value="cesado">Cesado</option>
            </select>
          </Campo>
        )}
      </div>
      {!p && puedeInvitar && (
        <fieldset style={{ border: '1px solid var(--en-linea)', borderRadius: 8, padding: 12, marginTop: 14 }}>
          <label className="casilla">
            <input type="checkbox" checked={invitar} onChange={(e) => setInvitar(e.target.checked)} />
            Crear su usuario y enviarle la invitación
          </label>
          {invitar && (
            <>
              <div className="casillas" style={{ margin: '8px 0' }}>
                {ROLES_PERSONAL.map((r) => (
                  <label key={r} className="casilla">
                    <input type="checkbox" checked={roles.includes(r)} onChange={(e) => setRoles(e.target.checked ? [...roles, r] : roles.filter((x) => x !== r))} />
                    {NOMBRE_ROL[r] ?? r}
                  </label>
                ))}
              </div>
              <Campo etiqueta="Enviar por">
                <select value={canal} onChange={(e) => setCanal(e.target.value)}>
                  <option value="correo">Correo</option>
                  <option value="whatsapp">WhatsApp</option>
                </select>
              </Campo>
            </>
          )}
        </fieldset>
      )}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>
          {accion.enviando ? 'Guardando…' : p ? 'Guardar' : 'Registrar'}
        </button>
      </div>
    </form>
  );
}

function Invitar({ p, alTerminar }: { p: Persona; alTerminar: (mensaje?: string, wa?: string | null) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [roles, setRoles] = useState<string[]>(['docente']);
  const [canal, setCanal] = useState(p.email ? 'correo' : 'whatsapp');
  return (
    <>
      <p className="ayuda">
        {p.nombres} {p.apellidos} · {p.email ?? 'sin correo'} · {p.celular ?? 'sin celular'}
      </p>
      {p.usuario === 'sin_usuario' && (
        <div className="casillas" style={{ margin: '8px 0' }}>
          {ROLES_PERSONAL.map((r) => (
            <label key={r} className="casilla">
              <input type="checkbox" checked={roles.includes(r)} onChange={(e) => setRoles(e.target.checked ? [...roles, r] : roles.filter((x) => x !== r))} />
              {NOMBRE_ROL[r] ?? r}
            </label>
          ))}
        </div>
      )}
      <Campo etiqueta="Enviar por">
        <select value={canal} onChange={(e) => setCanal(e.target.value)}>
          <option value="correo">Correo</option>
          <option value="whatsapp">WhatsApp</option>
        </select>
      </Campo>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button
          type="button"
          className="boton"
          disabled={accion.enviando}
          onClick={() =>
            accion.ejecutar(async () => {
              const r = await pedir<Invitacion>(`/personal/${p.id}/invitacion`, { metodo: 'POST', cuerpo: { roles, canal } });
              alTerminar(r.mensaje, r.invitacion?.whatsapp_url ?? null);
            })
          }
        >
          {p.usuario === 'sin_usuario' ? 'Crear usuario e invitar' : 'Reenviar invitación'}
        </button>
      </div>
    </>
  );
}

/** EDUN-47 · ADM-04 Personal docente y administrativo. */
export function Personal() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [buscar, setBuscar] = useState('');
  const [estadoFiltro, setEstadoFiltro] = useState('activo');
  const { datos, error, recargar } = useCarga(
    () => pedir<{ data: Persona[] }>(`/personal?${new URLSearchParams({ ...(buscar ? { buscar } : {}), ...(estadoFiltro ? { estado: estadoFiltro } : {}) })}`),
    [buscar, estadoFiltro],
  );
  const [dialogo, setDialogo] = useState<{ tipo: 'nuevo' | 'editar' | 'invitar'; p?: Persona } | null>(null);
  const [aviso, setAviso] = useState<{ mensaje: string; wa: string | null } | null>(null);
  const terminar = (mensaje?: string, wa?: string | null) => {
    setDialogo(null);
    if (mensaje) setAviso({ mensaje, wa: wa ?? null });
    recargar();
  };

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Institución`} titulo="Personal" descripcion="Docentes y administrativos con su cargo, especialidad y contrato.">
        {puede(colegio, 'adm.personal.crear') && (
          <button type="button" className="boton" onClick={() => setDialogo({ tipo: 'nuevo' })}>
            + Registrar personal
          </button>
        )}
      </Titulo>
      {aviso && (
        <Aviso tipo="ok">
          {aviso.mensaje}{' '}
          {aviso.wa && (
            <a href={aviso.wa} target="_blank" rel="noreferrer">
              Enviar por WhatsApp
            </a>
          )}
        </Aviso>
      )}
      <div className="tarjeta" style={{ marginTop: 12 }}>
        <div className="filtros">
          <Campo etiqueta="Buscar">
            <input placeholder="Nombre, DNI o cargo" value={buscar} onChange={(e) => setBuscar(e.target.value)} />
          </Campo>
          <Campo etiqueta="Estado">
            <select value={estadoFiltro} onChange={(e) => setEstadoFiltro(e.target.value)}>
              <option value="activo">Activos</option>
              <option value="cesado">Cesados</option>
              <option value="">Todos</option>
            </select>
          </Campo>
        </div>
        <Aviso tipo="error">{error}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Persona</th>
                <th>Cargo</th>
                <th>Contrato</th>
                <th>Usuario</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((p) => (
                <tr key={p.id}>
                  <td>
                    <b>
                      {p.apellidos}, {p.nombres}
                    </b>
                    <br />
                    <small className="ayuda">DNI {p.numero_documento}</small>
                  </td>
                  <td>
                    {p.cargo}
                    {p.especialidad && <small className="ayuda"> · {p.especialidad}</small>}
                  </td>
                  <td>
                    {p.tipo_contrato && <small className="ayuda">{CONTRATOS[p.tipo_contrato]} · </small>}
                    <EstadoContrato p={p} />
                  </td>
                  <td>
                    {p.usuario === 'activo' ? (
                      <span className="insignia insignia-ok">Activo</span>
                    ) : p.usuario === 'invitado' ? (
                      <span className="insignia insignia-ambar">Invitado</span>
                    ) : (
                      <span className="insignia">Sin usuario</span>
                    )}
                  </td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    {puede(colegio, 'adm.personal.editar') && (
                      <button type="button" className="boton boton-chico boton-borde" onClick={() => setDialogo({ tipo: 'editar', p })}>
                        Editar
                      </button>
                    )}{' '}
                    {p.usuario !== 'activo' && puede(colegio, 'seg.usuarios.crear') && (
                      <button type="button" className="boton boton-chico boton-claro" onClick={() => setDialogo({ tipo: 'invitar', p })}>
                        {p.usuario === 'sin_usuario' ? 'Invitar' : 'Reenviar'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">No hay personal con esos filtros.</p>}
        </div>
      </div>
      {dialogo && (
        <Dialogo
          titulo={dialogo.tipo === 'nuevo' ? 'Registrar personal' : dialogo.tipo === 'editar' ? 'Editar personal' : 'Usuario e invitación'}
          alCerrar={() => setDialogo(null)}
        >
          {dialogo.tipo === 'invitar' ? <Invitar p={dialogo.p!} alTerminar={terminar} /> : <FormPersona p={dialogo.p ?? null} alTerminar={terminar} />}
        </Dialogo>
      )}
    </>
  );
}
