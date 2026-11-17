// Ayuda por pantalla: para qué sirve, qué se puede hacer y qué no (y por qué). Se abre con el
// botón «Ayuda» de cada pantalla. Los textos describen las reglas que aplica el sistema.

export type Ayuda = { para: string; puedes: string[]; noPuedes?: string[]; consejo?: string };

export const AYUDA: Record<string, Ayuda> = {
  inicio: {
    para: 'Resumen del día del colegio y accesos rápidos según tu rol.',
    puedes: ['Ver los indicadores principales (matriculados, asistencia, cobranza).', 'Entrar a cualquier pantalla desde el menú de la izquierda.'],
    consejo: 'El menú muestra solo lo que tu rol puede usar. Si te falta una opción, pídela a dirección.',
  },
  configuracion: {
    para: 'Datos del colegio: nombre, logo, colores, año escolar y reglas generales (hora de entrada, tolerancia).',
    puedes: ['Cambiar el logo y el color: se aplica en la web, la app y los documentos.', 'Configurar la hora de entrada y la tolerancia para tardanzas.', 'Crear el año escolar y sus periodos.'],
    noPuedes: ['Cambiar el RUC ni el plan contratado: eso lo hace Edunova (pídelo en Soporte).'],
  },
  personal: {
    para: 'Registro del personal del colegio: docentes, tutores, administrativos y auxiliares.',
    puedes: ['Agregar personas, su cargo y datos de contacto.', 'Darles acceso al sistema con un usuario (Usuarios).'],
    noPuedes: ['Borrar a alguien con historial (notas, asistencia): se desactiva para conservar los registros.'],
  },
  usuarios: {
    para: 'Quién entra al sistema y con qué rol.',
    puedes: ['Invitar usuarios por correo o WhatsApp (crean su contraseña con el enlace).', 'Asignar o quitar roles y desactivar cuentas.'],
    noPuedes: ['Ver ni cambiar contraseñas: cada persona la crea o la recupera desde el ingreso.', 'Quitarte a ti mismo el último rol de dirección.'],
    consejo: 'Dirección, promotoría y tesorería deben activar el segundo factor (código de 6 dígitos).',
  },
  auditoria: {
    para: 'Registro de quién hizo qué y cuándo (cambios de notas, pagos, anulaciones, accesos).',
    puedes: ['Filtrar por persona, fecha o tipo de acción.', 'Exportar el registro.'],
    noPuedes: ['Editar ni borrar registros: la auditoría no se puede modificar.'],
  },
  plan: {
    para: 'Niveles, grados, secciones, áreas y competencias del año escolar.',
    puedes: ['Crear secciones y asignar su tutor.', 'Revisar las áreas y competencias del currículo nacional.'],
    noPuedes: ['Quitar una sección que ya tiene alumnos matriculados.'],
  },
  horarios: {
    para: 'Qué docente dicta cada curso en cada sección y el horario semanal.',
    puedes: ['Asignar la carga docente y armar el horario.'],
    noPuedes: ['Poner a un docente en dos aulas a la misma hora: el sistema lo marca como cruce.'],
  },
  notas: {
    para: 'Registro de notas por competencia (AD, A, B, C) de cada curso y periodo.',
    puedes: ['Crear actividades y poner notas por alumno.', 'Usar el promedio sugerido por actividades.', 'Guardar a medida que avanzas.'],
    noPuedes: ['Cambiar notas de un periodo cerrado: dirección debe reabrirlo.', 'Ver cursos que no son tuyos (si eres docente).'],
    consejo: 'Espera a que cargue la lista del curso elegido antes de escribir.',
  },
  'cierre-notas': {
    para: 'Revisar el avance de notas por sección y cerrar el periodo.',
    puedes: ['Ver qué cursos faltan completar y enviar recordatorios.', 'Cerrar el periodo (bloquea las notas) y reabrirlo con motivo.'],
    noPuedes: ['Cerrar si faltan notas, salvo que lo confirmes expresamente.'],
  },
  asistencia: {
    para: 'Asistencia diaria por sección: presentes, tardanzas, faltas y justificadas.',
    puedes: ['Marcar a todos presentes y cambiar solo a quien faltó o llegó tarde.', 'Ver la asistencia de días anteriores.'],
    noPuedes: ['Registrar días futuros.', 'Pisar un registro más reciente (gana el último registrado).'],
    consejo: 'Las faltas y tardanzas avisan a la familia al momento. Desde la app se puede tomar sin señal.',
  },
  tramites: {
    para: 'Trámites que piden las familias (constancias, certificados): bandeja por etapa, catálogo con requisitos y costo, y plantillas de los documentos.',
    puedes: [
      'Observar un trámite con un comentario: la familia lo corrige desde la app y vuelve a revisión.',
      'Pasar a firma y firmar varios a la vez: se genera el PDF con QR y la familia lo descarga en la app.',
      'Crear plantillas con variables ({alumno.nombre}, {seccion}, {fecha_larga}…) y verlas con los datos reales de un alumno.',
      'Anular un documento emitido: no se borra y la verificación pública dice que ya no es válido.',
    ],
    noPuedes: [
      'Firmar un trámite con costo mientras el pago esté pendiente.',
      'Firmar una constancia de no adeudo si el alumno tiene cargos vencidos.',
      'Cambiar un documento ya emitido: editar la plantilla crea una versión nueva para los siguientes.',
    ],
    consejo: 'El semáforo marca el plazo en días hábiles: verde en plazo, ámbar vence hoy o mañana, rojo vencido.',
  },
  enfermeria: {
    para: 'Fichas de salud, atenciones del tópico y campañas con autorización de las familias.',
    puedes: ['Mantener alergias, condiciones, medicación y el contacto de emergencia.', 'Ver las atenciones del día (se registran desde la app).', 'Crear una campaña: las familias la autorizan en la app y tienes la lista del día.'],
    noPuedes: ['Ver una ficha sin que quede registrado: cada consulta va a la auditoría (Ley 29733).'],
  },
  'movilidad-familia': {
    para: 'Dónde está la movilidad de tu hijo y cuánto falta para su paradero.',
    puedes: ['Ver la llegada estimada y abrir la ubicación en el mapa durante el recorrido.', 'Ver a qué hora subió o bajó.'],
    noPuedes: ['Ver la ubicación fuera del recorrido ni de otras rutas (privacidad).'],
    consejo: 'Te llega un aviso cuando la unidad está a menos de 500 m de tu paradero.',
  },
  'salud-familia': {
    para: 'Ficha de salud de tus hijos para enfermería.',
    puedes: ['Actualizar alergias, medicación y el contacto de emergencia.'],
  },
  'notas-moodle': {
    para: 'Notas que llegan de Moodle, ya convertidas a AD/A/B/C (o 0–20) con la tabla del colegio.',
    puedes: ['Elegir la competencia y el periodo y aprobarlas: pasan al registro oficial.', 'Descartar las que no correspondan.'],
    noPuedes: ['Aprobar en un periodo cerrado.', 'Ver notas de cursos que no dictas (salvo coordinación y dirección).'],
  },
  'entrar-moodle': {
    para: 'Abrir el Moodle del colegio con tu cuenta de Edunova.',
    puedes: ['Entrar sin escribir otra contraseña (el enlace vence en un minuto).'],
    noPuedes: ['Entrar si tu cuenta todavía no se creó en Moodle: se crea en la siguiente sincronización.'],
  },
  aula: {
    para: 'Aula virtual del colegio: cursos creados desde la carga docente, con materiales, tareas, cuestionarios, foros y clases en vivo.',
    puedes: [
      'Docente: organizar unidades, subir materiales (PDF, video, enlaces, lecturas), publicar tareas y cuestionarios y revisar entregas.',
      'Generar preguntas con IA para el banco: llegan marcadas y no se usan hasta que las apruebas.',
      'Seguir a cada alumno: avance, tiempo, última actividad, notas y observaciones; avisar a la familia.',
      'Alumno: avanzar en orden, ver videos, entregar tareas y rendir cuestionarios con corrección automática.',
    ],
    noPuedes: ['Adelantar un video si el curso no lo permite.', 'Usar preguntas de IA sin revisarlas.', 'Alojar la clase en vivo: se enlaza a Meet, Zoom o Teams.'],
    consejo: 'Las notas del aula son sugerencias: pásalas al registro oficial en Notas.',
  },
  'tareas-hijos': {
    para: 'Tareas que los docentes publican en el aula para tus hijos.',
    puedes: ['Ver la fecha límite, si ya entregó y la nota.'],
  },
  documentos: {
    para: 'Documentos institucionales (reglamento, PEI, normas), autorizaciones que firman los padres y vencimientos de los expedientes.',
    puedes: [
      'Publicar una versión nueva: la anterior queda como histórica y las familias ven solo la vigente.',
      'Pedir confirmación de lectura y recordar a quienes faltan.',
      'Pedir una autorización a una o varias secciones y seguir quién aceptó, quién no y quién falta.',
      'Ver los documentos de alumnos y personal que vencen en los próximos 30 días.',
    ],
    noPuedes: ['Cambiar una firma de autorización: se anula y la familia vuelve a firmar.', 'Pedir autorizaciones a secciones que no tienes a tu cargo (docentes).'],
  },
  'salidas-anticipadas': {
    para: 'Salidas antes de la hora que pide la familia desde la app (o que registra secretaría).',
    puedes: ['Autorizar o no autorizar (con la explicación para la familia).', 'Anular una salida que ya no va.'],
    noPuedes: ['Entregar al alumno a alguien que no esté autorizado: portería lo verifica con el DNI.'],
    consejo: 'Al autorizarla, portería la ve en su lista del turno; cuando verifica a quien recoge, la salida queda como «Salió».',
  },
  visitas: {
    para: 'Registro de quién entra al colegio sin ser alumno ni personal.',
    puedes: ['Registrar el ingreso con nombre, documento, a quién visita y el motivo.', 'Marcar la salida.'],
    consejo: 'El documento se muestra con los primeros dígitos ocultos para cuidar los datos personales.',
  },
  'mis-documentos': {
    para: 'Reglamento y normas del colegio, y autorizaciones de tus hijos.',
    puedes: ['Leer y confirmar la lectura.', 'Autorizar o no una salida, paseo o campaña.'],
    noPuedes: ['Cambiar una respuesta ya firmada: pídelo al colegio.'],
  },
  recojo: {
    para: 'Quién puede recoger a tus hijos y salidas antes de hora.',
    puedes: ['Agregar personas con su DNI, parentesco y vigencia, y desactivarlas.', 'Pedir una salida anticipada y elegir quién recoge.'],
    noPuedes: ['Que portería entregue a tu hijo a alguien que no esté en la lista o cuyo permiso venció.'],
  },
  'mis-tramites': {
    para: 'Pedir constancias y certificados del colegio y descargarlos firmados.',
    puedes: ['Adjuntar los requisitos como foto o PDF.', 'Responder si el colegio observa tu trámite.', 'Descargar el documento: lleva un código y un QR para verificarlo.'],
    noPuedes: ['Recibir un trámite con costo sin pagarlo: el cargo aparece en Mis pagos.'],
  },
  movilidad: {
    para: 'Rutas de movilidad escolar por turno, con sus paraderos, alumnos, unidades y personal.',
    puedes: [
      'Registrar unidades con sus vencimientos (SOAT, autorización, revisión técnica): avisa 30 días antes.',
      'Registrar conductores y auxiliares y enlazarlos a su usuario para que usen la app.',
      'Crear rutas, ordenar sus paraderos y asignar alumnos a cada paradero.',
    ],
    noPuedes: ['Asignar más alumnos que los asientos de la unidad.', 'Quitar un paradero que todavía tiene alumnos.', 'Asignar alumnos sin matrícula en el año vigente.'],
    consejo: 'En la app, el auxiliar inicia el recorrido y marca quién sube y baja, también sin señal: la familia recibe el aviso.',
  },
  salidas: {
    para: 'Salidas del colegio verificadas en portería o en el tópico, con quién recogió a cada alumno.',
    puedes: ['Ver las salidas de cualquier día.'],
    noPuedes: ['Registrar salidas desde aquí: se registran en la app de portería o de enfermería, verificando el documento de quien recoge.'],
    consejo: 'Las personas autorizadas para recoger se cargan en la ficha de cada alumno.',
  },
  justificaciones: {
    para: 'Justificaciones de inasistencia que envían las familias, con su sustento.',
    puedes: ['Ver el motivo y la foto o PDF del sustento.', 'Aceptar: las faltas (F) de esos días pasan a justificadas (J).', 'Rechazar con una explicación para la familia.'],
    noPuedes: ['Resolver justificaciones de secciones donde no eres tutor (salvo coordinación o dirección).', 'Cambiar una justificación ya resuelta.'],
  },
  tutoria: {
    para: 'Conducta, méritos y entrevistas de los alumnos de tu tutoría.',
    puedes: ['Registrar incidencias y méritos, con aviso a la familia si lo eliges.', 'Anotar entrevistas (son confidenciales).'],
    noPuedes: ['Ver entrevistas confidenciales de otros tutores.'],
  },
  alertas: {
    para: 'Alumnos en riesgo por faltas, notas bajas o conducta, calculado cada mañana.',
    puedes: ['Atender la alerta y registrar el seguimiento.', 'Cerrarla cuando se resuelva.'],
  },
  libretas: {
    para: 'Libretas de notas, acta y cuadro de méritos del periodo.',
    puedes: ['Generar y publicar libretas: la familia las ve en la app.', 'Descargar el acta y el cuadro de méritos en PDF o Excel.'],
    noPuedes: ['Ocultar la libreta por deuda: la ley no lo permite.'],
  },
  alumnos: {
    para: 'Ficha de cada alumno: datos, apoderados, matrícula, carné y documentos.',
    puedes: ['Registrar alumnos y matricularlos (se generan sus cargos).', 'Agregar apoderados e invitarlos a la app.', 'Imprimir el carné con QR.'],
    noPuedes: ['Borrar un alumno con historial: se retira para conservar sus datos.'],
  },
  admision: {
    para: 'Postulantes del próximo año: evaluación, vacantes y paso a matrícula.',
    puedes: ['Registrar postulantes y su resultado.', 'Pasar a matrícula a los admitidos.'],
  },
  condiciones: {
    para: 'Condiciones económicas del año (montos y reglas) que la familia acepta al matricular.',
    puedes: ['Publicar una versión nueva; la anterior se conserva como prueba.'],
    noPuedes: ['Editar una versión ya publicada: se publica otra.'],
  },
  consentimientos: {
    para: 'Autorizaciones de las familias (uso de imagen, datos de salud, etc.).',
    puedes: ['Ver quién autorizó qué y cuándo.'],
    noPuedes: ['Marcar un consentimiento en nombre de la familia.'],
  },
  'siagie-archivos': {
    para: 'Intercambio con el SIAGIE: importar la nómina y llenar las plantillas de notas.',
    puedes: ['Subir la nómina y revisar los errores por fila.', 'Validar notas antes de exportar (con enlace a la celda).', 'Descargar la plantilla llena o un ZIP de varias secciones.'],
    noPuedes: ['Enviar directamente al SIAGIE: se sube el archivo en el portal del Minedu.'],
  },
  siagie: {
    para: 'Alumnos con datos pendientes para el SIAGIE (código, documento, etc.).',
    puedes: ['Completar lo que falta desde la ficha del alumno.'],
  },
  caja: {
    para: 'Cobros en ventanilla con uno o varios medios (efectivo, Yape, tarjeta…).',
    puedes: ['Abrir la caja, cobrar cargos y emitir boleta o factura.', 'Cerrar con arqueo y justificar diferencias.'],
    noPuedes: ['Cobrar sin caja abierta.', 'Cobrar más que el saldo de un cargo ni repetir un cargo en el mismo cobro.'],
  },
  comprobantes: {
    para: 'Boletas, facturas y notas de crédito electrónicas.',
    puedes: ['Reenviar un comprobante a la familia.', 'Emitir nota de crédito con motivo (anula el pago).', 'Reintentar los que quedaron pendientes.'],
    noPuedes: ['Borrar un comprobante emitido: se anula con nota de crédito.'],
  },
  conceptos: {
    para: 'Conceptos de cobro (matrícula, pensiones, otros) y su cronograma de vencimientos.',
    puedes: ['Definir montos, fechas de vencimiento y mora.'],
    noPuedes: ['Cambiar montos de cargos ya emitidos: se ajustan con descuentos o anulaciones.'],
  },
  becas: {
    para: 'Descuentos y becas por alumno, con aprobación.',
    puedes: ['Solicitar y aprobar beneficios; se aplican a los cargos que vienen.'],
  },
  pasarela: {
    para: 'Cuenta del colegio en la pasarela de pagos (pagos en línea de las familias).',
    puedes: ['Conectar la cuenta, elegir los medios (tarjeta, Yape…) y probarla.'],
    noPuedes: ['Ver las claves guardadas: se guardan cifradas y nunca se muestran.'],
  },
  morosidad: {
    para: 'Familias con deuda por tramos de atraso y su historial de cobranza.',
    puedes: ['Registrar gestiones y compromisos de pago.', 'Enviar recordatorios.'],
  },
  convenios: {
    para: 'Convenios de fraccionamiento de deuda en cuotas.',
    puedes: ['Simular y crear el convenio; la familia lo firma en la app.', 'Aprobar (dirección) y seguir las cuotas.'],
    noPuedes: ['Incluir un cargo que ya está en otro convenio.', 'Aprobar sin la firma de la familia.'],
  },
  recaudacion: {
    para: 'Pagos en el banco (BCP, Interbank) por código de recaudación.',
    puedes: ['Generar el archivo de deudas para el banco.', 'Subir el archivo de pagos: se concilia y emite las boletas.'],
    noPuedes: ['Aplicar un pago a un cargo anulado: queda como «sin coincidencia» para revisar.'],
  },
  conciliacion: {
    para: 'Pagos en línea comparados con el reporte de abonos de la pasarela.',
    puedes: ['Subir el reporte y ver diferencias de monto o comisión.', 'Resolver excedentes (pagos de un cargo ya pagado): devolver o aplicar.'],
  },
  reportes: {
    para: 'Ingresos por mes, concepto y nivel, y proyección de cobranza.',
    puedes: ['Exportar a Excel.'],
  },
  facturacion: {
    para: 'Proveedor de facturación electrónica del colegio (series y credenciales).',
    puedes: ['Configurar el proveedor y probar la conexión.'],
    noPuedes: ['Ver las credenciales guardadas.'],
  },
  comunicados: {
    para: 'Comunicados a familias por colegio, nivel, grado o sección.',
    puedes: ['Programar el envío, pedir confirmación de lectura y ver quién leyó.', 'Anular un comunicado con motivo.'],
    noPuedes: ['Editar un comunicado ya enviado.'],
  },
  mensajes: {
    para: 'Mensajes entre familias y el colegio, dentro del horario de atención.',
    puedes: ['Responder conversaciones y ver las pendientes.'],
    consejo: 'Fuera del horario, el mensaje se entrega al empezar la atención.',
  },
  agenda: {
    para: 'Eventos del calendario escolar con recordatorio a las familias.',
    puedes: ['Crear eventos por colegio, nivel o sección.'],
  },
  citas: {
    para: 'Citas entre familias y docentes o tutores.',
    puedes: ['Ofrecer horarios y confirmar o reprogramar citas.'],
  },
  reclamos: {
    para: 'Libro de reclamaciones del colegio (plazo legal de respuesta).',
    puedes: ['Responder dentro del plazo y adjuntar la respuesta.'],
    noPuedes: ['Borrar un reclamo: es un registro legal.'],
  },
  bitacora: {
    para: 'Registro de avisos enviados (app, correo, WhatsApp) y su costo.',
    puedes: ['Ver si un aviso se entregó o falló.'],
  },
  'mis-convenios': {
    para: 'Tus convenios de pago en cuotas.',
    puedes: ['Firmar el convenio en la app y pagar cada cuota en línea.'],
  },
  'mis-comunicados': {
    para: 'Comunicados del colegio.',
    puedes: ['Leerlos y confirmar la lectura cuando el colegio lo pide.'],
  },
  familia: {
    para: 'Datos de tus hijos y autorizaciones.',
    puedes: ['Revisar sus datos, sección y carné.', 'Dar o retirar autorizaciones (uso de imagen, salud).'],
  },
  justificar: {
    para: 'Justificar una inasistencia de tu hijo.',
    puedes: ['Enviar el motivo y la foto o PDF del sustento (hasta 5 MB).', 'Ver si el tutor la aceptó o rechazó.'],
    noPuedes: ['Justificar días de hace más de 30 días.', 'Enviar dos justificaciones para los mismos días mientras una está pendiente.'],
  },
  'mis-notas': {
    para: 'Notas por competencia y libretas de tus hijos.',
    puedes: ['Ver las notas publicadas y descargar la libreta.'],
  },
  ratificacion: {
    para: 'Ratificar la matrícula del próximo año.',
    puedes: ['Aceptar las condiciones económicas y pagar la matrícula: con el pago queda confirmada.'],
  },
  'mis-pagos': {
    para: 'Estado de cuenta, pagos en línea y comprobantes.',
    puedes: ['Pagar cargos vencidos o del mes en línea.', 'Descargar tus boletas.'],
    noPuedes: ['Pagar cuotas futuras que aún no se emiten.'],
  },
  'pago-simulado': {
    para: 'Página de pago de prueba (servidor de pruebas): no se cobra dinero real.',
    puedes: ['Simular un pago aprobado o rechazado.'],
  },
  moodle: {
    para: 'Conexión con el Moodle del colegio.',
    puedes: ['Configurar y probar la conexión.'],
  },
  soporte: {
    para: 'Pedir ayuda a Edunova (tickets).',
    puedes: ['Abrir un ticket, responder y calificar la atención.'],
  },
  perfil: {
    para: 'Tus datos y la seguridad de tu cuenta.',
    puedes: ['Cambiar tu contraseña y activar el segundo factor.', 'Ver tus sesiones abiertas.'],
  },
};
