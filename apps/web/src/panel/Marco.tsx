import { useState, type ReactNode } from 'react';
import { moduloActivo, puede, useSesion, type Colegio } from '../sesion/contexto';
import { Admision } from '../adm/Admision';
import { Alumnos } from '../adm/Alumnos';
import { Configuracion } from '../adm/Configuracion';
import { Horarios } from '../adm/Horarios';
import { CondicionesEconomicas, ConsentimientosColegio, PendientesSiagie } from '../adm/Matricula';
import { MisHijos } from '../familia/MisHijos';
import { Beneficios } from '../pag/Beneficios';
import { ConceptosYCronogramas } from '../pag/Conceptos';
import { ProveedoresFacturacion } from '../pag/Facturacion';
import { SoporteColegio } from '../pla/SoporteColegio';
import { Personal } from '../adm/Personal';
import { PlanEstudios } from '../adm/PlanEstudios';
import { ConectorMoodle } from '../mdl/ConectorMoodle';
import { Auditoria } from '../seg/Auditoria';
import { MiPerfil } from '../seg/MiPerfil';
import { Usuarios } from '../seg/Usuarios';
import { estiloMarca, useRuta } from './ruta';
import { NOMBRE_ROL, useImagenApi } from './utilidades';
import './panel.css';

type Pagina = { ruta: string; nombre: string; grupo: string; visible: (c: Colegio) => boolean; pantalla: () => ReactNode };

/** Menú de la web de gestión: cada opción aparece solo con su permiso y su módulo encendido (EDUN-25). */
const PAGINAS: Pagina[] = [
  { ruta: 'inicio', nombre: 'Panel principal', grupo: 'Principal', visible: () => true, pantalla: () => <Inicio /> },
  {
    ruta: 'configuracion',
    nombre: 'Configuración del colegio',
    grupo: 'Institución',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.configuracion.ver'),
    pantalla: () => <Configuracion />,
  },
  {
    ruta: 'personal',
    nombre: 'Personal',
    grupo: 'Institución',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.personal.ver'),
    pantalla: () => <Personal />,
  },
  { ruta: 'usuarios', nombre: 'Usuarios', grupo: 'Institución', visible: (c) => puede(c, 'seg.usuarios.ver'), pantalla: () => <Usuarios /> },
  { ruta: 'auditoria', nombre: 'Auditoría', grupo: 'Institución', visible: (c) => puede(c, 'seg.auditoria.ver'), pantalla: () => <Auditoria /> },
  {
    ruta: 'plan',
    nombre: 'Plan de estudios y secciones',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.plan_estudios.ver'),
    pantalla: () => <PlanEstudios />,
  },
  {
    ruta: 'horarios',
    nombre: 'Carga docente y horarios',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.horarios.ver'),
    pantalla: () => <Horarios />,
  },
  {
    ruta: 'alumnos',
    nombre: 'Alumnos',
    grupo: 'Matrícula',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.alumnos.ver'),
    pantalla: () => <Alumnos />,
  },
  {
    ruta: 'admision',
    nombre: 'Admisión',
    grupo: 'Matrícula',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.admision.ver'),
    pantalla: () => <Admision />,
  },
  {
    ruta: 'condiciones',
    nombre: 'Condiciones económicas',
    grupo: 'Matrícula',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'adm.matricula.ver'),
    pantalla: () => <CondicionesEconomicas />,
  },
  {
    ruta: 'consentimientos',
    nombre: 'Consentimientos',
    grupo: 'Matrícula',
    visible: (c) => puede(c, 'seg.consentimientos.ver'),
    pantalla: () => <ConsentimientosColegio />,
  },
  {
    ruta: 'siagie',
    nombre: 'Pendientes SIAGIE',
    grupo: 'Matrícula',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'sia.nomina.importar', 'sia.plantillas.validar'),
    pantalla: () => <PendientesSiagie />,
  },
  {
    ruta: 'conceptos',
    nombre: 'Conceptos y cronogramas',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.conceptos.ver'),
    pantalla: () => <ConceptosYCronogramas />,
  },
  {
    ruta: 'becas',
    nombre: 'Descuentos y becas',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.conceptos.ver', 'pag.beneficios.aprobar'),
    pantalla: () => <Beneficios />,
  },
  {
    ruta: 'facturacion',
    nombre: 'Facturación electrónica',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.comprobantes.ver'),
    pantalla: () => <ProveedoresFacturacion />,
  },
  {
    ruta: 'familia',
    nombre: 'Mis hijos',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado'),
    pantalla: () => <MisHijos />,
  },
  {
    ruta: 'moodle',
    nombre: 'Moodle',
    grupo: 'Aula virtual',
    visible: (c) => moduloActivo(c, 'MDL') && puede(c, 'mdl.conector.ver'),
    pantalla: () => <ConectorMoodle />,
  },
  { ruta: 'soporte', nombre: 'Soporte', grupo: 'Cuenta', visible: (c) => puede(c, 'pla.tickets.ver'), pantalla: () => <SoporteColegio /> },
  { ruta: 'perfil', nombre: 'Mi perfil y seguridad', grupo: 'Cuenta', visible: () => true, pantalla: () => <MiPerfil /> },
];

