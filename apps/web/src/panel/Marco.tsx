import { lazy, Suspense, useState, type ComponentType, type ReactNode } from 'react';
import { moduloActivo, puede, useSesion, type Colegio } from '../sesion/contexto';
import { estiloMarca, useRuta } from './ruta';
import { NOMBRE_ROL, useImagenApi } from './utilidades';
import './panel.css';

// Cada pantalla se descarga recién al abrirla (el bundle inicial ya no lleva las ~50 pantallas).
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- lazy() exige ComponentType<any>
function perezoso<M, K extends keyof M>(cargar: () => Promise<M>, nombre: K) {
  return lazy(async () => ({ default: (await cargar())[nombre] as M[K] & ComponentType<any> }));
}
const Admision = perezoso(() => import('../adm/Admision'), 'Admision');
const Alumnos = perezoso(() => import('../adm/Alumnos'), 'Alumnos');
const Configuracion = perezoso(() => import('../adm/Configuracion'), 'Configuracion');
const Horarios = perezoso(() => import('../adm/Horarios'), 'Horarios');
const CondicionesEconomicas = perezoso(() => import('../adm/Matricula'), 'CondicionesEconomicas');
const ConsentimientosColegio = perezoso(() => import('../adm/Matricula'), 'ConsentimientosColegio');
const PendientesSiagie = perezoso(() => import('../adm/Matricula'), 'PendientesSiagie');
const MisHijos = perezoso(() => import('../familia/MisHijos'), 'MisHijos');
const MisPagos = perezoso(() => import('../familia/MisPagos'), 'MisPagos');
const PagoSimulado = perezoso(() => import('../familia/MisPagos'), 'PagoSimulado');
const Ratificacion = perezoso(() => import('../familia/Ratificacion'), 'Ratificacion');
const CierrePeriodo = perezoso(() => import('../cal/Cierre'), 'CierrePeriodo');
const Notas = perezoso(() => import('../cal/Notas'), 'Notas');
const LibretasYActas = perezoso(() => import('../cal/Libretas'), 'LibretasYActas');
const AlertasRiesgo = perezoso(() => import('../cal/Seguimiento'), 'AlertasRiesgo');
const AsistenciaDiaria = perezoso(() => import('../cal/Seguimiento'), 'AsistenciaDiaria');
const TutoriaAlumno = perezoso(() => import('../cal/Seguimiento'), 'TutoriaAlumno');
const MisNotas = perezoso(() => import('../familia/MisNotas'), 'MisNotas');
const Siagie = perezoso(() => import('../sia/Siagie'), 'Siagie');
const Agenda = perezoso(() => import('../com/Comunicacion'), 'Agenda');
const Bitacora = perezoso(() => import('../com/Comunicacion'), 'Bitacora');
const Comunicados = perezoso(() => import('../com/Comunicacion'), 'Comunicados');
const MisComunicados = perezoso(() => import('../com/Comunicacion'), 'MisComunicados');
const Reclamos = perezoso(() => import('../com/Comunicacion'), 'Reclamos');
const Citas = perezoso(() => import('../com/Mensajes'), 'Citas');
const Mensajes = perezoso(() => import('../com/Mensajes'), 'Mensajes');
const Morosidad = perezoso(() => import('../pag/Morosidad'), 'Morosidad');
const Convenios = perezoso(() => import('../pag/Convenios'), 'Convenios');
const MisConvenios = perezoso(() => import('../pag/Convenios'), 'MisConvenios');
const Conciliacion = perezoso(() => import('../pag/Finanzas'), 'Conciliacion');
const Recaudacion = perezoso(() => import('../pag/Finanzas'), 'Recaudacion');
const ReportesFinancieros = perezoso(() => import('../pag/Finanzas'), 'ReportesFinancieros');
const Caja = perezoso(() => import('../pag/Caja'), 'Caja');
const Comprobantes = perezoso(() => import('../pag/Comprobantes'), 'Comprobantes');
const Pasarela = perezoso(() => import('../pag/Pasarela'), 'Pasarela');
const Beneficios = perezoso(() => import('../pag/Beneficios'), 'Beneficios');
const ConceptosYCronogramas = perezoso(() => import('../pag/Conceptos'), 'ConceptosYCronogramas');
const ProveedoresFacturacion = perezoso(() => import('../pag/Facturacion'), 'ProveedoresFacturacion');
const SoporteColegio = perezoso(() => import('../pla/SoporteColegio'), 'SoporteColegio');
const Personal = perezoso(() => import('../adm/Personal'), 'Personal');
const PlanEstudios = perezoso(() => import('../adm/PlanEstudios'), 'PlanEstudios');
const ConectorMoodle = perezoso(() => import('../mdl/ConectorMoodle'), 'ConectorMoodle');
const Auditoria = perezoso(() => import('../seg/Auditoria'), 'Auditoria');
const MiPerfil = perezoso(() => import('../seg/MiPerfil'), 'MiPerfil');
const Usuarios = perezoso(() => import('../seg/Usuarios'), 'Usuarios');

