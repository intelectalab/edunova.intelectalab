# Moodle conectado (S30 · EDUN-143 a 150)

Edunova es la fuente de los datos y Moodle es el aula: alumnos, docentes, cursos, retiros y
traslados viajan de Edunova a Moodle; las notas vuelven a Edunova como pendientes y el docente
las aprueba antes de que lleguen al registro oficial.

## 1. Preparar Moodle (del colegio o alojado)

1. **Servicio web.** Activa los servicios web y el protocolo REST, crea un servicio externo con estas
   funciones y genera un token para un usuario administrador del servicio:
   `core_webservice_get_site_info`, `core_course_create_categories`, `core_course_create_courses`,
   `core_user_get_users_by_field`, `core_user_create_users`, `core_user_update_users`,
   `enrol_manual_enrol_users`, `gradereport_user_get_grade_items`.
2. **Ingreso único.** Copia `auth_edunova/` a `{moodle}/auth/edunova`, instala el plugin desde
   Administración del sitio → Notificaciones y actívalo en Extensiones → Autenticación.
3. En Edunova (web de gestión → Moodle) pega la dirección y el token, pulsa **Probar conexión** y
   luego **Generar secreto de ingreso único**. Copia ese secreto en la configuración del plugin
   (se muestra una sola vez; si lo pierdes, genera otro).

## 2. Qué hace la sincronización

- Cada 15 minutos (`mdl:sincronizar`) revisa los colegios activos según su frecuencia:
  - crea un curso por carga docente del año, en la categoría «Año · Grado»;
  - crea los usuarios de alumnos y docentes con cuenta (usuario `edu{colegio}-{id}`, auth `edunova`,
    sin contraseña) y los matricula (alumno = rol 5, docente = rol 3);
  - suspende en Moodle a quien se retiró o trasladó (no lo borra);
  - trae los ítems de calificación como notas pendientes, convertidas a AD/A/B/C con la tabla del colegio.
- Cada paso queda en la **bitácora** con sus errores. Con errores y nada sincronizado, la conexión
  pasa a «error»; se puede pausar y reanudar.
- Sin un Moodle real (o fuera de producción) el conector responde **simulado** (`MOODLE_SIMULADO`).

## 3. Alojar el Moodle del colegio (Edunova)

`docker-compose.yml` levanta una instancia por colegio (PostgreSQL + Moodle 4.5) con el plugin ya
montado, escuchando solo en `127.0.0.1:${PUERTO}` para publicarla detrás del proxy con HTTPS.

```bash
cp .env.example .env    # COLEGIO, DOMINIO, PUERTO, DB_CLAVE, ADMIN_CLAVE, ADMIN_CORREO
docker compose --env-file .env up -d
```

`respaldo.sh` hace el respaldo diario cifrado (base y moodledata) y lo sube con rclone; prográmalo
con un timer de systemd igual que los respaldos de Edunova (`infra/vps/README.md`). En la consola de
la plataforma marca el colegio como «alojado por Edunova».
