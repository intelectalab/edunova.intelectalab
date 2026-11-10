import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fechaHora, NOMBRE_ROL, useAccion, useCarga } from '../panel/utilidades';

type Rol = { codigo: string; nombre: string };
type Usuario = {
  id: number;
  nombres: string;
  apellidos: string;
  numero_documento: string | null;
  email: string | null;
  celular: string | null;
  ultimo_acceso_at: string | null;
  tiene_password: boolean;
  dos_pasos: boolean;
  roles: string[];
  activo: boolean;
  invitacion_pendiente: { vence: string; canal: string } | null;
};
type Invitacion = { canal: string; vence: string; whatsapp_url: string | null };

function Estado({ u }: { u: Usuario }) {
  if (!u.activo) return <span className="insignia insignia-rojo">Inactivo</span>;
  if (!u.tiene_password) return <span className="insignia insignia-ambar">Invitación pendiente</span>;
  return <span className="insignia insignia-ok">Activo</span>;
}

/** Enlace de WhatsApp listo para que quien invita lo envíe desde su teléfono. */
function EnviarWhatsapp({ invitacion }: { invitacion: Invitacion | null }) {
  if (!invitacion?.whatsapp_url) return null;
  return (
    <Aviso tipo="ok">
      Invitación creada. Ábrela en WhatsApp para enviarla:{' '}
      <a href={invitacion.whatsapp_url} target="_blank" rel="noreferrer">
        Enviar por WhatsApp
      </a>
    </Aviso>
  );
}

function FormularioInvitar({ roles, alTerminar }: { roles: Rol[]; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [d, setD] = useState({ nombres: '', apellidos: '', numero_documento: '', email: '', celular: '', canal: 'correo' });
  const [elegidos, setElegidos] = useState<string[]>([]);
  const [hecho, setHecho] = useState<Invitacion | null>(null);
  const cambiar = (k: keyof typeof d) => (e: { target: { value: string } }) => setD({ ...d, [k]: e.target.value });

  async function enviar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const r = await pedir<{ invitacion: Invitacion }>('/usuarios', {
        metodo: 'POST',
        cuerpo: {
          ...d,
          numero_documento: d.numero_documento || null,
          email: d.email || null,
          celular: d.celular || null,
          roles: elegidos,
        },
      });
      setHecho(r.invitacion);
    });
  }

  if (hecho) {
    return (
      <>
        {hecho.whatsapp_url ? <EnviarWhatsapp invitacion={hecho} /> : <Aviso tipo="ok">Le enviamos la invitación a {d.email}. El enlace sirve una sola vez.</Aviso>}
        <div className="acciones">
          <button type="button" className="boton" onClick={alTerminar}>
            Listo
          </button>
        </div>
      </>
    );
  }

  return (
    <form onSubmit={enviar} noValidate>
      <p className="ayuda">La persona recibe un enlace de un solo uso para crear su contraseña. Puedes darle varios roles.</p>
      <div className="campos">
        <Campo etiqueta="Nombres">
          <input value={d.nombres} onChange={cambiar('nombres')} required />
        </Campo>
        <Campo etiqueta="Apellidos">
          <input value={d.apellidos} onChange={cambiar('apellidos')} required />
        </Campo>
        <Campo etiqueta="DNI">
          <input value={d.numero_documento} onChange={cambiar('numero_documento')} inputMode="numeric" maxLength={8} />
        </Campo>
        <Campo etiqueta="Correo">
          <input type="email" value={d.email} onChange={cambiar('email')} />
        </Campo>
        <Campo etiqueta="Celular">
          <input value={d.celular} onChange={cambiar('celular')} inputMode="tel" />
        </Campo>
        <Campo etiqueta="Enviar invitación por">
          <select value={d.canal} onChange={cambiar('canal')}>
            <option value="correo">Correo</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
        </Campo>
      </div>
      <fieldset style={{ border: 0, padding: 0, margin: '14px 0 0' }}>
        <legend className="campo" style={{ marginBottom: 6 }}>
          Roles
        </legend>
        <div className="casillas">
          {roles.map((r) => (
            <label key={r.codigo} className="casilla">
              <input
                type="checkbox"
                checked={elegidos.includes(r.codigo)}
                onChange={(e) => setElegidos(e.target.checked ? [...elegidos, r.codigo] : elegidos.filter((x) => x !== r.codigo))}
              />
              {NOMBRE_ROL[r.codigo] ?? r.nombre}
            </label>
          ))}
        </div>
      </fieldset>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>
          {accion.enviando ? 'Enviando…' : 'Enviar invitación'}
        </button>
        <button type="button" className="boton boton-borde" onClick={alTerminar}>
          Cancelar
        </button>
      </div>
    </form>
  );
}

