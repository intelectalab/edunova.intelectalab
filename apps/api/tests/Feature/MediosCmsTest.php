<?php

use App\Modulos\SIT\Services\BibliotecaMedios;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| EDUN-237 / SIT-045 · Biblioteca de medios del CMS.
*/

function tokenCms(): string
{
    static $t = null;

    return $t ??= test()->postJson('/api/v1/auth/ingresar', ['usuario' => 'superadmin@demo.edunova.pe', 'password' => DemoSeeder::DEMO_PASSWORD])->json('token');
}

/** PNG mínimo válido (sin GD): firma, IHDR con el tamaño, IDAT vacío comprimido e IEND. */
function pngDe(int $ancho, int $alto): UploadedFile
{
    $trozo = fn (string $tipo, string $datos) => pack('N', strlen($datos)).$tipo.$datos.pack('N', crc32($tipo.$datos));
    $ihdr = pack('NNCCCCC', $ancho, $alto, 8, 2, 0, 0, 0);
    $bytes = chr(0x89).'PNG'.chr(13).chr(10).chr(26).chr(10).$trozo('IHDR', $ihdr).$trozo('IDAT', gzcompress('')).$trozo('IEND', '');

    return UploadedFile::fake()->createWithContent('portada.png', $bytes);
}

beforeEach(fn () => Storage::fake(BibliotecaMedios::DISCO));

it('un PNG de 1200 × 800 queda en cms.medios con su tamaño y se puede previsualizar', function () {
    $r = $this->withToken(tokenCms())->post('/api/v1/cms/medios', ['archivo' => pngDe(1200, 800), 'alt' => 'Portada del sitio'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('message', 'Archivo subido.');

    $medio = $r->json('data');
    expect($medio)->tipo->toBe('imagen')->mime->toBe('image/png')->ancho->toBe(1200)->alto->toBe(800)->alt->toBe('Portada del sitio')
        ->and($medio['url'])->toContain('medios/');
    Storage::disk(BibliotecaMedios::DISCO)->assertExists(str($medio['url'])->after('/medios/')->prepend('medios/')->toString());
    expect(DB::connection('propietario')->table('cms.auditoria')->where('entidad', 'medios')->where('entidad_id', (string) $medio['id'])->exists())->toBeTrue();

    $lista = $this->withToken(tokenCms())->getJson('/api/v1/cms/medios?tipo=imagenes')->assertOk()->json('data');
    expect(collect($lista)->pluck('id'))->toContain($medio['id']);
});

it('un archivo de 8 MB se rechaza con «El archivo supera 5 MB»', function () {
    $this->withToken(tokenCms())->post('/api/v1/cms/medios', ['archivo' => UploadedFile::fake()->create('grande.pdf', 8 * 1024, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.archivo.0', 'El archivo supera 5 MB.');
    expect(Storage::disk(BibliotecaMedios::DISCO)->allFiles())->toBe([]);
});

it('un SVG con script se guarda sin el script ni los eventos', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40" onload="alert(1)">'
        .'<script>alert("xss")</script><a href="javascript:alert(2)"><rect width="120" height="40" fill="#0a3257"/></a></svg>';

    $medio = $this->withToken(tokenCms())->post('/api/v1/cms/medios', ['archivo' => UploadedFile::fake()->createWithContent('logo.svg', $svg), 'alt' => 'Logo'], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');

    $guardado = Storage::disk(BibliotecaMedios::DISCO)->get(collect(Storage::disk(BibliotecaMedios::DISCO)->allFiles())->first());
    expect($medio['tipo'])->toBe('svg')->and($medio['ancho'])->toBe(120)->and($medio['alto'])->toBe(40)
        ->and($guardado)->not->toContain('<script')->not->toContain('onload')->not->toContain('javascript:')
        ->and($guardado)->toContain('<rect');
});

it('rechaza tipos no permitidos aunque cambien la extensión', function () {
    $this->withToken(tokenCms())->post('/api/v1/cms/medios', ['archivo' => UploadedFile::fake()->createWithContent('foto.png', '<?php echo 1;')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonPath('errors.archivo.0', 'Solo se aceptan imágenes JPG, PNG, WebP o SVG y documentos PDF.');
});

it('una imagen sin texto alternativo no se puede publicar', function () {
    $medio = $this->withToken(tokenCms())->post('/api/v1/cms/medios', ['archivo' => pngDe(10, 10)], ['Accept' => 'application/json'])->assertCreated()->json('data');

    expect(fn () => BibliotecaMedios::exigirTextoAlternativo($medio['id']))->toThrow(ValidationException::class);

    $this->withToken(tokenCms())->patchJson("/api/v1/cms/medios/{$medio['id']}", ['alt' => 'Vista del panel'])->assertOk()->assertJsonPath('data.alt', 'Vista del panel');
    BibliotecaMedios::exigirTextoAlternativo($medio['id']);
});

it('un usuario de colegio no sube medios', function () {
    $director = $this->postJson('/api/v1/auth/ingresar', ['usuario' => 'director@demo.edunova.pe', 'password' => DemoSeeder::DEMO_PASSWORD])->json('token');
    app('auth')->forgetGuards();
    $this->withToken($director)->post('/api/v1/cms/medios', ['archivo' => pngDe(10, 10)], ['Accept' => 'application/json'])->assertForbidden();
});