function Inicio() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const accesos = PAGINAS.filter((p) => p.ruta !== 'inicio' && p.visible(colegio));
  return (
    <>
      <div className="titulo">
        <div className="migas">{colegio.nombre} · Inicio</div>
        <h1>Hola, {estado!.usuario.nombres}</h1>
        <p>
          {colegio.estado === 'implementacion'
            ? 'Tu colegio está en implementación: completa los datos, el año escolar y los usuarios.'
            : 'Esto es lo que puedes hacer en Edunova.'}
        </p>
      </div>
      {accesos.length > 0 && (
        <div className="rejilla">
          {accesos.map((p) => (
            <a key={p.ruta} className="tarjeta" href={`#/${p.ruta}`} style={{ textDecoration: 'none', color: 'inherit' }}>
              <div className="migas">{p.grupo}</div>
              <h2 style={{ marginTop: 4 }}>{p.nombre}</h2>
            </a>
          ))}
        </div>
      )}
    </>
  );
}

/** Marco de la web de gestión con la sesión iniciada y el segundo factor resuelto. */
export function Marco() {
  const { estado, salir } = useSesion();
  const ruta = useRuta().split('/')[0] || 'inicio';
  const [abierto, setAbierto] = useState(false);
  const colegio = estado!.colegio_activo!;
  // El logo se pide a la API con la sesión: el bucket es privado (EDUN-43).
  const logo = useImagenApi('/institucion/logo', colegio.marca?.tiene_logo ?? false, JSON.stringify(colegio.marca));
  const visibles = PAGINAS.filter((p) => p.visible(colegio));
  const actual = visibles.find((p) => p.ruta === ruta) ?? visibles[0];
  const grupos = [...new Set(visibles.map((p) => p.grupo))];

  return (
    <div className="marco" style={estiloMarca(colegio.marca?.color_primario)}>
      <aside className={`lateral${abierto ? ' abierto' : ''}`}>
        <div className="lateral-marca">
          <span className="lateral-logo">{logo ? <img src={logo} alt={`Logo de ${colegio.nombre}`} /> : 'E'}</span>
          <div style={{ minWidth: 0 }}>
            <strong>Edunova</strong>
            <span style={{ fontSize: 14 }}>{colegio.nombre}</span>
          </div>
          <button type="button" className="boton boton-chico boton-claro boton-movil" onClick={() => setAbierto(!abierto)} aria-expanded={abierto}>
            Menú
          </button>
        </div>
        <nav aria-label="Menú principal">
          {grupos.map((g) => (
            <div key={g}>
              <div className="lateral-grupo">{g}</div>
              {visibles
                .filter((p) => p.grupo === g)
                .map((p) => (
                  <a key={p.ruta} href={`#/${p.ruta}`} aria-current={actual.ruta === p.ruta ? 'page' : undefined} onClick={() => setAbierto(false)}>
                    {p.nombre}
                  </a>
                ))}
            </div>
          ))}
        </nav>
        <div className="lateral-pie">
          <span>
            {estado!.usuario.nombres} {estado!.usuario.apellidos}
            <br />
            <small style={{ opacity: 0.8 }}>{colegio.roles.map((r) => NOMBRE_ROL[r] ?? r).join(' · ')}</small>
          </span>
          <button type="button" onClick={salir}>
            Cerrar sesión
          </button>
        </div>
      </aside>
      <main className="contenido">{actual.pantalla()}</main>
    </div>
  );
}
