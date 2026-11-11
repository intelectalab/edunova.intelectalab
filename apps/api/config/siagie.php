<?php

/*
|--------------------------------------------------------------------------
| Configuración de integración con SIAGIE (MINEDU)
|--------------------------------------------------------------------------
| El SIAGIE no tiene API: la integración es por archivos Excel.
| Los nombres de columna se comparan NORMALIZADOS (mayúsculas, sin tildes,
| sin signos), por eso aquí se escriben en esa forma.
|
| IMPORTANTE: estos alias son un punto de partida. Deben confirmarse con
| plantillas reales descargadas del SIAGIE por un director.
*/

return [

    'disco' => env('SIAGIE_DISCO', 'objetos'),

    // Tamaño máximo del Excel (MB) y del lote de plantillas.
    'max_mb' => 10,

    'nomina' => [
        // Cuántas filas superiores se revisan para encontrar la fila de encabezados.
        'filas_busqueda_encabezado' => 30,

        // Tamaño de bloque al leer el Excel (memoria constante).
        'chunk' => 200,

        // Campo interno => alias posibles del encabezado en el Excel del SIAGIE.
        'columnas' => [
            'codigo_estudiante' => ['CODIGO DEL ESTUDIANTE', 'CODIGO ESTUDIANTE', 'COD ESTUDIANTE', 'CODIGO DE ESTUDIANTE'],
            'tipo_documento' => ['TIPO DE DOCUMENTO', 'TIPO DOCUMENTO', 'TIPO DOC'],
            'numero_documento' => ['NUMERO DE DOCUMENTO', 'NRO DOCUMENTO', 'N DOCUMENTO', 'DNI', 'DOCUMENTO'],
            'apellido_paterno' => ['APELLIDO PATERNO'],
            'apellido_materno' => ['APELLIDO MATERNO'],
            'nombres' => ['NOMBRES', 'NOMBRE'],
            'apellidos_nombres' => ['APELLIDOS Y NOMBRES', 'APELLIDOS NOMBRES', 'ESTUDIANTE'],
            'sexo' => ['SEXO'],
            'fecha_nacimiento' => ['FECHA DE NACIMIENTO', 'FECHA NACIMIENTO', 'F NACIMIENTO'],
        ],

        // Al menos uno de estos debe existir para identificar al alumno.
        'identificadores' => ['codigo_estudiante', 'numero_documento'],
    ],

    'notas' => [
        // Fila donde suelen empezar los alumnos si no se puede detectar.
        'fila_inicio_por_defecto' => 11,

        'filas_busqueda_encabezado' => 20,

        'alias_codigo_estudiante' => ['CODIGO DEL ESTUDIANTE', 'CODIGO ESTUDIANTE', 'COD ESTUDIANTE', 'CODIGO'],
        'alias_documento' => ['DNI', 'NUMERO DE DOCUMENTO', 'DOCUMENTO'],
        'alias_conclusion' => ['CONCLUSION DESCRIPTIVA', 'CONCLUSION'],

        // Filas vacías seguidas que indican el fin de la lista de alumnos.
        'filas_vacias_para_terminar' => 3,

        // Calificaciones que exigen conclusión descriptiva (MINEDU la exige con C).
        'exigir_conclusion_en' => ['C'],

        // Vigesimal: true = "05", false = "5".
        'vigesimal_dos_digitos' => true,
    ],
];