function EditarUsuario({ u, roles, alTerminar }: { u: Usuario; roles: Rol[]; alTerminar: (recargar: boolean) => void }) {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo;
  const accion = useAccion();
  const [elegidos, setElegidos] = useState<string[]>(u.roles);
  const [invitacion, setInvitacion] = useState<Invitacion | null>(null);
  const yo = estado!.usuario.id === u.id;

  return (
    <>
      <p className="ayuda">
        {u.nombres} {u.apellidos} · {u.email ?? u.celular ?? 'sin contacto'}
      </p>
      {puede(colegio, 'seg.roles.asignar') && (
        <>
          <div className="casillas" style={{ margin: '10px 0' }}>
            {roles.map((r) => (
              <label key={r.codigo} className="casilla">
                <input
                  type="checkbox"
                  disabled={yo}
                  checked={elegidos.includes(r.codigo)}
                  onChange={(e) => setElegidos(e.target.checked ? [...elegidos, r.codigo] : elegidos.filter((x) => x !== r.codigo))}
                />
                {NOMBRE_ROL[r.codigo] ?? r.nombre}
              </label>
            ))}
          </div>
          {yo && <Aviso>No puedes cambiar tus propios roles.</Aviso>}
        </>
      )}
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <EnviarWhatsapp invitacion={invitacion} />
      <div className="acciones">
        {puede(colegio, 'seg.roles.asignar') && !yo && (
          <button
            type="button"
            className="boton"
            disabled={accion.enviando}
            onClick={async () => (await accion.ejecutar(() => pedir(`/usuarios/${u.id}/roles`, { metodo: 'PUT', cuerpo: { roles: elegidos } }))) && alTerminar(true)}
          >
            Guardar roles
          </button>
        )}
        {!u.tiene_password && u.activo && puede(colegio, 'seg.usuarios.crear') && (
          <>
            {u.email && (
              <button
                type="button"
                className="boton boton-claro"
                disabled={accion.enviando}
                onClick={() => accion.ejecutar(() => pedir(`/usuarios/${u.id}/invitacion`, { metodo: 'POST', cuerpo: { canal: 'correo' } }), 'Invitación reenviada por correo.')}
              >
                Reenviar por correo
              </button>
            )}
            {u.celular && (
              <button
                type="button"
                className="boton boton-claro"
                disabled={accion.enviando}
                onClick={() =>
                  accion.ejecutar(async () =>
                    setInvitacion(await pedir<Invitacion>(`/usuarios/${u.id}/invitacion`, { metodo: 'POST', cuerpo: { canal: 'whatsapp' } })),
                  )
                }
              >
                Nuevo enlace por WhatsApp
              </button>
            )}
          </>
        )}
        {puede(colegio, 'seg.usuarios.desactivar') && !yo && (
          <button
            type="button"
            className={u.activo ? 'boton boton-peligro' : 'boton boton-claro'}
            disabled={accion.enviando}
            onClick={async () => (await accion.ejecutar(() => pedir(`/usuarios/${u.id}/estado`, { metodo: 'PATCH', cuerpo: { activo: !u.activo } }))) && alTerminar(true)}
          >
            {u.activo ? 'Desactivar' : 'Reactivar'}
          </button>
        )}
        <button type="button" className="boton boton-borde" onClick={() => alTerminar(false)}>
          Cerrar
        </button>
      </div>
    </>
  );
}

