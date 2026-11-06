<?php

use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EDUN-207 · Crea el bucket privado del almacenamiento de objetos si no existe (local y entornos nuevos).
Artisan::command('objetos:preparar', function () {
    /** @var AwsS3V3Adapter $disco */
    $disco = Storage::disk(AlmacenArchivos::DISCO);
    $cliente = $disco->getClient();
    $bucket = config('filesystems.disks.objetos.bucket');
    if ($cliente->doesBucketExistV2($bucket, false)) {
        $this->info("El bucket «{$bucket}» ya existe.");

        return;
    }
    $cliente->createBucket(['Bucket' => $bucket]);
    $this->info("Bucket «{$bucket}» creado (privado).");
})->purpose('Crea el bucket del almacenamiento de objetos si no existe');
