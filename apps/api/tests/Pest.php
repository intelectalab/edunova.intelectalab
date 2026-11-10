<?php

use Tests\TestCase;

/*
| Pest: las pruebas de Feature y Unit usan Tests\TestCase, que reconstruye la base
| de pruebas (edunova_test) una vez por corrida. Las pruebas en clases (PHPUnit)
| también corren con Pest sin cambios.
*/

require_once __DIR__.'/Ayudas.php';

pest()->extend(TestCase::class)->in('Feature');
