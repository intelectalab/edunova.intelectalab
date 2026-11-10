<?php

namespace App\Modulos\ADM\Services;

use App\Models\User;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Services\Auditoria;
use App\Modulos\SEG\Services\UsuariosColegio;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-47 / ADM-H05 · Personal docente y administrativo: datos, cargo, especialidad,
 * contrato y vencimiento. Desde aquí se le crea el usuario y se le envía la invitación
 * (EDUN-36): queda enlazado en personal.user_id.
 */
class Personal
{
    public const CAMPOS = ['tipo_documento', 'numero_documento', 'apellidos', 'nombres', 'cargo', 'especialidad', 'tipo_contrato', 'contrato_fin', 'colegiatura', 'email', 'celular', 'estado'];

    public function __construct(private Auditoria $auditoria, private UsuariosColegio $usuarios) {}

    /** @param array{buscar?: string|null, estado?: string|null} $filtros */
    public function listar(array $filtros): array
    {
        return DB::table('personal as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->when($filtros['buscar'] ?? null, function ($q, $v) {
                $t = '%'.mb_strtolower(trim($v)).'%';
                $q->where(fn ($w) => $w->whereRaw("lower(p.nombres || ' ' || p.apellidos) like ?", [$t])->orWhere('p.numero_documento', 'like', $t)->orWhereRaw('lower(p.cargo) like ?', [$t]));
            })
            ->when($filtros['estado'] ?? null, fn ($q, $v) => $q->where('p.estado', $v))
            ->orderBy('p.apellidos')->orderBy('p.nombres')
            ->get(['p.*', DB::raw('u.password is not null as acepto_invitacion')])
            ->map(function ($p) {
                $dias = $p->contrato_fin ? (int) now()->startOfDay()->diffInDays($p->contrato_fin, false) : null;

                return [
                    ...collect(self::CAMPOS)->mapWithKeys(fn ($c) => [$c => $p->{$c}])->all(),
                    'id' => (int) $p->id, 'user_id' => $p->user_id ? (int) $p->user_id : null,
                    'usuario' => $p->user_id ? ($p->acepto_invitacion ? 'activo' : 'invitado') : 'sin_usuario',
                    // Aviso de contrato por vencer (30 días) o vencido.
                    'contrato' => $dias === null ? null : ($dias < 0 ? 'vencido' : ($dias <= 30 ? 'por_vencer' : 'vigente')),
                ];
            })->all();
    }

    /** @param array<string, mixed> $d */
    public function guardar(array $d, ?int $id = null): int
    {
        $fila = collect(self::CAMPOS)->mapWithKeys(fn ($c) => [$c => $d[$c] ?? null])->all();
        $fila['tipo_documento'] ??= 'DNI';
        $fila['estado'] ??= 'activo';
        $fila['email'] = $fila['email'] ? mb_strtolower(trim($fila['email'])) : null;
        try {
            if ($id) {
                $antes = (array) DB::table('personal')->find($id);
                abort_if(! $antes, 404);
                DB::table('personal')->where('id', $id)->update($fila);
                $cambios = array_diff_assoc(array_map(fn ($v) => (string) $v, $fila), array_map(fn ($v) => (string) $v, array_intersect_key($antes, $fila)));
                if ($cambios !== []) {
                    $this->auditoria->registrar('personal_editado', 'personal', $id, array_intersect_key($antes, $cambios), array_intersect_key($fila, $cambios));
                }

                return $id;
            }
            $nuevo = (int) DB::table('personal')->insertGetId($fila + ['institucion_id' => ContextoInstitucion::requerido()]);
            $this->auditoria->registrar('personal_registrado', 'personal', $nuevo, null, $fila);

            return $nuevo;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['numero_documento' => ['Ya hay una persona del personal con ese documento.']]);
            }
            throw $e;
        }
    }

    /**
     * Crea el usuario (o reutiliza el que ya existe con ese correo o documento) y le envía la
     * invitación con los roles elegidos.
     *
     * @param  list<string>  $roles
     * @return array{mensaje: string, invitacion: array<string, mixed>|null}
     */
    public function invitar(User $actor, int $id, array $roles, string $canal): array
    {
        $p = DB::table('personal')->find($id);
        abort_if(! $p, 404);

        // Ya tiene usuario: solo se reenvía la invitación.
        if ($p->user_id && DB::table('membresias')->where('user_id', $p->user_id)->exists()) {
            return ['mensaje' => 'Invitación reenviada.', 'invitacion' => $this->usuarios->reinvitar($actor, (int) $p->user_id, $canal)];
        }
        // La persona ya es usuaria del colegio (con otro registro): se enlaza sin invitar.
        $existente = UsuariosColegio::buscarPersona($p->email, $p->tipo_documento, $p->numero_documento);
        if ($existente && DB::table('membresias')->where('user_id', $existente->id)->exists()) {
            DB::table('personal')->where('id', $id)->update(['user_id' => $existente->id]);

            return ['mensaje' => 'Esta persona ya tenía usuario en el colegio: quedó enlazada.', 'invitacion' => null];
        }

        $r = $this->usuarios->invitar($actor, [
            'nombres' => $p->nombres, 'apellidos' => $p->apellidos, 'tipo_documento' => $p->tipo_documento,
            'numero_documento' => $p->numero_documento, 'email' => $p->email, 'celular' => $p->celular, 'roles' => $roles, 'canal' => $canal,
        ]);
        DB::table('personal')->where('id', $id)->update(['user_id' => $r['usuario_id']]);

        return ['mensaje' => $canal === 'correo' ? "Invitación enviada a {$p->email}." : 'Invitación creada: envíala por WhatsApp.', 'invitacion' => $r['invitacion']];
    }
}