type Pagina = { ruta: string; nombre: string; grupo: string; visible: (c: Colegio) => boolean; pantalla: () => ReactNode; oculta?: boolean };

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
    ruta: 'notas',
    nombre: 'Notas',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'CAL') && puede(c, 'cal.notas.registrar', 'cal.notas.ver'),
    pantalla: () => <Notas />,
  },
  {
    ruta: 'cierre-notas',
    nombre: 'Revisión y cierre',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'CAL') && puede(c, 'cal.notas.revisar'),
    pantalla: () => <CierrePeriodo />,
  },
  {
    ruta: 'asistencia',
    nombre: 'Asistencia',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'CAL') && puede(c, 'cal.asistencia.registrar', 'cal.asistencia.ver'),
    pantalla: () => <AsistenciaDiaria />,
  },
  {
    ruta: 'tutoria',
    nombre: 'Tutoría y conducta',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'CAL') && puede(c, 'cal.conducta.registrar', 'cal.conducta.ver'),
    pantalla: () => <TutoriaAlumno />,
  },
  {
    ruta: 'alertas',
    nombre: 'Alertas de riesgo',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'CAL') && puede(c, 'cal.alertas.ver'),
    pantalla: () => <AlertasRiesgo />,
  },
  {
    ruta: 'libretas',
    nombre: 'Libretas y actas',
    grupo: 'Académico',
    visible: (c) => moduloActivo(c, 'CAL') && puede(c, 'cal.libretas.ver'),
    pantalla: () => <LibretasYActas />,
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
    ruta: 'siagie-archivos',
    nombre: 'SIAGIE',
    grupo: 'Matrícula',
    visible: (c) => moduloActivo(c, 'SIA') && puede(c, 'sia.nomina.importar', 'sia.plantillas.validar', 'sia.plantillas.generar', 'sia.plantillas.descargar'),
    pantalla: () => <Siagie />,
  },
  {
    ruta: 'siagie',
    nombre: 'Pendientes SIAGIE',
    grupo: 'Matrícula',
    visible: (c) => moduloActivo(c, 'ADM') && puede(c, 'sia.nomina.importar', 'sia.plantillas.validar'),
    pantalla: () => <PendientesSiagie />,
  },
  {
    ruta: 'caja',
    nombre: 'Caja',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.caja.ver', 'pag.caja.abrir'),
    pantalla: () => <Caja />,
  },
  {
    ruta: 'comprobantes',
    nombre: 'Comprobantes',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.comprobantes.ver'),
    pantalla: () => <Comprobantes />,
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
    ruta: 'pasarela',
    nombre: 'Pagos en línea',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.pasarela.ver'),
    pantalla: () => <Pasarela />,
  },
  {
    ruta: 'morosidad',
    nombre: 'Morosidad',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.cobranza.ver'),
    pantalla: () => <Morosidad />,
  },
  {
    ruta: 'convenios',
    nombre: 'Convenios',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.convenios.ver'),
    pantalla: () => <Convenios />,
  },
  {
    ruta: 'recaudacion',
    nombre: 'Recaudación bancaria',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.recaudacion.ver'),
    pantalla: () => <Recaudacion />,
  },
  {
    ruta: 'conciliacion',
    nombre: 'Conciliación en línea',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.pasarela.conciliar', 'pag.pasarela.ver'),
    pantalla: () => <Conciliacion />,
  },
  {
    ruta: 'reportes',
    nombre: 'Reportes financieros',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.reportes.ver'),
    pantalla: () => <ReportesFinancieros />,
  },
  {
    ruta: 'facturacion',
    nombre: 'Facturación electrónica',
    grupo: 'Pagos',
    visible: (c) => moduloActivo(c, 'PAG') && puede(c, 'pag.comprobantes.ver'),
    pantalla: () => <ProveedoresFacturacion />,
  },
  {
    ruta: 'comunicados',
    nombre: 'Comunicados',
    grupo: 'Comunicación',
    visible: (c) => moduloActivo(c, 'COM') && puede(c, 'com.comunicados.ver'),
    pantalla: () => <Comunicados />,
  },
  {
    ruta: 'mensajes',
    nombre: 'Mensajes',
    grupo: 'Comunicación',
    visible: (c) => moduloActivo(c, 'COM') && (c.roles.includes('apoderado') || puede(c, 'com.mensajes.ver')),
    pantalla: () => <Mensajes />,
  },
  {
    ruta: 'agenda',
    nombre: 'Agenda',
    grupo: 'Comunicación',
    visible: (c) => moduloActivo(c, 'COM'),
    pantalla: () => <Agenda />,
  },
  {
    ruta: 'citas',
    nombre: 'Citas',
    grupo: 'Comunicación',
    visible: (c) => moduloActivo(c, 'COM'),
    pantalla: () => <Citas />,
  },
  {
    ruta: 'reclamos',
    nombre: 'Libro de reclamaciones',
    grupo: 'Comunicación',
    visible: (c) => moduloActivo(c, 'COM') && puede(c, 'com.reclamaciones.ver'),
    pantalla: () => <Reclamos />,
  },
  {
    ruta: 'bitacora',
    nombre: 'Bitácora de avisos',
    grupo: 'Comunicación',
    visible: (c) => moduloActivo(c, 'COM') && puede(c, 'com.comunicados.ver'),
    pantalla: () => <Bitacora />,
  },
  {
    ruta: 'mis-convenios',
    nombre: 'Mis convenios',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado') && moduloActivo(c, 'PAG'),
    pantalla: () => <MisConvenios />,
  },
  {
    ruta: 'mis-comunicados',
    nombre: 'Comunicados',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado') && moduloActivo(c, 'COM'),
    pantalla: () => <MisComunicados />,
  },
  {
    ruta: 'familia',
    nombre: 'Mis hijos',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado'),
    pantalla: () => <MisHijos />,
  },
  {
    ruta: 'mis-notas',
    nombre: 'Notas y libretas',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado') && moduloActivo(c, 'CAL'),
    pantalla: () => <MisNotas />,
  },
  {
    ruta: 'ratificacion',
    nombre: 'Ratificar matrícula',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado') && moduloActivo(c, 'ADM'),
    pantalla: () => <Ratificacion />,
  },
  {
    ruta: 'mis-pagos',
    nombre: 'Mis pagos',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado') && moduloActivo(c, 'PAG'),
    pantalla: () => <MisPagos />,
  },
  {
    ruta: 'pago-simulado',
    nombre: 'Pago en línea',
    grupo: 'Familia',
    visible: (c) => c.roles.includes('apoderado'),
    pantalla: () => <PagoSimulado orden={decodeURIComponent(window.location.hash.split('/')[2] ?? '')} />,
    oculta: true,
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
  const accesos = PAGINAS.filter((p) => p.ruta !== 'inicio' && !p.oculta && p.visible(colegio));
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
  const grupos = [...new Set(visibles.filter((p) => !p.oculta).map((p) => p.grupo))];

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
                .filter((p) => p.grupo === g && !p.oculta)
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
      <main className="contenido">
        <Suspense fallback={<p className="aviso-carga" role="status">Cargando…</p>}>{actual.pantalla()}</Suspense>
      </main>
    </div>
  );
}
