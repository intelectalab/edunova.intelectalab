<?php

namespace App\Modulos\MDL\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Llamadas a los servicios web REST de Moodle ({url}/webservice/rest/server.php) con el token
 * del colegio. Con services.moodle.simular (fuera de producción por defecto) responde un Moodle
 * simulado que guarda sus datos en la caché: sirve para probar todo el flujo sin un Moodle real.
 *
 * Funciones que usa el conector (deben estar habilitadas en el servicio externo de Moodle):
 * core_webservice_get_site_info, core_course_create_categories, core_course_create_courses,
 * core_user_get_users_by_field, core_user_create_users, core_user_update_users,
 * enrol_manual_enrol_users, gradereport_user_get_grade_items.
 */
class ClienteMoodle
{
    public function __construct(private string $url, private string $token) {}

    public static function simulado(): bool
    {
        return (bool) config('services.moodle.simular');
    }

    /** @return array<mixed> */
    public function llamar(string $funcion, array $parametros = []): array
    {
        if (self::simulado()) {
            return $this->simular($funcion, $parametros);
        }
        $r = Http::asForm()->timeout((int) config('services.moodle.timeout', 20))
            ->post($this->url.'/webservice/rest/server.php', ['wstoken' => $this->token, 'wsfunction' => $funcion, 'moodlewsrestformat' => 'json'] + self::aplanar($parametros));
        if (! $r->successful()) {
            throw new RuntimeException("Moodle respondió {$r->status()} en {$funcion}.");
        }
        $json = $r->json();
        if (is_array($json) && isset($json['exception'])) {
            throw new RuntimeException('Moodle: '.($json['message'] ?? $json['errorcode'] ?? 'error')." ({$funcion}).");
        }

        return is_array($json) ? $json : [];
    }

    /** Moodle espera los arreglos como courses[0][fullname]=… */
    public static function aplanar(array $datos, string $prefijo = ''): array
    {
        $r = [];
        foreach ($datos as $k => $v) {
            $clave = $prefijo === '' ? (string) $k : "{$prefijo}[{$k}]";
            if (is_array($v)) {
                $r += self::aplanar($v, $clave);
            } else {
                $r[$clave] = is_bool($v) ? (int) $v : $v;
            }
        }

        return $r;
    }

    // ───────────── Moodle simulado (pruebas)

    private function simular(string $funcion, array $p): array
    {
        $clave = 'moodle-simulado:'.md5($this->url);
        $estado = Cache::get($clave, ['siguiente' => 100, 'usuarios' => [], 'cursos' => [], 'matriculas' => [], 'notas' => []]);
        if (str_contains($this->token, 'invalido')) {
            throw new RuntimeException('Moodle: Token no válido (invalidtoken).');
        }
        $nuevoId = function () use (&$estado) {
            return $estado['siguiente']++;
        };
        $r = match ($funcion) {
            'core_webservice_get_site_info' => ['sitename' => 'Moodle simulado', 'release' => '4.5 (simulado)', 'version' => '2024100700', 'functions' => []],
            'core_course_create_categories' => array_map(fn ($c) => ['id' => $nuevoId(), 'name' => $c['name']], $p['categories'] ?? []),
            'core_course_create_courses' => array_map(function ($c) use (&$estado, $nuevoId) {
                $id = $nuevoId();
                $estado['cursos'][$id] = $c['shortname'];

                return ['id' => $id, 'shortname' => $c['shortname']];
            }, $p['courses'] ?? []),
            'core_user_get_users_by_field' => array_values(array_filter(array_map(fn ($u) => in_array($u['username'], $p['values'] ?? [], true) ? $u : null, $estado['usuarios']))),
            'core_user_create_users' => array_map(function ($u) use (&$estado, $nuevoId) {
                $id = $nuevoId();
                $estado['usuarios'][$id] = ['id' => $id, 'username' => $u['username'], 'suspended' => 0];

                return ['id' => $id, 'username' => $u['username']];
            }, $p['users'] ?? []),
            'core_user_update_users' => (function () use (&$estado, $p) {
                foreach ($p['users'] ?? [] as $u) {
                    if (isset($estado['usuarios'][$u['id']]) && array_key_exists('suspended', $u)) {
                        $estado['usuarios'][$u['id']]['suspended'] = (int) $u['suspended'];
                    }
                }

                return [];
            })(),
            'enrol_manual_enrol_users' => (function () use (&$estado, $p) {
                foreach ($p['enrolments'] ?? [] as $e) {
                    $estado['matriculas'][$e['courseid'].'-'.$e['userid']] = (int) $e['roleid'];
                }

                return [];
            })(),
            'gradereport_user_get_grade_items' => ['usergrades' => array_values(array_map(fn ($uid) => [
                'userid' => (int) $uid, 'courseid' => (int) $p['courseid'],
                'gradeitems' => [['id' => 9001, 'itemname' => 'Examen de unidad (simulado)', 'itemtype' => 'mod', 'graderaw' => 15 + ((int) $uid % 6), 'grademax' => 20]],
            ], array_map(fn ($k) => (int) explode('-', $k)[1], array_keys(array_filter($estado['matriculas'], fn ($rol, $k) => str_starts_with($k, $p['courseid'].'-') && $rol === 5, ARRAY_FILTER_USE_BOTH)))))],
            default => throw new RuntimeException("Función no simulada: {$funcion}"),
        };
        Cache::put($clave, $estado, now()->addDays(30));

        return $r;
    }

    /** Estado del Moodle simulado (para las pruebas). */
    public static function estadoSimulado(string $url): array
    {
        return Cache::get('moodle-simulado:'.md5($url), []);
    }
}
