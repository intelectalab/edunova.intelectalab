SET client_min_messages = warning;
-- Sprint 6 · Conversión y legal del sitio público.
--   EDUN-267 (SIT-002): la demo se agenda solo en días hábiles desde mañana (hora de Lima).
--   EDUN-287 (SIT-053): políticas con marca de borrador y textos base pendientes de revisión legal.
--   EDUN-291 (SIT-054) y EDUN-306 (SIT-055): consentimientos de formularios y de cookies.
-- Se carga después de 01_cms_esquema.sql (migración 0005 y infra/pruebas-cms.sh).

-- ---------------------------------------------------------------------
-- Demo: desde mañana. El CHECK de la tabla ya exige lunes a viernes; «desde mañana» depende
-- de la fecha actual, así que va en un trigger (un CHECK no puede usar now()).
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION cms.validar_fecha_demo() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.demo_fecha IS NOT NULL AND NEW.demo_fecha <= (now() AT TIME ZONE 'America/Lima')::date THEN
    RAISE EXCEPTION 'La demo se agenda desde mañana' USING ERRCODE = 'check_violation';
  END IF;
  IF (NEW.demo_fecha IS NULL) <> (NEW.demo_hora IS NULL) THEN
    RAISE EXCEPTION 'La demo necesita fecha y hora' USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS leads_fecha_demo ON cms.leads;
CREATE TRIGGER leads_fecha_demo BEFORE INSERT ON cms.leads FOR EACH ROW EXECUTE FUNCTION cms.validar_fecha_demo();

-- ---------------------------------------------------------------------
-- Consentimientos: categorías de cookies conocidas y siempre con «esenciales».
-- ---------------------------------------------------------------------
ALTER TABLE cms.consentimientos DROP CONSTRAINT IF EXISTS consentimientos_categorias;
ALTER TABLE cms.consentimientos ADD CONSTRAINT consentimientos_categorias CHECK (
  (tipo = 'formulario' AND categorias IS NULL)
  OR (tipo = 'cookies' AND categorias IS NOT NULL AND 'esenciales' = ANY (categorias)
      AND categorias <@ ARRAY['esenciales', 'analitica', 'marketing', 'personalizacion']::text[])
);

-- ---------------------------------------------------------------------
-- Políticas: marca de borrador (el texto definitivo lo revisa un abogado, D-10).
-- ---------------------------------------------------------------------
ALTER TABLE cms.politicas ADD COLUMN IF NOT EXISTS borrador boolean NOT NULL DEFAULT false;
CREATE INDEX IF NOT EXISTS politicas_vigencia ON cms.politicas (tipo, vigente_desde DESC, created_at DESC);

