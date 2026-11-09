<?php

namespace App\Modulos\SIT\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * EDUN-267 / SIT-002 · Solicitud de demo con calendario. EDUN-291 / SIT-054: sin la casilla de
 * consentimiento no se registra nada (422). Los horarios son fijos, en hora de Lima.
 */
class SolicitudDemoRequest extends FormRequest
{
    public const HORAS = ['09:00', '10:30', '12:00', '15:00', '16:30', '18:00'];

    public const PLANES = ['inicial', 'crecimiento', 'integral', 'institucional'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $utm = ['nullable', 'string', 'max:100'];

        return [
            'source' => ['required', Rule::in(['demo'])],
            'nombre' => ['required', 'string', 'min:3', 'max:120'],
            'cargo' => ['required', 'string', 'max:80'],
            'telefono' => ['required', 'string', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'colegio' => ['required', 'string', 'min:2', 'max:160'],
            // EDUN-278 · Para filtrar la bandeja por ciudad.
            'ciudad' => ['nullable', 'string', 'max:80'],
            'alumnos' => ['required', 'integer', 'min:1', 'max:100000'],
            'plan_interes' => ['nullable', Rule::in(self::PLANES)],
            'demo_fecha' => ['required', 'date_format:Y-m-d', $this->diaHabilDesdeManana(...)],
            'demo_hora' => ['required', Rule::in(self::HORAS)],
            'consentimiento' => ['accepted'],
            'origen' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'utm_source' => $utm, 'utm_medium' => $utm, 'utm_campaign' => $utm, 'utm_content' => $utm, 'utm_term' => $utm,
            // Campo trampa: invisible para las personas, los robots lo llenan.
            'sitio_web' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Completa este campo.',
            'consentimiento.accepted' => 'Debes aceptar la Política de Privacidad para enviar la solicitud.',
            'email.email' => 'Escribe un correo válido.',
            'telefono.regex' => 'Escribe un teléfono válido.',
            'alumnos.*' => 'Indica el número de alumnos.',
            'demo_fecha.date_format' => 'Elige el día de la demo en el calendario.',
            'demo_fecha.required' => 'Elige el día de la demo en el calendario.',
            'demo_hora.*' => 'Elige una de las horas disponibles.',
            'plan_interes.in' => 'Elige un plan de la lista.',
            'sitio_web.prohibited' => 'No pudimos registrar la solicitud.',
        ];
    }

    private function diaHabilDesdeManana(string $atributo, mixed $valor, Closure $falla): void
    {
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', (string) $valor, 'America/Lima');
        if (! $fecha || $fecha->format('Y-m-d') !== $valor) {
            return; // Lo informa date_format.
        }
        if ($fecha->isWeekend()) {
            $falla('La demo se agenda de lunes a viernes.');
        } elseif ($fecha->lte(CarbonImmutable::now('America/Lima')->startOfDay())) {
            $falla('Elige un día a partir de mañana.');
        }
    }
}
