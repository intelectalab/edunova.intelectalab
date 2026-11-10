import { useState, type FormEvent } from 'react';
import { useSesion } from '../sesion/contexto';
import { mensajeError } from '../panel/utilidades';
import '../sesion/ingreso.css';

type Qr = { secreto: string; uri: string; qr: string };

/**
 * EDUN-38 · Segundo factor al ingresar (SEG-01): activarlo la primera vez (QR de la app
 * autenticadora y códigos de respaldo) o confirmar el código en cada ingreso.
 */
export function PantallaDosPasos() {
  const { estado, pedir, recargar, salir } = useSesion();
  const configurar = estado?.dos_pasos === 'configurar';
  const [qr, setQr] = useState<Qr | null>(null);
  const [codigo, setCodigo] = useState('');
  const [respaldos, setRespaldos] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [conRespaldo, setConRespaldo] = useState(false);

  async function generar() {
    setError(null);
    setEnviando(true);
    try {
      setQr(await pedir<Qr>('/auth/dos-pasos/iniciar', { metodo: 'POST' }));
    } catch (e) {
      setError(mensajeError(e));
    } finally {
      setEnviando(false);
    }
  }

  async function confirmar(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setEnviando(true);
    try {
      if (configurar) {
        const r = await pedir<{ codigos_respaldo: string[] }>('/auth/dos-pasos/activar', { metodo: 'POST', cuerpo: { codigo } });
        setRespaldos(r.codigos_respaldo);
      } else {
        await pedir('/auth/dos-pasos/verificar', { metodo: 'POST', cuerpo: { codigo } });
        await recargar();
      }
    } catch (x) {
      setError(mensajeError(x));
    } finally {
      setEnviando(false);
    }
  }

  return (
    <main className="ingreso">
      <div className="ingreso-tarjeta" style={{ width: 'min(480px, 100%)' }}>
        {respaldos ? (
          <div className="ingreso-pila">
            <h1>Guarda tus códigos de respaldo</h1>
            <p className="ingreso-ayuda">
              Si pierdes tu teléfono, cada código te deja entrar una vez. Guárdalos en un lugar seguro: no se vuelven a mostrar.
            </p>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '6px 16px', padding: 14, background: 'var(--en-suave)', borderRadius: 8, fontFamily: 'ui-monospace, Consolas, monospace' }}>
              {respaldos.map((c) => (
                <span key={c}>{c}</span>
              ))}
            </div>
            <button type="button" className="ingreso-boton" onClick={() => void recargar()}>
              Ya los guardé, continuar
            </button>
          </div>
        ) : (
          <form className="ingreso-pila" onSubmit={confirmar} noValidate>
            <h1>{configurar ? 'Activa el segundo factor' : 'Confirma que eres tú'}</h1>
            {configurar ? (
              <>
                <p className="ingreso-ayuda">
                  Por seguridad, tu cargo necesita un código de tu teléfono además de la contraseña. Instala una app autenticadora (Google
                  Authenticator, Microsoft Authenticator o similar) y escanea el código.
                </p>
                {qr ? (
                  <div style={{ display: 'grid', justifyItems: 'center', gap: 8 }}>
                    <img src={qr.qr} alt="Código QR para la app autenticadora" width={200} height={200} />
                    <small className="ingreso-ayuda">
                      ¿No puedes escanear? Escribe esta clave en la app: <b style={{ fontFamily: 'ui-monospace, Consolas, monospace' }}>{qr.secreto}</b>
                    </small>
                  </div>
                ) : (
                  <button type="button" className="ingreso-boton" onClick={generar} disabled={enviando}>
                    Mostrar el código QR
                  </button>
                )}
              </>
            ) : (
              <p className="ingreso-ayuda">
                {conRespaldo ? 'Escribe uno de tus códigos de respaldo.' : 'Escribe el código de 6 dígitos que muestra tu app autenticadora.'}
              </p>
            )}
            {(!configurar || qr) && (
              <label className="ingreso-campo">
                <span>{conRespaldo ? 'Código de respaldo' : 'Código de la app'}</span>
                <input
                  inputMode={conRespaldo ? 'text' : 'numeric'}
                  autoComplete="one-time-code"
                  value={codigo}
                  onChange={(e) => setCodigo(e.target.value)}
                  maxLength={conRespaldo ? 20 : 7}
                  required
                  autoFocus
                />
              </label>
            )}
            {error && (
              <p role="alert" className="ingreso-error">
                {error}
              </p>
            )}
            {(!configurar || qr) && (
              <button type="submit" className="ingreso-boton" disabled={enviando || codigo.trim() === ''}>
                {enviando ? 'Confirmando…' : configurar ? 'Activar' : 'Confirmar'}
              </button>
            )}
            {!configurar && (
              <button type="button" className="ingreso-enlace" onClick={() => { setConRespaldo(!conRespaldo); setCodigo(''); }}>
                {conRespaldo ? 'Usar el código de la app' : 'No tengo mi teléfono: usar un código de respaldo'}
              </button>
            )}
            <button type="button" className="ingreso-enlace" onClick={salir}>
              Salir
            </button>
          </form>
        )}
      </div>
    </main>
  );
}
