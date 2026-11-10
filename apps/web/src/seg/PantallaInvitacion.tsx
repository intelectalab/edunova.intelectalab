import { useEffect, useState, type FormEvent } from 'react';
import { api } from '../api/cliente';
import { mensajeError } from '../panel/utilidades';
import '../sesion/ingreso.css';

type Invitacion = { colegio: string; nombres: string; apellidos: string; email: string | null; tiene_password: boolean };

/** EDUN-36 · Enlace de invitación: el invitado crea su contraseña (un solo uso). */
export function PantallaInvitacion({ token, alTerminar }: { token: string; alTerminar: () => void }) {
  const [invitacion, setInvitacion] = useState<Invitacion | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [listo, setListo] = useState(false);
  const [password, setPassword] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    api<Invitacion>(`/invitaciones/${token}`)
      .then(setInvitacion)
      .catch((e) => setError(mensajeError(e)));
  }, [token]);

  async function aceptar(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setEnviando(true);
    try {
      await api(`/invitaciones/${token}/aceptar`, {
        metodo: 'POST',
        cuerpo: invitacion?.tiene_password ? {} : { password, password_confirmation: confirmacion },
      });
      setListo(true);
    } catch (x) {
      setError(mensajeError(x));
    } finally {
      setEnviando(false);
    }
  }

  return (
    <main className="ingreso">
      <div className="ingreso-tarjeta">
        <div className="ingreso-marca">
          <span className="ingreso-logo" aria-hidden="true">E</span>
          <div>
            <h2>Edunova</h2>
            <small>Gestión escolar</small>
          </div>
        </div>
        {listo ? (
          <div className="ingreso-pila">
            <h1>¡Listo!</h1>
            <p className="ingreso-ayuda">Tu cuenta está activa. Ya puedes ingresar con tu DNI o correo.</p>
            <button type="button" className="ingreso-boton" onClick={alTerminar}>
              Ir al ingreso
            </button>
          </div>
        ) : !invitacion ? (
          <div className="ingreso-pila">
            <h1>Invitación</h1>
            {error ? (
              <>
                <p role="alert" className="ingreso-error">{error}</p>
                <button type="button" className="ingreso-boton" onClick={alTerminar}>
                  Ir al ingreso
                </button>
              </>
            ) : (
              <p className="ingreso-ayuda">Cargando…</p>
            )}
          </div>
        ) : (
          <form className="ingreso-pila" onSubmit={aceptar} noValidate>
            <h1>Hola, {invitacion.nombres}</h1>
            <p className="ingreso-ayuda">
              <b>{invitacion.colegio}</b> te invitó a Edunova.{' '}
              {invitacion.tiene_password ? 'Ya tienes una cuenta: confirma para sumar este colegio.' : 'Crea tu contraseña para entrar.'}
            </p>
            {!invitacion.tiene_password && (
              <>
                <label className="ingreso-campo">
                  <span>Contraseña nueva</span>
                  <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
                </label>
                <label className="ingreso-campo">
                  <span>Repite la contraseña</span>
                  <input type="password" autoComplete="new-password" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} required />
                </label>
                <p className="ingreso-nota">Al menos 10 caracteres, con letras y números.</p>
              </>
            )}
            {error && <p role="alert" className="ingreso-error">{error}</p>}
            <button type="submit" className="ingreso-boton" disabled={enviando}>
              {enviando ? 'Guardando…' : invitacion.tiene_password ? 'Aceptar invitación' : 'Crear contraseña'}
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
