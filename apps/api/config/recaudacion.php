<?php

/*
| EDUN-79 / EDUN-80 · Formatos de recaudación bancaria.
| Los formatos reales de BCP e Interbank los entrega cada banco al afiliar al colegio y no son
| públicos: estos son formatos base (texto separado por «|») que se ajustan aquí, sin tocar código.
| Columnas del archivo de deudas y posición de cada dato en el archivo de pagos (desde 0).
*/

return [
    'separador' => '|',
    'bancos' => [
        'BCP' => [
            'deudas' => ['codigo_recaudacion', 'alumno', 'concepto', 'vencimiento', 'monto'],
            'pagos' => ['codigo_recaudacion' => 0, 'monto' => 1, 'fecha_pago' => 2, 'operacion' => 3],
            'formato_fecha' => 'Ymd',
        ],
        'INTERBANK' => [
            'deudas' => ['codigo_recaudacion', 'alumno', 'concepto', 'vencimiento', 'monto'],
            'pagos' => ['operacion' => 0, 'codigo_recaudacion' => 1, 'fecha_pago' => 2, 'monto' => 3],
            'formato_fecha' => 'd/m/Y',
        ],
    ],
];
