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