-- Textos base. {{razon_social}}, {{ruc}}, {{direccion}} y {{email_contacto}} se reemplazan con
-- cms.config al servir la política (sin dato: «[A CONFIRMAR]»). Solo se cargan si el tipo aún no
-- tiene ninguna versión, para no pisar textos ya revisados.
INSERT INTO cms.politicas (tipo, version, contenido, vigente_desde, borrador)
SELECT v.tipo, '1.0', v.contenido, current_date, true
FROM (VALUES
('privacidad', $p$<p>{{razon_social}} (RUC {{ruc}}), con domicilio en {{direccion}}, es titular del banco de datos personales de contactos comerciales y responsable de su tratamiento, conforme a la Ley N.° 29733, Ley de Protección de Datos Personales, y su Reglamento aprobado por D.S. N.° 003-2013-JUS.</p>
<h3>1. Datos que recopilamos</h3>
<p>Nombre, cargo, correo electrónico, teléfono, nombre del colegio y número aproximado de alumnos que nos entregas al solicitar una demo o dejar tus datos en el sitio; y datos de navegación (páginas visitadas, origen de la visita) mediante cookies, solo según tus preferencias.</p>
<h3>2. Finalidad</h3>
<p>Coordinar y realizar la demo, enviarte información comercial de Edunova, preparar cotizaciones y medir el uso del sitio para mejorarlo. No vendemos ni cedemos tus datos a terceros con fines comerciales.</p>
<h3>3. Encargados del tratamiento</h3>
<p>Proveedores de infraestructura, de correo electrónico y de analítica web que tratan los datos por cuenta de Edunova y bajo contrato de confidencialidad.</p>
<h3>4. Plazo de conservación</h3>
<p>Mientras exista una relación comercial o hasta que solicites la eliminación de tus datos.</p>
<h3>5. Tus derechos (ARCO)</h3>
<p>Puedes ejercer tus derechos de acceso, rectificación, cancelación y oposición escribiendo a {{email_contacto}} con el asunto «Derechos ARCO» y copia de tu documento de identidad. Si no recibes respuesta, puedes acudir a la Autoridad Nacional de Protección de Datos Personales del Ministerio de Justicia y Derechos Humanos.</p>
<h3>6. Datos de alumnos y familias</h3>
<p>Los datos de alumnos, apoderados y personal que cada colegio registra en la plataforma pertenecen al colegio, que es su titular y responsable. Edunova actúa como encargado del tratamiento según el contrato de servicio.</p>
<h3>7. Registro de tu consentimiento</h3>
<p>Cuando aceptas esta política en un formulario guardamos la versión aceptada, la fecha, la dirección IP y el navegador, como prueba de tu consentimiento.</p>$p$),
('terminos', $p$<p>Estos términos resumen las condiciones del servicio Edunova. El detalle aplicable a cada colegio consta en su contrato de servicio.</p>
<h3>1. Objeto</h3>
<p>Edunova brinda acceso, bajo modalidad de suscripción (SaaS), a una plataforma web y apps móviles de gestión escolar con los módulos incluidos en el plan contratado.</p>
<h3>2. Planes y pagos</h3>
<p>Los precios publicados son referenciales, no incluyen IGV y están sujetos a validación comercial. La tarifa se determina por rango de alumnos y módulos contratados, y se factura de forma mensual o anual según lo acordado.</p>
<h3>3. Implementación y soporte</h3>
<p>Incluye configuración inicial, migración asistida de datos, capacitación del personal y soporte en el horario publicado en el sitio. Los niveles de servicio se fijan en el contrato.</p>
<h3>4. Obligaciones del colegio</h3>
<p>Usar la plataforma conforme a la ley, mantener la confidencialidad de sus credenciales y contar con el consentimiento de las familias para el tratamiento de los datos que registre.</p>
<h3>5. Propiedad de la información</h3>
<p>La información registrada por el colegio le pertenece. Al terminar el servicio, el colegio puede solicitar la exportación de sus datos.</p>
<h3>6. Reclamos</h3>
<p>Puedes presentar quejas o reclamos en nuestro <a href="/libro-de-reclamaciones">Libro de Reclamaciones virtual</a>, conforme a la Ley N.° 29571.</p>$p$),
('seguridad', $p$<p>La información de un colegio es sensible: notas, datos de menores de edad y pagos de las familias. Estas son las medidas con las que la protegemos, conforme a la Ley N.° 29733 y la Directiva de Seguridad de la Información de la Autoridad Nacional de Protección de Datos Personales.</p>
<h3>1. Aislamiento por colegio</h3>
<p>Cada colegio solo accede a su propia información: la separación se aplica en la base de datos mediante seguridad a nivel de fila.</p>
<h3>2. Roles y permisos</h3>
<p>Roles con permisos específicos: cada usuario ve únicamente lo que necesita para su función.</p>
<h3>3. Cifrado</h3>
<p>Todas las conexiones al sitio, a la plataforma y a las apps van cifradas (HTTPS/TLS).</p>
<h3>4. Trazabilidad</h3>
<p>Registro de auditoría de las acciones relevantes.</p>
<h3>5. Pagos en línea</h3>
<p>Los pagos con tarjeta se procesan en la pasarela de pagos; Edunova no almacena datos de tarjetas.</p>$p$),
('cookies', $p$<p>Usamos cookies y tecnologías similares para que el sitio funcione y, solo si lo aceptas, para medir su uso y mostrarte publicidad relevante. Puedes cambiar tu elección cuando quieras desde «Preferencias de cookies» en el pie de página.</p>
<h3>1. Esenciales</h3>
<p>Necesarias para el funcionamiento y la seguridad del sitio, y para recordar tu elección de cookies. Siempre están activas.</p>
<h3>2. Analítica</h3>
<p>Nos dicen qué páginas se visitan y de dónde llegan las visitas, para mejorar el sitio (por ejemplo, Google Analytics).</p>
<h3>3. Marketing</h3>
<p>Permiten medir campañas y mostrar anuncios de Edunova en otros sitios.</p>
<h3>4. Personalización</h3>
<p>Recuerdan preferencias para adaptar el contenido del sitio.</p>
<h3>5. Registro de tu elección</h3>
<p>Guardamos tu elección en tu navegador y una copia con la fecha, la versión de esta política, la dirección IP y el navegador, como prueba de tu consentimiento.</p>$p$)
) AS v (tipo, contenido)
WHERE NOT EXISTS (SELECT 1 FROM cms.politicas p WHERE p.tipo = v.tipo);
