import { ProveedorSesion } from './sesion/ContextoSesion';
import { useSesion } from './sesion/contexto';
import { PantallaIngreso } from './sesion/PantallaIngreso';

/** Marco de la web de gestión con la sesión iniciada. Las pantallas llegan con cada módulo. */
function Marco() {
  const { estado, salir } = useSesion();
  if (!estado?.colegio_activo) return null;
  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <aside style={{ width: 240, background: 'var(--en-menu-lateral)', color: '#fff', padding: '24px 16px', display: 'flex', flexDirection: 'column', gap: 16 }}>
        <strong style={{ fontFamily: 'var(--en-fuente-titulos)', fontSize: 20 }}>Edunova</strong>
        <span style={{ fontSize: 14 }}>{estado.colegio_activo.nombre}</span>
        <span style={{ flex: 1 }} />
        <span style={{ fontSize: 14 }}>
          {estado.usuario.nombres} {estado.usuario.apellidos}
        </span>
        <button type="button" onClick={salir} style={{ height: 36, borderRadius: 8, border: '1px solid #fff', background: 'transparent', color: '#fff', cursor: 'pointer' }}>
          Cerrar sesión
        </button>
      </aside>
      <main style={{ flex: 1, padding: 32, background: 'var(--en-tarjeta)' }}>
        <h1>Hola, {estado.usuario.nombres}</h1>
        <p style={{ color: 'var(--en-texto2)' }}>Las pantallas de cada módulo llegan con sus historias.</p>
      </main>
    </div>
  );
}

function Contenido() {
  const { cargando, estado } = useSesion();
  if (cargando) return <p style={{ padding: 32 }}>Cargando…</p>;
  if (!estado || !estado.colegio_activo) return <PantallaIngreso />;
  return <Marco />;
}

export default function App() {
  return (
    <ProveedorSesion>
      <Contenido />
    </ProveedorSesion>
  );
}
