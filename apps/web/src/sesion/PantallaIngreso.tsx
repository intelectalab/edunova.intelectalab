import { useState, type FormEvent } from 'react';
import { ErrorApi } from '../api/cliente';
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
export function PantallaIngreso() {
  const { estado, ingresar, elegirColegio, salir } = useSesion();
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
        ) : (
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
            <p className="ingreso-nota">Los apoderados ingresan con su DNI; la primera vez reciben una invitación por correo o WhatsApp.</p>
          </form>
        )}
      </div>
    </main>
  );
}
