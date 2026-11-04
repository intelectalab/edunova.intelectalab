<?php

/*
| Pest: las pruebas de Feature y Unit usan Tests\TestCase, que reconstruye la base
| de pruebas (edunova_test) una vez por corrida. Las pruebas en clases (PHPUnit)
| también corren con Pest sin cambios.
*/

pest()->extend(Tests\TestCase::class)->in('Feature');
