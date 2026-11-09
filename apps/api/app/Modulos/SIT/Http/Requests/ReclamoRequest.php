<?php

namespace App\Modulos\SIT\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EDUN-294 / SIT-010 · Hoja de reclamación (D.S. N.° 011-2011-PCM): reclamante (y apoderado si
 * es menor de edad), bien o servicio, tipo, detalle de al menos 50 caracteres, pedido,
 * declaración y consentimiento. Adjuntos: hasta 2 imágenes o PDF de 5 MB.
 */
class ReclamoRequest extends FormRequest
{
    public const MAX_ADJUNTOS = 2;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'menor_edad' => filter_var($this->input('menor_edad', false), FILTER_VALIDATE_BOOL),
            'numero_doc' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('numero_doc'))),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        $documento = fn (string $campoTipo) => function (string $atributo, mixed $valor, \Closure $falla) use ($campoTipo) {
            $patrones = ['DNI' => '/^\d{8}$/', 'RUC' => '/^(10|15|17|20)\d{9}$/', 'CE' => '/^[A-Z0-9]{8,12}$/', 'PAS' => '/^[A-Z0-9]{6,12}$/'];
            $tipo = $this->input($campoTipo);
            if (isset($patrones[$tipo]) && ! preg_match($patrones[$tipo], (string) $valor)) {
                $falla(['DNI' => 'El DNI tiene 8 dígitos.', 'RUC' => 'El RUC tiene 11 dígitos.'][$tipo] ?? 'Revisa el número de documento.');
            }
        };

        return [
            'tipo' => ['required', Rule::in(['reclamo', 'queja'])],
            'nombre' => ['required', 'string', 'min:3', 'max:160'],
            'tipo_doc' => ['required', Rule::in(['DNI', 'RUC', 'CE', 'PAS'])],
            'numero_doc' => ['required', 'string', 'max:15', $documento('tipo_doc')],
            'email' => ['required', 'email:rfc', 'max:160'],
            'telefono' => ['nullable', 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'direccion' => ['required', 'string', 'min:5', 'max:250'],
            'menor_edad' => ['boolean'],
            'apoderado_nombre' => ['required_if:menor_edad,true', 'nullable', 'string', 'min:3', 'max:160'],
            'apoderado_doc' => ['required_if:menor_edad,true', 'nullable', 'string', 'regex:/^[0-9A-Za-z]{8,12}$/'],
            'producto' => ['required', 'string', 'min:3', 'max:250'],
            'monto' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'descripcion' => ['required', 'string', 'min:50', 'max:2000'],
            'pedido' => ['required', 'string', 'min:5', 'max:1000'],
            'declaracion' => ['accepted'],
            'consentimiento' => ['accepted'],
            'adjuntos' => ['nullable', 'array', 'max:'.self::MAX_ADJUNTOS],
            'adjuntos.*' => ['file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'],
            'recaptcha' => ['nullable', 'string', 'max:4000'],
            'sitio_web' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Completa este campo.',
            'required_if' => 'Completa este campo.',
            'descripcion.min' => 'Describe los hechos con al menos 50 caracteres.',
            'descripcion.max' => 'El detalle admite hasta 2000 caracteres.',
            'email.email' => 'Escribe un correo válido.',
            'telefono.regex' => 'Escribe un teléfono válido.',
            'apoderado_doc.regex' => 'Revisa el documento del padre, madre o tutor.',
            'declaracion.accepted' => 'Debes declarar que la información es verdadera.',
            'consentimiento.accepted' => 'Debes autorizar el tratamiento de tus datos para tramitar el reclamo.',
            'adjuntos.max' => 'Puedes adjuntar hasta 2 archivos.',
            'adjuntos.*.max' => 'Cada archivo puede pesar hasta 5 MB.',
            'adjuntos.*.mimetypes' => 'Adjunta imágenes (JPG, PNG o WebP) o PDF.',
            'adjuntos.*.file' => 'No pudimos leer el archivo adjunto.',
            'monto.*' => 'Escribe el monto en soles, por ejemplo 150.00.',
            'sitio_web.prohibited' => 'No pudimos registrar el reclamo.',
        ];
    }
}
