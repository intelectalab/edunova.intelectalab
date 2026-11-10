<?php

namespace App\Modulos\ADM\Http\Controllers;

use App\Modulos\ADM\Services\ConfiguracionInstitucion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** EDUN-43 · ADM-01 Configuración de la institución. */
class InstitucionController
{
    public function __construct(private ConfiguracionInstitucion $config) {}

    public function show(): JsonResponse
    {
        return response()->json($this->config->datos());
    }

    public function update(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'razon_social' => ['nullable', 'string', 'max:200'],
            'ruc' => ['nullable', 'digits:11'],
            'codigo_local' => ['nullable', 'digits:6'],
            'ugel' => ['required', 'string', 'max:120'],
            'dre' => ['nullable', 'string', 'max:120'],
            'ubigeo' => ['nullable', 'digits:6'],
            'direccion' => ['nullable', 'string', 'max:250'],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +()-]{6,20}$/'],
            'correo' => ['nullable', 'email:rfc', 'max:150'],
            'color_primario' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'resolucion_creacion' => ['nullable', 'string', 'max:120'],
            'horario_atencion' => ['nullable', 'string', 'max:120'],
            'pagina_web' => ['nullable', 'url:https,http', 'max:200'],
            'director_nombre' => ['nullable', 'string', 'max:150'],
        ], [
            'nombre.required' => 'Escribe el nombre del colegio.',
            'ugel.required' => 'Escribe la UGEL.',
            'ruc.digits' => 'El RUC debe tener 11 dígitos.',
            'codigo_local.digits' => 'El código de local debe tener 6 dígitos.',
            'ubigeo.digits' => 'El ubigeo debe tener 6 dígitos.',
            'telefono.regex' => 'El teléfono no es válido.',
            'correo.email' => 'El correo no es válido.',
            'color_primario.regex' => 'Elige un color en formato #RRGGBB.',
            'pagina_web.url' => 'La página web debe empezar con https://',
        ]);
        $this->config->guardar($datos);

        return response()->json($this->config->datos());
    }

    public function servicios(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'servicios' => ['required', 'array', 'min:1', 'max:3'],
            'servicios.*.nivel' => ['required', Rule::in(['inicial', 'primaria', 'secundaria'])],
            'servicios.*.codigo_modular' => ['required', 'digits:7'],
            'servicios.*.anexo' => ['nullable', 'digits:1'],
        ], [
            'servicios.required' => 'Agrega al menos un nivel con su código modular.',
            'servicios.min' => 'Agrega al menos un nivel con su código modular.',
            'servicios.*.codigo_modular.required' => 'Escribe el código modular de cada nivel.',
            'servicios.*.codigo_modular.digits' => 'El código modular tiene 7 dígitos.',
        ]);
        $this->config->guardarServicios($datos['servicios']);

        return response()->json($this->config->datos());
    }

    public function subirLogo(Request $request): JsonResponse
    {
        $datos = $request->validate(
            ['logo' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000']],
            ['logo.required' => 'Elige una imagen.', 'logo.max' => 'El logo debe pesar menos de 2 MB.', 'logo.mimes' => 'El logo debe ser PNG, JPG o WEBP.', 'logo.dimensions' => 'El logo debe medir entre 64 y 4000 píxeles por lado.'],
        );
        $this->config->guardarLogo($datos['logo'], $request->user()->id);

        return response()->json($this->config->datos());
    }

    public function quitarLogo(): JsonResponse
    {
        $this->config->quitarLogo();

        return response()->json($this->config->datos());
    }

    /** El logo se entrega por la API (con sesión) para que la web lo muestre sin abrir el bucket. */
    public function logo(): Response
    {
        $archivo = $this->config->logo();
        abort_if(! $archivo, 404);
        $contenido = Storage::disk($archivo->disco)->get($archivo->ruta);
        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => $archivo->mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
