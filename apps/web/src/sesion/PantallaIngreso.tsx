import { useEffect, useState, type FormEvent } from 'react';
import { api, ErrorApi } from '../api/cliente';
import { useSesion } from './contexto';
import './ingreso.css';

const NOMBRE_ROL: Record<string, string> = {
  promotor: 'Promotor',
  director: 'Director(a)',
  subdirector: 'Subdirector(a)',
  coordinador: 'Coordinador(a)',
  secretaria: 'Secretaría',
  tesoreria: 'Tesorería',
  docente: 'Docente',
  tutor: 'Tutor(a)',
  apoderado: 'Apoderado',
  alumno: 'Alumno',
};

function Marca() {
  return (
    <div className="ingreso-marca">
      <span className="ingreso-logo" aria-hidden="true">E</span>
      <div>
        <h2>Edunova</h2>
        <small>Gestión escolar</small>
      </div>
    </div>
  );
}

/** EDUN-35 · SEG-01: ingreso con DNI o correo y elección de colegio (prototipo, pantalla de ingreso). */
type OpcionesDemo = { activo: boolean; colegio?: string | null; roles: { codigo: string; nombre: string }[] };

/** «¿Olvidaste tu contraseña?»: pide un enlace al correo registrado (DNI o correo). */
function Recuperar({ alVolver }: { alVolver: () => void }) {
  const [usuario, setUsuario] = useState('');
  const [mensaje, setMensaje] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  return (
    <form className="ingreso-pila" noValidate onSubmit={async (e) => {
      e.preventDefault();
      setError(null);
      setEnviando(true);
      try {
        setMensaje((await api<{ message: string }>('/auth/recuperar', { metodo: 'POST', cuerpo: { usuario: usuario.trim() } })).message);
      } catch (x) {
        setError(x instanceof ErrorApi ? x.paraMostrar : 'Ocurrió un error. Inténtalo de nuevo.');
      } finally {
        setEnviando(false);
      }
    }}>
      <h1>Recupera tu acceso</h1>
      <p className="ingreso-ayuda">Escribe tu DNI o correo. Si tienes un correo registrado, te enviaremos un enlace para crear una contraseña nueva.</p>
      <label className="ingreso-campo">
        <span>DNI o correo</span>
        <input autoComplete="username" value={usuario} onChange={(e) => setUsuario(e.target.value)} required />
      </label>
      {mensaje && <p role="status" className="ingreso-ok">{mensaje}</p>}
      {error && <p role="alert" className="ingreso-error">{error}</p>}
      <button type="submit" className="ingreso-boton" disabled={enviando || usuario.trim().length < 6}>{enviando ? 'Enviando…' : 'Enviar enlace'}</button>
      <button type="button" className="ingreso-enlace" onClick={alVolver}>Volver al ingreso</button>
    </form>
  );
}

export function PantallaRestablecer({ token, correo, alTerminar }: { token: string; correo: string; alTerminar: () => void }) {
  const [password, setPassword] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [listo, setListo] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  return (
    <main className="ingreso">
      <div className="ingreso-tarjeta">
        <Marca />
        {listo ? (
          <div className="ingreso-pila">
            <h1>Contraseña creada</h1>
            <p role="status" className="ingreso-ok">{listo}</p>
            <button type="button" className="ingreso-boton" onClick={alTerminar}>Ir al ingreso</button>
          </div>
        ) : (
          <form className="ingreso-pila" noValidate onSubmit={async (e) => {
            e.preventDefault();
            setError(null);
            setEnviando(true);
            try {
              setListo((await api<{ message: string }>('/auth/restablecer', { metodo: 'POST', cuerpo: { token, correo, password, password_confirmation: confirmacion } })).message);
            } catch (x) {
              setError(x instanceof ErrorApi ? x.paraMostrar : 'Ocurrió un error. Inténtalo de nuevo.');
            } finally {
              setEnviando(false);
            }
          }}>
            <h1>Crea tu nueva contraseña</h1>
            <p className="ingreso-ayuda">Para {correo}. Usa al menos 10 caracteres, con letras y números.</p>
            <label className="ingreso-campo">
              <span>Nueva contraseña</span>
              <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={10} />
            </label>
            <label className="ingreso-campo">
              <span>Repite la contraseña</span>
              <input type="password" autoComplete="new-password" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} required />
            </label>
            {error && <p role="alert" className="ingreso-error">{error}</p>}
            <button type="submit" className="ingreso-boton" disabled={enviando || password.length < 10}>{enviando ? 'Guardando…' : 'Guardar contraseña'}</button>
          </form>
        )}
      </div>
    </main>
  );
}

