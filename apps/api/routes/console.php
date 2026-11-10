<?php

use App\Modulos\Compartido\Correo\CorreoDePrueba;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\Compartido\Monitoreo\Monitoreo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SIT\Services\BibliotecaMedios;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EDUN-207 / EDUN-237 · Crea los buckets si no existen: «objetos» (privado, archivos de los
// colegios) y «medios_sitio» (público, imágenes del sitio web).
Artisan::command('objetos:preparar', function () {
    foreach ([AlmacenArchivos::DISCO, BibliotecaMedios::DISCO] as $nombreDisco) {
        /** @var AwsS3V3Adapter $disco */
        $disco = Storage::disk($nombreDisco);
        $cliente = $disco->getClient();
        $bucket = config("filesystems.disks.{$nombreDisco}.bucket");
        if ($cliente->doesBucketExistV2($bucket, false)) {
            $this->info("El bucket «{$bucket}» ya existe.");

            continue;
        }
        $cliente->createBucket(['Bucket' => $bucket]);
        $this->info("Bucket «{$bucket}» creado.");
    }
})->purpose('Crea los buckets del almacenamiento de objetos si no existen');

// EDUN-208 · Envía un correo de prueba por la cola (en local llega a Mailpit: http://localhost:8026).
Artisan::command('correo:probar {email}', function (string $email) {
    $id = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, $email, 'prueba');
    $this->info("Correo en cola (bitácora #{$id}).");
})->purpose('Envía un correo de prueba por la cola');

// EDUN-206 · Revisión del monitoreo (la corre edunova-vigilancia.timer cada 5 minutos en el VPS).
Artisan::command('monitoreo:revisar', function (Monitoreo $monitoreo) {
    $problemas = $monitoreo->revisar();
    $problemas === [] ? $this->info('Todo en orden.') : $this->warn(implode(PHP_EOL, $problemas));

    return $problemas === [] ? 0 : 1;
})->purpose('Revisa la salud, los 5xx y la cola, y avisa al equipo técnico si algo falla');
