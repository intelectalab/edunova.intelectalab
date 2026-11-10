import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { NOMBRE_ROL, useAccion } from '../panel/utilidades';

/** SEG-05 (parte de EDUN-38) · Mi perfil: estado del segundo factor y códigos de respaldo nuevos. */
export function MiPerfil() {
  const { estado, pedir } = useSesion();
  const accion = useAccion();
  const [codigo, setCodigo] = useState('');
  const [respaldos, setRespaldos] = useState<string[] | null>(null);
  const colegio = estado!.colegio_activo!;

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Cuenta`} titulo="Mi perfil y seguridad" />
      <div className="rejilla">
        <div className="tarjeta">
          <h2>Mis datos</h2>
          <p className="ayuda">
            {estado!.usuario.nombres} {estado!.usuario.apellidos}
            <br />
            {estado!.usuario.email ?? 'Sin correo'}
            <br />
            {colegio.roles.map((r) => NOMBRE_ROL[r] ?? r).join(' · ')}
          </p>
        </div>
        <div className="tarjeta">
          <h2>Segundo factor</h2>
          {estado!.dos_pasos_activo ? (
            <>
              <p className="ayuda">
                <span className="insignia insignia-ok">Activo</span> Al ingresar se te pide el código de tu app autenticadora.
              </p>
              <p className="ayuda">Si usaste o perdiste tus códigos de respaldo, genera otros (los anteriores dejan de servir).</p>
              {respaldos ? (
                <>
                  <div className="codigos">
                    {respaldos.map((c) => (
                      <span key={c}>{c}</span>
                    ))}
                  </div>
                  <Aviso tipo="ambar">Guárdalos ahora: no se vuelven a mostrar.</Aviso>
                </>
              ) : (
                <div className="filtros" style={{ marginTop: 10 }}>
                  <Campo etiqueta="Código de tu app">
                    <input inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={codigo} onChange={(e) => setCodigo(e.target.value)} />
                  </Campo>
                  <button
                    type="button"
                    className="boton boton-claro"
                    disabled={accion.enviando || codigo.trim() === ''}
                    onClick={() =>
                      accion.ejecutar(async () => {
                        const r = await pedir<{ codigos_respaldo: string[] }>('/auth/dos-pasos/respaldos', { metodo: 'POST', cuerpo: { codigo } });
                        setRespaldos(r.codigos_respaldo);
                      })
                    }
                  >
                    Generar códigos nuevos
                  </button>
                </div>
              )}
              <Aviso tipo="error">{accion.error}</Aviso>
            </>
          ) : (
            <p className="ayuda">
              Tu cargo no lo exige. Promotor, director y tesorería lo activan obligatoriamente al ingresar.
            </p>
          )}
        </div>
      </div>
    </>
  );
}