/** Acceso de demostración por rol: solo aparece si el servidor lo tiene activo (servidor de pruebas). */
function AccesoDemo() {
  const { entrarDemo } = useSesion();
  const [opciones, setOpciones] = useState<OpcionesDemo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [entrando, setEntrando] = useState<string | null>(null);
  useEffect(() => {
    api<OpcionesDemo>('/auth/demo').then(setOpciones).catch(() => setOpciones(null));
  }, []);
  if (!opciones?.activo) return null;
  return (
    <section className="ingreso-demo" aria-labelledby="titulo-demo">
      <h2 id="titulo-demo">¿Quieres conocer Edunova?</h2>
      <p className="ingreso-ayuda">Entra a la demostración de {opciones.colegio ?? 'un colegio de prueba'} sin contraseña. Elige cómo quieres verlo:</p>
      <div className="ingreso-roles">
        {opciones.roles.map((r) => (
          <button key={r.codigo} type="button" className="ingreso-opcion" disabled={entrando !== null} onClick={async () => {
            setError(null);
            setEntrando(r.codigo);
            try {
              await entrarDemo(r.codigo);
            } catch (x) {
              setError(x instanceof ErrorApi ? x.paraMostrar : 'No se pudo entrar a la demostración.');
              setEntrando(null);
            }
          }}>
            <b>{NOMBRE_ROL[r.codigo] ?? r.nombre}</b>
            <small>{entrando === r.codigo ? 'Entrando…' : DESCRIPCION_ROL[r.codigo] ?? ''}</small>
          </button>
        ))}
      </div>
      {error && <p role="alert" className="ingreso-error">{error}</p>}
      <p className="ingreso-nota">Son datos ficticios: puedes probar todo sin miedo a romper nada real.</p>
    </section>
  );
}

const DESCRIPCION_ROL: Record<string, string> = {
  director: 'Todo el colegio: matrícula, notas, pagos y reportes',
  secretaria: 'Alumnos, matrícula y trámites',
  tesoreria: 'Caja, pensiones, morosidad y comprobantes',
  docente: 'Notas y asistencia de sus cursos',
  tutor: 'Su sección: asistencia, conducta y justificaciones',
  auxiliar: 'Asistencia y puerta con QR',
  apoderado: 'La vista de la familia: notas, pagos y avisos',
};

export function PantallaIngreso() {
  const { estado, ingresar, elegirColegio, salir } = useSesion();
  const [recuperando, setRecuperando] = useState(false);
  const [usuario, setUsuario] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);

  async function alEnviar(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setEnviando(true);
    try {
      await ingresar(usuario.trim(), password);
    } catch (x) {
      setError(x instanceof ErrorApi ? x.paraMostrar : 'Ocurrió un error. Inténtalo de nuevo.');
    } finally {
      setEnviando(false);
    }
  }

  async function alElegir(id: number) {
    setError(null);
    setEnviando(true);
    try {
      await elegirColegio(id);
    } catch (x) {
      setError(x instanceof ErrorApi ? x.paraMostrar : 'Ocurrió un error. Inténtalo de nuevo.');
    } finally {
      setEnviando(false);
    }
  }

  const eligiendo = estado?.debe_elegir_colegio;
  const sinColegio = estado !== null && estado.colegios.length === 0;

  return (
    <main className="ingreso">
      <div className="ingreso-tarjeta">
        <Marca />
        {sinColegio ? (
          <div className="ingreso-pila">
            <h1>Sin colegio asignado</h1>
            <p role="alert" className="ingreso-error">
              Tu usuario no pertenece a ningún colegio. Si trabajas en Edunova, entra al Panel CMS o a la consola de plataforma.
            </p>
            <button type="button" className="ingreso-boton" onClick={salir}>
              Usar otra cuenta
            </button>
          </div>
        ) : eligiendo ? (
          <div className="ingreso-pila">
            <h1>Elige el colegio</h1>
            <p className="ingreso-ayuda">Tu usuario trabaja en {estado.colegios.length} colegios.</p>
            {estado.colegios.map((c) => (
              <button key={c.id} type="button" className="ingreso-opcion" disabled={enviando} onClick={() => alElegir(c.id)}>
                <b>{c.nombre}</b>
                <small>{c.roles.map((r) => NOMBRE_ROL[r] ?? r).join(' · ')}</small>
              </button>
            ))}
            {error && <p role="alert" className="ingreso-error">{error}</p>}
          </div>
        ) : recuperando ? (
          <Recuperar alVolver={() => setRecuperando(false)} />
        ) : (
          <>
          <form className="ingreso-pila" onSubmit={alEnviar} noValidate>
            <h1>Ingresa a tu cuenta</h1>
            <label className="ingreso-campo">
              <span>DNI o correo</span>
              <input autoComplete="username" value={usuario} onChange={(e) => setUsuario(e.target.value)} required />
            </label>
            <label className="ingreso-campo">
              <span>Contraseña</span>
              <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
            </label>
            {error && <p role="alert" className="ingreso-error">{error}</p>}
            <button type="submit" className="ingreso-boton" disabled={enviando}>
              {enviando ? 'Ingresando…' : 'Ingresar'}
            </button>
            <button type="button" className="ingreso-enlace" onClick={() => setRecuperando(true)}>¿Olvidaste tu contraseña?</button>
            <p className="ingreso-nota">Los apoderados ingresan con su DNI; la primera vez reciben una invitación por correo o WhatsApp.</p>
          </form>
          <AccesoDemo />
          </>
        )}
      </div>
    </main>
  );
}