/** EDUN-36 · SEG-02 Usuarios del colegio: invitar por rol, cambiar roles y desactivar. */
export function Usuarios() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo;
  const [filtros, setFiltros] = useState({ buscar: '', rol: '', estado: '' });
  const consulta = new URLSearchParams(Object.entries(filtros).filter(([, v]) => v)).toString();
  const { datos, error, cargando, recargar } = useCarga(() => pedir<{ data: Usuario[]; roles: Rol[] }>(`/usuarios${consulta ? `?${consulta}` : ''}`), [consulta]);
  const [invitando, setInvitando] = useState(false);
  const [editando, setEditando] = useState<Usuario | null>(null);

  return (
    <>
      <Titulo migas={`${colegio!.nombre} · Institución`} titulo="Usuarios" descripcion="Invita al personal con uno o varios roles. Cada persona crea su propia contraseña.">
        {puede(colegio, 'seg.usuarios.crear') && (
          <button type="button" className="boton" onClick={() => setInvitando(true)}>
            + Invitar usuario
          </button>
        )}
      </Titulo>
      <div className="tarjeta">
        <div className="filtros">
          <Campo etiqueta="Buscar">
            <input placeholder="Nombre, correo o DNI" value={filtros.buscar} onChange={(e) => setFiltros({ ...filtros, buscar: e.target.value })} />
          </Campo>
          <Campo etiqueta="Rol">
            <select value={filtros.rol} onChange={(e) => setFiltros({ ...filtros, rol: e.target.value })}>
              <option value="">Todos</option>
              {datos?.roles.map((r) => (
                <option key={r.codigo} value={r.codigo}>
                  {NOMBRE_ROL[r.codigo] ?? r.nombre}
                </option>
              ))}
            </select>
          </Campo>
          <Campo etiqueta="Estado">
            <select value={filtros.estado} onChange={(e) => setFiltros({ ...filtros, estado: e.target.value })}>
              <option value="">Todos</option>
              <option value="activo">Activos</option>
              <option value="pendiente">Invitación pendiente</option>
              <option value="inactivo">Inactivos</option>
            </select>
          </Campo>
        </div>
        <Aviso tipo="error">{error}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Persona</th>
                <th>Roles</th>
                <th>Estado</th>
                <th>Último acceso</th>
                <th>2FA</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((u) => (
                <tr key={u.id}>
                  <td>
                    <b>
                      {u.apellidos}, {u.nombres}
                    </b>
                    <br />
                    <small className="ayuda">{[u.numero_documento, u.email].filter(Boolean).join(' · ')}</small>
                  </td>
                  <td>{u.roles.map((r) => NOMBRE_ROL[r] ?? r).join(', ') || '—'}</td>
                  <td>
                    <Estado u={u} />
                  </td>
                  <td>{fechaHora(u.ultimo_acceso_at)}</td>
                  <td>{u.dos_pasos ? <span className="insignia insignia-ok">Sí</span> : <span className="insignia">No</span>}</td>
                  <td style={{ textAlign: 'right' }}>
                    <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(u)}>
                      Gestionar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!cargando && datos?.data.length === 0 && <p className="vacio">No hay usuarios con esos filtros.</p>}
          {cargando && <p className="vacio">Cargando…</p>}
        </div>
      </div>
      {invitando && datos && (
        <Dialogo titulo="Invitar usuario" alCerrar={() => { setInvitando(false); void recargar(); }}>
          <FormularioInvitar roles={datos.roles} alTerminar={() => { setInvitando(false); void recargar(); }} />
        </Dialogo>
      )}
      {editando && datos && (
        <Dialogo titulo="Gestionar usuario" alCerrar={() => setEditando(null)}>
          <EditarUsuario
            u={editando}
            roles={datos.roles}
            alTerminar={(r) => {
              setEditando(null);
              if (r) void recargar();
            }}
          />
        </Dialogo>
      )}
    </>
  );
}
