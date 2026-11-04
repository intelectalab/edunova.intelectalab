# Módulos de la API

Cada módulo de negocio vive en su propia carpeta con el código del kit (`PLA`, `SEG`, `ADM`, `COM`, `PAG`, `CAL`, `SIA`, …):

```
app/Modulos/<Codigo>/
  Http/Controllers   controladores delgados
  Http/Requests      validación (FormRequest)
  Models             modelos con el trait PerteneceAInstitucion
  Services           reglas de negocio
  Jobs               procesos en cola (idempotentes)
  Policies           permisos por propiedad
```

Las convenciones completas están en `docs/13-convenciones.md` del kit.
