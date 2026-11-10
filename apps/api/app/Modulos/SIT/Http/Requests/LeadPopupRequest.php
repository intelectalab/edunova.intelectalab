<?php

namespace App\Modulos\SIT\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-271 / SIT-009 · Lead del popup de salida: correo, teléfono o ambos según
 * cms.popup_config.capture_field, y la casilla de consentimiento (EDUN-291).
 */
class LeadPopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) && trim($this->email) !== '' ? mb_strtolower(trim($this->email)) : null,
            'telefono' => is_string($this->telefono) && trim($this->telefono) !== '' ? trim($this->telefono) : null,
        ]);
    }

    public function rules(): array
    {
        $campo = self::campoCaptura();
        $utm = ['nullable', 'string', 'max:100'];
        $email = ['email:rfc', 'max:160'];
        $telefono = ['string', 'regex:/^\+?[0-9 ()-]{6,20}$/'];

        return [
            'email' => match ($campo) {
                'email' => ['required', ...$email],
                'telefono' => ['prohibited'],
                default => ['nullable', 'required_without:telefono', ...$email],
            },
            'telefono' => match ($campo) {
                'telefono' => ['required', ...$telefono],
                'email' => ['prohibited'],
                default => ['nullable', 'required_without:email', ...$telefono],
            },
            'consentimiento' => ['accepted'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'utm_source' => $utm, 'utm_medium' => $utm, 'utm_campaign' => $utm, 'utm_content' => $utm, 'utm_term' => $utm,
            'sitio_web' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Completa este campo.',
            'email.required_without' => 'Escribe tu correo o tu WhatsApp.',
            'telefono.required_without' => 'Escribe tu correo o tu WhatsApp.',
            'email.email' => 'Escribe un correo válido.',
            'telefono.regex' => 'Escribe un teléfono válido.',
            'consentimiento.accepted' => 'Debes aceptar la Política de Privacidad para enviar tus datos.',
            'prohibited' => 'No pudimos registrar tus datos.',
        ];
    }

    /** 'email' | 'telefono' | 'ambos' (por defecto, si no hay popup configurado). */
    public static function campoCaptura(): string
    {
        $campo = DB::transaction(function () {
            DB::select("select set_config('app.rol_cms', 'publico', true)");

            return DB::table('cms.popup_config')->where('id', 1)->value('capture_field');
        });

        return in_array($campo, ['email', 'telefono', 'ambos'], true) ? $campo : 'ambos';
    }
}
