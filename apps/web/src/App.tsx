import { useState } from 'react';
import { LibroPublico } from './com/LibroPublico';
import { slugLibro } from './com/libro';
import { Marco } from './panel/Marco';
import { Consola } from './pla/Consola';
import { PantallaDosPasos } from './seg/PantallaDosPasos';
import { PantallaInvitacion } from './seg/PantallaInvitacion';
import { ProveedorSesion } from './sesion/ContextoSesion';
import { sesionLista, useSesion } from './sesion/contexto';
import { PantallaIngreso, PantallaRestablecer } from './sesion/PantallaIngreso';
import { datosRestablecer } from './sesion/restablecer';

/** Enlace de invitación: ?invitacion=<token> (EDUN-36). */
function tokenInvitacion(): string | null {
  const t = new URLSearchParams(window.location.search).get('invitacion');
  return t && /^[A-Za-z0-9]{48}$/.test(t) ? t : null;
}

function Contenido() {
  const { cargando, estado, salir } = useSesion();
  const [invitacion, setInvitacion] = useState(tokenInvitacion);
  const [restablecer, setRestablecer] = useState(datosRestablecer);

  if (invitacion) {
    return (
      <PantallaInvitacion
        token={invitacion}
        alTerminar={() => {
          window.history.replaceState(null, '', window.location.pathname);
          setInvitacion(null);
          // Si en este navegador había otra sesión abierta, el invitado ingresa con la suya.
          if (estado) void salir();
        }}
      />
    );
  }
  if (restablecer) {
    return (
      <PantallaRestablecer
        {...restablecer}
        alTerminar={() => {
          window.history.replaceState(null, '', window.location.pathname);
          setRestablecer(null);
          if (estado) void salir();
        }}
      />
    );
  }
  if (cargando) return <p style={{ padding: 32 }}>Cargando…</p>;
  if (!estado) return <PantallaIngreso />;
  // EDUN-38 · Segundo factor antes de cualquier pantalla.
  if (!sesionLista(estado)) return <PantallaDosPasos />;
  if (estado.colegio_activo) return <Marco />;
  // Staff de Edunova sin colegio: consola de la plataforma.
  if (estado.roles_plataforma.length > 0 && estado.colegios.length === 0) return <Consola />;
  return <PantallaIngreso />;
}

export default function App() {
  // EDUN-66 · El libro de reclamaciones del colegio es público (sin sesión).
  const libro = slugLibro();
  if (libro) return <LibroPublico slug={libro} />;
  return (
    <ProveedorSesion>
      <Contenido />
    </ProveedorSesion>
  );
}
