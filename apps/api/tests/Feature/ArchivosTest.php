<?php

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| EDUN-207 · Almacenamiento de objetos compatible con S3.
| Las pruebas de regla usan un disco falso; las de integración corren contra el S3 local
| (SeaweedFS de docker compose) y se omiten si no está disponible.
*/

function colegioDemo(): int
{
    return (int) DB::connection('propietario')->table('instituciones')->value('id');
}

/** Ejecuta dentro de una transacción con el colegio activo fijado y la deshace al final. */
function enColegio(?int $id, callable $accion): mixed
{
    DB::beginTransaction();
    try {
        ContextoInstitucion::fijar($id);

        return $accion();
    } finally {
        DB::rollBack();
    }
}

function s3LocalDisponible(): bool
{
    try {
        return Storage::disk(AlmacenArchivos::DISCO)->getClient()->doesBucketExistV2(config('filesystems.disks.objetos.bucket'), false);
    } catch (Throwable) {
        return false;
    }
}

it('guarda el archivo con el prefijo del colegio y lo registra', function () {
    Storage::fake(AlmacenArchivos::DISCO);
    $colegio = colegioDemo();

    $archivo = enColegio($colegio, function () {
        $a = app(AlmacenArchivos::class)->guardar(UploadedFile::fake()->create('constancia.pdf', 120, 'application/pdf'));
        expect(Archivo::query()->whereKey($a->id)->exists())->toBeTrue();

        return $a;
    });

    expect($archivo->ruta)->toStartWith("{$colegio}/")
        ->and($archivo->institucion_id)->toBe($colegio)
        ->and($archivo->nombre_original)->toBe('constancia.pdf')
        ->and($archivo->sha256)->toHaveLength(64);
    Storage::disk(AlmacenArchivos::DISCO)->assertExists($archivo->ruta);
});

it('rechaza el archivo que supera el límite antes de subirlo, con un mensaje claro', function () {
    Storage::fake(AlmacenArchivos::DISCO);
    config(['edunova.archivos.max_mb' => 1]);

    $error = null;
    enColegio(colegioDemo(), function () use (&$error) {
        try {
            app(AlmacenArchivos::class)->guardar(UploadedFile::fake()->create('video.mp4', 2048));
        } catch (ValidationException $e) {
            $error = $e->errors()['archivo'][0];
        }
    });

    expect($error)->toBe('El archivo pesa 2.0 MB y el máximo permitido es 1 MB.');
    expect(Storage::disk(AlmacenArchivos::DISCO)->allFiles())->toBe([]);
});

it('sin colegio activo no guarda nada', function () {
    Storage::fake(AlmacenArchivos::DISCO);

    enColegio(null, fn () => app(AlmacenArchivos::class)->guardar(UploadedFile::fake()->create('a.pdf', 1)));
})->throws(RuntimeException::class, 'No hay colegio activo');

it('otro colegio no ve los archivos', function () {
    Storage::fake(AlmacenArchivos::DISCO);

    enColegio(colegioDemo(), function () {
        app(AlmacenArchivos::class)->guardar(UploadedFile::fake()->create('a.pdf', 1));
        ContextoInstitucion::fijar(colegioDemo() + 1000);
        expect(Archivo::query()->count())->toBe(0);
    });
});

it('la descarga es por URL firmada que vence a los 5 minutos', function () {
    $archivo = new Archivo(['disco' => AlmacenArchivos::DISCO, 'ruta' => '1/2026/11/x.pdf', 'nombre_original' => 'x.pdf']);

    $url = app(AlmacenArchivos::class)->urlTemporal($archivo);

    // Se firma contra el host público configurado, no se reemplaza después de firmar.
    expect($url)->toStartWith(rtrim((string) config('filesystems.disks.objetos.temporary_url') ?: (string) config('filesystems.disks.objetos.endpoint'), '/'))
        ->toContain('/1/2026/11/x.pdf')
        ->toContain('X-Amz-Signature=')
        ->toContain('X-Amz-Expires=300');
});

it('integración con el S3 local: el bucket es privado y la URL firmada sí descarga', function () {
    if (! s3LocalDisponible()) {
        $this->markTestSkipped('S3 local no disponible (docker compose up s3).');
    }
    // Desde el contenedor de la API el S3 se ve como http://s3:8333 (no como localhost:9000).
    config(['filesystems.disks.objetos.temporary_url' => null]);
    Storage::forgetDisk(AlmacenArchivos::DISCO);
    $disco = Storage::disk(AlmacenArchivos::DISCO);

    $archivo = enColegio(colegioDemo(), fn () => app(AlmacenArchivos::class)->guardar(UploadedFile::fake()->createWithContent('nota.txt', 'contenido de prueba')));

    try {
        $directa = rtrim(config('filesystems.disks.objetos.endpoint'), '/').'/'.config('filesystems.disks.objetos.bucket').'/'.$archivo->ruta;
        expect(Http::get($directa)->status())->toBe(403);

        $firmada = Http::get(app(AlmacenArchivos::class)->urlTemporal($archivo));
        expect($firmada->status())->toBe(200)
            ->and($firmada->body())->toBe('contenido de prueba');
    } finally {
        $disco->delete($archivo->ruta);
    }
});
