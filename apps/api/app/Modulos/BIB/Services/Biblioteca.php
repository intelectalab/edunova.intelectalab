<?php

namespace App\Modulos\BIB\Services;

use App\Models\User;
use App\Modulos\ADM\Services\Carnes;
use App\Modulos\COM\Services\Notificador;
use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SEG\Models\Archivo;
use App\Modulos\SEG\Services\AlmacenArchivos;
use App\Modulos\SEG\Services\Auditoria;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * EDUN-151 a 160 · Biblioteca escolar.
 *
 * - Catálogo por título, autor o ISBN, con área del CNEB, nivel, grado y tipo; ficha con ejemplares y
 *   quién los tiene. ISBN: título, autor y editorial se autocompletan con Open Library cuando responde.
 * - Préstamo con el código del ejemplar (o su QR): un ejemplar prestado no se presta otra vez, hay
 *   límite por lector y, sin multas, el atraso suspende un día por cada día de atraso (si la regla
 *   está activa). Aviso diario de vencidos al alumno y a la familia.
 * - Reservas desde la app, con aviso cuando el libro vuelve.
 * - Plan lector por grado y periodo con el avance de cada alumno y recordatorio a quienes faltan.
 * - Libros digitales: se respetan las licencias, vencen solos y se abren con un enlace que vence en minutos.
 * - Inventario por estante, bajas con motivo y etiquetas con QR en PDF; estadísticas de lectura.
 */
class Biblioteca
{
    public const TIPOS = ['libro', 'texto', 'consulta', 'revista', 'ebook', 'audiolibro'];

    public function __construct(private Notificador $notificador, private Auditoria $auditoria, private AlmacenArchivos $almacen) {}

    // ───────────── Reglas (EDUN-159)

    public function reglas(): object
    {
        $r = DB::table('bib_reglas')->first();
        if (! $r) {
            DB::table('bib_reglas')->insert(['institucion_id' => ContextoInstitucion::requerido()]);
            $r = DB::table('bib_reglas')->first();
        }

        return $r;
    }

    public function guardarReglas(array $d): object
    {
        $r = $this->reglas();
        DB::table('bib_reglas')->where('id', $r->id)->update(array_intersect_key($d, array_flip(['dias_alumno', 'dias_personal', 'max_alumno', 'renovaciones_max', 'suspension_por_atraso', 'reservas_app'])));

        return $this->reglas();
    }

    // ───────────── Catálogo (EDUN-151 / 155)

    public function catalogo(array $f): array
    {
        $q = DB::table('bib_libros as l')->leftJoin('areas_curriculares as a', 'a.id', '=', 'l.area_curricular_id')
            ->when($f['buscar'] ?? null, fn ($q, $b) => $q->where(fn ($w) => $w->whereRaw('lower(l.titulo) like ?', ['%'.mb_strtolower(trim($b)).'%'])
                ->orWhereRaw('lower(l.autor) like ?', ['%'.mb_strtolower(trim($b)).'%'])->orWhere('l.isbn', preg_replace('/[^0-9X]/i', '', $b))))
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('l.tipo', $v))
            ->when($f['nivel'] ?? null, fn ($q, $v) => $q->whereIn('l.nivel', [$v, 'todos']))
            ->when($f['area_curricular_id'] ?? null, fn ($q, $v) => $q->where('l.area_curricular_id', $v))
            ->when($f['categoria'] ?? null, fn ($q, $v) => $q->where('l.categoria', $v))
            ->when($f['grado'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->whereNull('l.grados')->orWhere('l.grados', 'like', '%'.$v.'%')))
            ->orderBy('l.titulo')->limit(300);

        return $q->get(['l.id', 'l.titulo', 'l.autor', 'l.isbn', 'l.editorial', 'l.anio_publicacion', 'l.categoria', 'l.ubicacion', 'l.tipo', 'l.nivel', 'l.grados', 'l.licencias', 'a.nombre as area',
            DB::raw("(select count(*) from bib_ejemplares e where e.bib_libro_id = l.id and e.estado <> 'baja') as ejemplares"),
            DB::raw("(select count(*) from bib_ejemplares e where e.bib_libro_id = l.id and e.estado = 'disponible') as disponibles")])
            ->map(fn ($l) => array_merge((array) $l, ['ejemplares' => (int) $l->ejemplares, 'disponibles' => (int) $l->disponibles]))->all();
    }

    public function ficha(int $id, bool $conLectores): array
    {
        $l = collect($this->catalogo([]))->firstWhere('id', $id) ?? (array) DB::table('bib_libros')->find($id);
        abort_if(! $l, 404, 'El libro no existe.');
        $ejemplares = DB::table('bib_ejemplares as e')->leftJoin('bib_prestamos as p', fn ($j) => $j->on('p.bib_ejemplar_id', '=', 'e.id')->whereNull('p.devuelto_at'))
            ->where('e.bib_libro_id', $id)->orderBy('e.codigo')
            ->get(['e.id', 'e.codigo', 'e.estado', 'e.conservacion', 'e.estante', 'e.inventariado_at', 'p.id as prestamo_id', 'p.lector_tipo', 'p.lector_id', 'p.vence']);

        return array_merge($l, [
            'descripcion' => DB::table('bib_libros')->where('id', $id)->value('descripcion'),
            // Quien no gestiona la biblioteca no ve qué lector tiene cada ejemplar.
            'ejemplares_detalle' => $ejemplares->map(fn ($e) => $conLectores
                ? array_merge((array) $e, ['lector' => $e->prestamo_id ? $this->nombreLector($e->lector_tipo, (int) $e->lector_id) : null])
                : array_merge(array_diff_key((array) $e, array_flip(['lector_tipo', 'lector_id', 'prestamo_id'])), ['lector' => null]))->all(),
            'reservas' => DB::table('bib_reservas')->where('bib_libro_id', $id)->whereIn('estado', ['en_espera', 'disponible'])->count(),
        ]);
    }

    public function guardarLibro(?int $id, array $d, ?UploadedFile $archivo, int $usuarioId): array
    {
        $fila = array_intersect_key($d, array_flip(['titulo', 'autor', 'isbn', 'editorial', 'anio_publicacion', 'categoria', 'ubicacion', 'tipo', 'area_curricular_id', 'nivel', 'grados', 'descripcion', 'licencias']));
        if (isset($fila['isbn'])) {
            $fila['isbn'] = preg_replace('/[^0-9X]/i', '', (string) $fila['isbn']) ?: null;
        }
        if ($archivo) {
            $fila['archivo_id'] = $this->almacen->guardar($archivo, $usuarioId, true)->id;
        }
        if (($fila['tipo'] ?? null) === 'ebook' && empty($fila['licencias'])) {
            throw ValidationException::withMessages(['licencias' => ['Indica cuántas licencias tiene el libro digital.']]);
        }
        if ($id) {
            abort_unless(DB::table('bib_libros')->where('id', $id)->exists(), 404);
            DB::table('bib_libros')->where('id', $id)->update($fila);
        } else {
            $id = (int) DB::table('bib_libros')->insertGetId(['institucion_id' => ContextoInstitucion::requerido()] + $fila);
        }
        $cantidad = (int) ($d['ejemplares'] ?? 0);
        if ($cantidad > 0) {
            $this->agregarEjemplares($id, $cantidad, $d['estante'] ?? null, $d['origen'] ?? null);
        }

        return $this->ficha($id, true);
    }

    /** Crea $cantidad ejemplares con código propio (B{libro}-{n}). */
    public function agregarEjemplares(int $libroId, int $cantidad, ?string $estante, ?string $origen): array
    {
        abort_unless(DB::table('bib_libros')->where('id', $libroId)->exists(), 404);
        $n = (int) DB::table('bib_ejemplares')->where('bib_libro_id', $libroId)->count();
        $codigos = [];
        for ($i = 1; $i <= min($cantidad, 200); $i++) {
            $codigo = sprintf('B%d-%03d', $libroId, $n + $i);
            DB::table('bib_ejemplares')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'bib_libro_id' => $libroId, 'codigo' => $codigo, 'estante' => $estante, 'origen' => $origen, 'ingreso' => now()->toDateString()]);
            $codigos[] = $codigo;
        }

        return $codigos;
    }

    /** Título, autor y editorial por ISBN (Open Library); null si no responde o no lo encuentra. */
    public function porIsbn(string $isbn): ?array
    {
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
        if (! preg_match('/^(\d{9}[\dX]|\d{13})$/i', (string) $isbn)) {
            throw ValidationException::withMessages(['isbn' => ['El ISBN debe tener 10 o 13 dígitos.']]);
        }
        try {
            $r = Http::timeout(6)->get('https://openlibrary.org/api/books', ['bibkeys' => "ISBN:{$isbn}", 'format' => 'json', 'jscmd' => 'data']);
            $libro = $r->successful() ? ($r->json()["ISBN:{$isbn}"] ?? null) : null;
        } catch (Throwable) {
            $libro = null;
        }

        return $libro ? [
            'isbn' => $isbn, 'titulo' => $libro['title'] ?? null, 'autor' => collect($libro['authors'] ?? [])->pluck('name')->implode(', ') ?: null,
            'editorial' => $libro['publishers'][0]['name'] ?? null, 'anio_publicacion' => isset($libro['publish_date']) && preg_match('/(\d{4})/', $libro['publish_date'], $m) ? (int) $m[1] : null,
        ] : null;
    }

    /**
     * Importa el catálogo desde Excel: columnas titulo, autor, isbn, editorial, anio, categoria, tipo, nivel, ejemplares.
     *
     * @return array{creados: int, errores: list<string>}
     */
    public function importar(UploadedFile $archivo, int $usuarioId): array
    {
        $hoja = IOFactory::load($archivo->getRealPath())->getActiveSheet()->toArray(null, true, true, false);
        $encabezado = array_map(fn ($c) => mb_strtolower(trim((string) $c)), array_shift($hoja) ?? []);
        if (! in_array('titulo', $encabezado, true)) {
            throw ValidationException::withMessages(['archivo' => ['La primera fila debe tener los nombres de las columnas (al menos «titulo»).']]);
        }
        $creados = 0;
        $errores = [];
        foreach ($hoja as $i => $fila) {
            $f = array_combine($encabezado, array_pad(array_slice($fila, 0, count($encabezado)), count($encabezado), null));
            if (trim((string) ($f['titulo'] ?? '')) === '') {
                continue;
            }
            $tipo = in_array($f['tipo'] ?? '', self::TIPOS, true) ? $f['tipo'] : 'libro';
            $nivel = in_array($f['nivel'] ?? '', ['inicial', 'primaria', 'secundaria'], true) ? $f['nivel'] : 'todos';
            try {
                $this->guardarLibro(null, ['titulo' => mb_substr(trim((string) $f['titulo']), 0, 250), 'autor' => $f['autor'] ?? null, 'isbn' => $f['isbn'] ?? null, 'editorial' => $f['editorial'] ?? null,
                    'anio_publicacion' => is_numeric($f['anio'] ?? null) ? (int) $f['anio'] : null, 'categoria' => $f['categoria'] ?? null, 'tipo' => $tipo === 'ebook' ? 'libro' : $tipo, 'nivel' => $nivel,
                    'ejemplares' => max(0, (int) ($f['ejemplares'] ?? 1))], null, $usuarioId);
                $creados++;
            } catch (Throwable $e) {
                $errores[] = 'Fila '.($i + 2).': '.($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : 'no se pudo importar');
            }
        }

        return ['creados' => $creados, 'errores' => $errores];
    }

    // ───────────── Préstamos (EDUN-152 / 153)

    /** Lector por carné, DNI (alumno) o id de personal. */
    public function lector(?string $codigo, ?int $personalId): array
    {
        if ($personalId) {
            $p = DB::table('personal')->find($personalId);
            abort_if(! $p, 404, 'La persona no está en el personal.');

            return ['tipo' => 'personal', 'id' => (int) $p->id, 'nombre' => "{$p->apellidos}, {$p->nombres}"];
        }
        $a = $codigo ? app(Carnes::class)->buscar($codigo) : null;
        if (! $a) {
            throw ValidationException::withMessages(['codigo' => ['Carné o DNI no reconocido.']]);
        }

        return ['tipo' => 'alumno', 'id' => (int) $a['id'], 'nombre' => "{$a['apellido_paterno']}, {$a['nombres']}"];
    }

    public function prestar(string $codigoEjemplar, array $lector, int $usuarioId): array
    {
        return DB::transaction(function () use ($codigoEjemplar, $lector, $usuarioId) {
            $e = DB::table('bib_ejemplares')->where('codigo', strtoupper(trim($codigoEjemplar)))->lockForUpdate()->first();
            if (! $e) {
                throw ValidationException::withMessages(['ejemplar' => ['No hay un ejemplar con ese código.']]);
            }
            $reglas = $this->reglas();
            $reservaPropia = $e->estado === 'reservado' ? DB::table('bib_reservas')->where('bib_ejemplar_id', $e->id)->where('estado', 'disponible')->first() : null;
            if ($e->estado !== 'disponible' && ! ($reservaPropia && $lector['tipo'] === 'alumno' && (int) $reservaPropia->alumno_id === $lector['id'])) {
                throw ValidationException::withMessages(['ejemplar' => [$e->estado === 'reservado' ? 'Ese ejemplar está separado para quien lo reservó.' : 'Ese ejemplar no está disponible ('.$e->estado.').']]);
            }
            $suspension = DB::table('bib_suspensiones')->where('lector_tipo', $lector['tipo'])->where('lector_id', $lector['id'])->where('hasta', '>=', now()->toDateString())->max('hasta');
            if ($suspension) {
                throw ValidationException::withMessages(['lector' => ['El lector tiene préstamos suspendidos hasta el '.Carbon::parse($suspension)->format('d/m/Y').' por devolver con atraso.']]);
            }
            $activos = DB::table('bib_prestamos')->where('lector_tipo', $lector['tipo'])->where('lector_id', $lector['id'])->whereNull('devuelto_at')->count();
            if ($lector['tipo'] === 'alumno' && $activos >= $reglas->max_alumno) {
                throw ValidationException::withMessages(['lector' => ["El alumno ya tiene {$activos} libro(s): el máximo es {$reglas->max_alumno}."]]);
            }
            $vence = now()->addDays($lector['tipo'] === 'alumno' ? $reglas->dias_alumno : $reglas->dias_personal)->toDateString();
            $id = (int) DB::table('bib_prestamos')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'bib_ejemplar_id' => $e->id, 'lector_tipo' => $lector['tipo'],
                'lector_id' => $lector['id'], 'vence' => $vence, 'registrado_por' => $usuarioId]);
            DB::table('bib_ejemplares')->where('id', $e->id)->update(['estado' => 'prestado']);
            if ($reservaPropia) {
                DB::table('bib_reservas')->where('id', $reservaPropia->id)->update(['estado' => 'atendida']);
            }

            return ['id' => $id, 'vence' => $vence, 'libro' => DB::table('bib_libros')->where('id', $e->bib_libro_id)->value('titulo'), 'lector' => $lector['nombre']];
        });
    }

    /** Devuelve el ejemplar: suspende por atraso (sin multa) y separa el libro para la siguiente reserva. */
    public function devolver(string $codigoEjemplar): array
    {
        return DB::transaction(function () use ($codigoEjemplar) {
            $e = DB::table('bib_ejemplares')->where('codigo', strtoupper(trim($codigoEjemplar)))->lockForUpdate()->first();
            abort_if(! $e, 404, 'No hay un ejemplar con ese código.');
            $p = DB::table('bib_prestamos')->where('bib_ejemplar_id', $e->id)->whereNull('devuelto_at')->first();
            if (! $p) {
                throw ValidationException::withMessages(['ejemplar' => ['Ese ejemplar no está prestado.']]);
            }
            DB::table('bib_prestamos')->where('id', $p->id)->update(['devuelto_at' => now()]);
            $atraso = max(0, (int) Carbon::parse($p->vence)->startOfDay()->diffInDays(now()->startOfDay(), false));
            $suspendidoHasta = null;
            if ($atraso > 0 && $this->reglas()->suspension_por_atraso) {
                $suspendidoHasta = now()->addDays($atraso)->toDateString();
                DB::table('bib_suspensiones')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'lector_tipo' => $p->lector_tipo, 'lector_id' => $p->lector_id, 'hasta' => $suspendidoHasta, 'bib_prestamo_id' => $p->id]);
            }
            $reserva = DB::table('bib_reservas')->where('bib_libro_id', $e->bib_libro_id)->where('estado', 'en_espera')->orderBy('id')->first();
            if ($reserva) {
                DB::table('bib_reservas')->where('id', $reserva->id)->update(['estado' => 'disponible', 'bib_ejemplar_id' => $e->id, 'avisado_at' => now()]);
                DB::table('bib_ejemplares')->where('id', $e->id)->update(['estado' => 'reservado']);
                $titulo = DB::table('bib_libros')->where('id', $e->bib_libro_id)->value('titulo');
                $this->notificador->enviar($this->usuariosDeAlumno((int) $reserva->alumno_id), ['app'], 'comunicados', 'Tu reserva está lista', "«{$titulo}» te espera en la biblioteca.", ['pantalla' => 'biblioteca']);
            } else {
                DB::table('bib_ejemplares')->where('id', $e->id)->update(['estado' => 'disponible']);
            }

            return ['atraso_dias' => $atraso, 'suspendido_hasta' => $suspendidoHasta, 'reservado' => (bool) $reserva];
        });
    }

    public function renovar(int $prestamoId): array
    {
        $p = DB::table('bib_prestamos')->where('id', $prestamoId)->whereNull('devuelto_at')->first();
        abort_if(! $p, 404);
        $r = $this->reglas();
        if ($p->renovaciones >= $r->renovaciones_max) {
            throw ValidationException::withMessages(['prestamo' => ['Ya se renovó el máximo de veces.']]);
        }
        if ($p->vence < now()->toDateString()) {
            throw ValidationException::withMessages(['prestamo' => ['Un préstamo vencido no se renueva: devuélvelo primero.']]);
        }
        $libro = DB::table('bib_ejemplares')->where('id', $p->bib_ejemplar_id)->value('bib_libro_id');
        if (DB::table('bib_reservas')->where('bib_libro_id', $libro)->where('estado', 'en_espera')->exists()) {
            throw ValidationException::withMessages(['prestamo' => ['Otro lector reservó este libro: no se puede renovar.']]);
        }
        $vence = Carbon::parse($p->vence)->addDays($p->lector_tipo === 'alumno' ? $r->dias_alumno : $r->dias_personal)->toDateString();
        DB::table('bib_prestamos')->where('id', $prestamoId)->update(['vence' => $vence, 'renovaciones' => $p->renovaciones + 1]);

        return ['vence' => $vence];
    }

    public function prestamosActivos(?bool $soloVencidos): array
    {
        return DB::table('bib_prestamos as p')->join('bib_ejemplares as e', 'e.id', '=', 'p.bib_ejemplar_id')->join('bib_libros as l', 'l.id', '=', 'e.bib_libro_id')
            ->whereNull('p.devuelto_at')->when($soloVencidos, fn ($q) => $q->where('p.vence', '<', now()->toDateString()))->orderBy('p.vence')->limit(500)
            ->get(['p.id', 'p.lector_tipo', 'p.lector_id', 'p.prestado_at', 'p.vence', 'p.renovaciones', 'e.codigo', 'l.titulo'])
            ->map(fn ($p) => array_merge((array) $p, ['lector' => $this->nombreLector($p->lector_tipo, (int) $p->lector_id), 'vencido' => $p->vence < now()->toDateString()]))->all();
    }

    /** Aviso diario de préstamos vencidos al alumno y a su familia (bib:vencidos). */
    public function avisarVencidos(): int
    {
        $hoy = now()->toDateString();
        $vencidos = DB::table('bib_prestamos as p')->join('bib_ejemplares as e', 'e.id', '=', 'p.bib_ejemplar_id')->join('bib_libros as l', 'l.id', '=', 'e.bib_libro_id')
            ->whereNull('p.devuelto_at')->where('p.vence', '<', $hoy)->where('p.lector_tipo', 'alumno')->where(fn ($q) => $q->whereNull('p.avisado_at')->orWhere('p.avisado_at', '<', $hoy))
            ->get(['p.id', 'p.lector_id', 'p.vence', 'l.titulo']);
        foreach ($vencidos as $v) {
            $this->notificador->enviar($this->usuariosDeAlumno((int) $v->lector_id), ['app'], 'comunicados', 'Libro por devolver', "«{$v->titulo}» venció el ".Carbon::parse($v->vence)->format('d/m').'. Devuélvelo a la biblioteca.', ['pantalla' => 'biblioteca']);
            DB::table('bib_prestamos')->where('id', $v->id)->update(['avisado_at' => $hoy]);
        }
        // Libros digitales: se devuelven solos al vencer.
        DB::table('bib_lecturas_digitales')->whereNull('devuelto_at')->where('vence', '<', now())->update(['devuelto_at' => now()]);

        return $vencidos->count();
    }

    // ───────────── Reservas (EDUN-154)

    public function reservar(int $libroId, int $alumnoId): array
    {
        if (! $this->reglas()->reservas_app) {
            throw ValidationException::withMessages(['reserva' => ['La biblioteca no recibe reservas desde la app.']]);
        }
        abort_unless(DB::table('bib_libros')->where('id', $libroId)->where('tipo', '!=', 'ebook')->exists(), 404);
        if (DB::table('bib_reservas')->where('bib_libro_id', $libroId)->where('alumno_id', $alumnoId)->whereIn('estado', ['en_espera', 'disponible'])->exists()) {
            throw ValidationException::withMessages(['reserva' => ['Ya tienes una reserva de este libro.']]);
        }
        $id = (int) DB::table('bib_reservas')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'bib_libro_id' => $libroId, 'alumno_id' => $alumnoId]);
        // Si hay un ejemplar disponible, se separa al momento.
        $e = DB::table('bib_ejemplares')->where('bib_libro_id', $libroId)->where('estado', 'disponible')->orderBy('id')->lockForUpdate()->first();
        if ($e) {
            DB::table('bib_ejemplares')->where('id', $e->id)->update(['estado' => 'reservado']);
            DB::table('bib_reservas')->where('id', $id)->update(['estado' => 'disponible', 'bib_ejemplar_id' => $e->id, 'avisado_at' => now()]);
        }

        return (array) DB::table('bib_reservas')->find($id, ['id', 'estado', 'bib_libro_id']);
    }

    public function cancelarReserva(int $id, array $alumnos): void
    {
        $r = DB::table('bib_reservas')->where('id', $id)->whereIn('alumno_id', $alumnos ?: [0])->whereIn('estado', ['en_espera', 'disponible'])->first();
        abort_if(! $r, 404);
        DB::table('bib_reservas')->where('id', $id)->update(['estado' => 'cancelada']);
        if ($r->bib_ejemplar_id) {
            DB::table('bib_ejemplares')->where('id', $r->bib_ejemplar_id)->where('estado', 'reservado')->update(['estado' => 'disponible']);
        }
    }

    // ───────────── Plan lector (EDUN-156)

    public function planLector(?int $gradoId, ?int $periodoId): array
    {
        return DB::table('bib_plan_lector as pl')->join('bib_libros as l', 'l.id', '=', 'pl.bib_libro_id')->join('grados as g', 'g.id', '=', 'pl.grado_id')->join('periodos as pe', 'pe.id', '=', 'pl.periodo_id')
            ->when($gradoId, fn ($q, $v) => $q->where('pl.grado_id', $v))->when($periodoId, fn ($q, $v) => $q->where('pl.periodo_id', $v))->orderBy('g.nivel')->orderBy('g.numero')->orderBy('pe.numero')
            ->get(['pl.id', 'pl.grado_id', 'pl.periodo_id', 'l.id as libro_id', 'l.titulo', 'l.autor', 'g.nombre as grado', 'pe.nombre as periodo',
                DB::raw("(select count(*) from bib_plan_lector_avance a where a.bib_plan_lector_id = pl.id and a.estado = 'terminado') as terminados")])
            ->map(fn ($p) => array_merge((array) $p, ['terminados' => (int) $p->terminados]))->all();
    }

    public function agregarPlan(int $gradoId, int $periodoId, int $libroId, ?int $docenteId): array
    {
        abort_unless(DB::table('bib_libros')->where('id', $libroId)->exists() && DB::table('grados')->where('id', $gradoId)->exists() && DB::table('periodos')->where('id', $periodoId)->exists(), 404);
        if (DB::table('bib_plan_lector')->where('grado_id', $gradoId)->where('periodo_id', $periodoId)->where('bib_libro_id', $libroId)->exists()) {
            throw ValidationException::withMessages(['bib_libro_id' => ['Ese libro ya está en el plan de ese grado y periodo.']]);
        }
        $id = (int) DB::table('bib_plan_lector')->insertGetId(['institucion_id' => ContextoInstitucion::requerido(), 'grado_id' => $gradoId, 'periodo_id' => $periodoId, 'bib_libro_id' => $libroId, 'docente_id' => $docenteId]);

        return collect($this->planLector($gradoId, $periodoId))->firstWhere('id', $id);
    }

    /** Avance de los alumnos del grado en un libro del plan. */
    public function avance(int $planId): array
    {
        $p = DB::table('bib_plan_lector')->find($planId);
        abort_if(! $p, 404);
        $estados = DB::table('bib_plan_lector_avance')->where('bib_plan_lector_id', $planId)->pluck('estado', 'alumno_id');

        return DB::table('matriculas as m')->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('alumnos as a', 'a.id', '=', 'm.alumno_id')
            ->where('s.grado_id', $p->grado_id)->where('m.estado', 'matriculado')->orderBy('s.nombre')->orderBy('a.apellido_paterno')
            ->get(['a.id', DB::raw("a.apellido_paterno || ', ' || a.nombres as alumno"), 's.nombre as seccion'])
            ->map(fn ($a) => ['alumno_id' => (int) $a->id, 'alumno' => $a->alumno, 'seccion' => $a->seccion, 'estado' => $estados[$a->id] ?? 'sin_empezar'])->all();
    }

    public function marcarAvance(int $planId, int $alumnoId, string $estado): void
    {
        abort_unless(collect($this->avance($planId))->contains('alumno_id', $alumnoId), 404);
        DB::table('bib_plan_lector_avance')->upsert([['institucion_id' => ContextoInstitucion::requerido(), 'bib_plan_lector_id' => $planId, 'alumno_id' => $alumnoId, 'estado' => $estado]],
            ['bib_plan_lector_id', 'alumno_id'], ['estado']);
    }

    public function recordarPlan(int $planId): int
    {
        $titulo = DB::table('bib_plan_lector as p')->join('bib_libros as l', 'l.id', '=', 'p.bib_libro_id')->where('p.id', $planId)->value('l.titulo');
        $faltan = collect($this->avance($planId))->where('estado', '!=', 'terminado')->pluck('alumno_id')->all();
        $usuarios = collect($faltan)->flatMap(fn ($a) => $this->usuariosDeAlumno((int) $a))->unique()->values()->all();

        return $this->notificador->enviar($usuarios, ['app'], 'tareas', 'Plan lector', "Recuerda leer «{$titulo}».", ['pantalla' => 'biblioteca'])['app'];
    }

    // ───────────── Libros digitales (EDUN-157)

    /** Abre (o presta) un libro digital respetando las licencias; devuelve un enlace que vence en minutos. */
    public function leerDigital(int $libroId, User $usuario): array
    {
        return DB::transaction(function () use ($libroId, $usuario) {
            $l = DB::table('bib_libros')->where('id', $libroId)->where('tipo', 'ebook')->lockForUpdate()->first();
            abort_if(! $l || ! $l->archivo_id, 404, 'El libro digital no existe.');
            DB::table('bib_lecturas_digitales')->where('bib_libro_id', $libroId)->whereNull('devuelto_at')->where('vence', '<', now())->update(['devuelto_at' => now()]);
            $mia = DB::table('bib_lecturas_digitales')->where('bib_libro_id', $libroId)->where('user_id', $usuario->id)->whereNull('devuelto_at')->first();
            if (! $mia) {
                $enUso = DB::table('bib_lecturas_digitales')->where('bib_libro_id', $libroId)->whereNull('devuelto_at')->count();
                if ($l->licencias !== null && $enUso >= $l->licencias) {
                    throw ValidationException::withMessages(['libro' => ['Todas las licencias de este libro están en uso. Intenta más tarde o resérvalo.']]);
                }
                DB::table('bib_lecturas_digitales')->insert(['institucion_id' => ContextoInstitucion::requerido(), 'bib_libro_id' => $libroId, 'user_id' => $usuario->id,
                    'vence' => now()->addDays($this->reglas()->dias_alumno)]);
                $mia = DB::table('bib_lecturas_digitales')->where('bib_libro_id', $libroId)->where('user_id', $usuario->id)->whereNull('devuelto_at')->first();
            }

            return ['url' => $this->almacen->urlTemporal(Archivo::query()->findOrFail($l->archivo_id)), 'vence' => $mia->vence];
        });
    }

    public function devolverDigital(int $libroId, User $usuario): void
    {
        DB::table('bib_lecturas_digitales')->where('bib_libro_id', $libroId)->where('user_id', $usuario->id)->whereNull('devuelto_at')->update(['devuelto_at' => now()]);
    }

    // ───────────── Inventario y bajas (EDUN-158)

    /** Toma de inventario de un estante: marca los leídos y devuelve los que faltan. */
    public function inventario(string $estante, array $codigos): array
    {
        $codigos = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $codigos)));
        $vistos = DB::table('bib_ejemplares')->whereIn('codigo', $codigos ?: ['-'])->get(['id', 'codigo', 'estante']);
        DB::table('bib_ejemplares')->whereIn('id', $vistos->pluck('id'))->update(['inventariado_at' => now(), 'estante' => $estante]);
        $faltan = DB::table('bib_ejemplares as e')->join('bib_libros as l', 'l.id', '=', 'e.bib_libro_id')->where('e.estante', $estante)->whereNotIn('e.codigo', $codigos ?: ['-'])
            ->whereIn('e.estado', ['disponible', 'reservado'])->get(['e.codigo', 'l.titulo'])->map(fn ($x) => (array) $x)->all();

        return ['leidos' => $vistos->count(), 'desconocidos' => array_values(array_diff($codigos, $vistos->pluck('codigo')->all())), 'faltan' => $faltan];
    }

    public function actualizarEjemplar(int $id, ?string $conservacion, bool $baja, ?string $motivo): void
    {
        $e = DB::table('bib_ejemplares')->find($id);
        abort_if(! $e, 404);
        if ($baja && $e->estado === 'prestado') {
            throw ValidationException::withMessages(['ejemplar' => ['El ejemplar está prestado: regístralo como devuelto antes de darlo de baja.']]);
        }
        if ($baja && trim((string) $motivo) === '') {
            throw ValidationException::withMessages(['motivo' => ['Indica el motivo de la baja.']]);
        }
        DB::table('bib_ejemplares')->where('id', $id)->update(array_filter(['conservacion' => $conservacion, 'estado' => $baja ? 'baja' : null, 'baja_motivo' => $baja ? mb_substr(trim((string) $motivo), 0, 200) : null]));
        if ($baja) {
            $this->auditoria->registrar('ejemplar_baja', 'bib_ejemplares', $id, ['estado' => $e->estado], ['estado' => 'baja', 'motivo' => trim((string) $motivo)]);
        }
    }

    /** PDF de etiquetas (24 por hoja A4) con el código y su QR, para pegar en el lomo. */
    public function etiquetas(array $ejemplarIds): string
    {
        $ej = DB::table('bib_ejemplares as e')->join('bib_libros as l', 'l.id', '=', 'e.bib_libro_id')->whereIn('e.id', $ejemplarIds)->orderBy('e.codigo')->get(['e.codigo', 'l.titulo']);
        $escribir = new Writer(new ImageRenderer(new RendererStyle(120, 1), new SvgImageBackEnd));
        $esc = fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $celdas = $ej->map(fn ($e) => '<div class="et"><img src="data:image/svg+xml;base64,'.base64_encode($escribir->writeString($e->codigo)).'"><b>'.$esc($e->codigo).'</b><br><small>'.$esc(mb_strimwidth($e->titulo, 0, 40, '…')).'</small></div>')->implode('');
        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml('<html><head><meta charset="utf-8"><style>@page{margin:10mm}body{font-family:DejaVu Sans;font-size:8px}.et{display:inline-block;width:45mm;height:34mm;margin:1mm;text-align:center;border:0.2mm dashed #cbd5e1;vertical-align:top}.et img{width:20mm;height:20mm;display:block;margin:1mm auto}</style></head><body>'.$celdas.'</body></html>', 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    // ───────────── Estadísticas (EDUN-160)

    public function estadisticas(): array
    {
        $desde = now()->subMonths(11)->startOfMonth();

        return [
            'por_mes' => DB::table('bib_prestamos')->where('prestado_at', '>=', $desde)
                ->selectRaw("to_char(prestado_at at time zone ?, 'YYYY-MM') as mes, count(*) as prestamos", [config('app.timezone')])->groupBy('mes')->orderBy('mes')->get()
                ->map(fn ($m) => ['mes' => $m->mes, 'prestamos' => (int) $m->prestamos])->all(),
            'mas_leidos' => DB::table('bib_prestamos as p')->join('bib_ejemplares as e', 'e.id', '=', 'p.bib_ejemplar_id')->join('bib_libros as l', 'l.id', '=', 'e.bib_libro_id')
                ->where('p.prestado_at', '>=', $desde)->groupBy('l.id', 'l.titulo', 'l.autor')->orderByDesc(DB::raw('count(*)'))->limit(10)
                ->get(['l.titulo', 'l.autor', DB::raw('count(*) as prestamos')])->map(fn ($l) => ['titulo' => $l->titulo, 'autor' => $l->autor, 'prestamos' => (int) $l->prestamos])->all(),
            'lectores_por_nivel' => DB::table('bib_prestamos as p')->join('matriculas as m', fn ($j) => $j->on('m.alumno_id', '=', 'p.lector_id')->where('m.estado', '=', 'matriculado'))
                ->join('secciones as s', 's.id', '=', 'm.seccion_id')->join('grados as g', 'g.id', '=', 's.grado_id')->where('p.lector_tipo', 'alumno')->where('p.prestado_at', '>=', $desde)
                ->groupBy('g.nivel')->get(['g.nivel', DB::raw('count(distinct p.lector_id) as lectores')])->map(fn ($n) => ['nivel' => $n->nivel, 'lectores' => (int) $n->lectores])->all(),
        ];
    }

    // ───────────── Familia y alumno

    public function deFamilia(array $alumnos, int $userId): array
    {
        return [
            'prestamos' => DB::table('bib_prestamos as p')->join('bib_ejemplares as e', 'e.id', '=', 'p.bib_ejemplar_id')->join('bib_libros as l', 'l.id', '=', 'e.bib_libro_id')
                ->join('alumnos as a', 'a.id', '=', 'p.lector_id')->where('p.lector_tipo', 'alumno')->whereIn('p.lector_id', $alumnos ?: [0])->whereNull('p.devuelto_at')
                ->get(['p.id', 'l.titulo', 'p.vence', 'a.nombres as alumno'])->map(fn ($x) => array_merge((array) $x, ['vencido' => $x->vence < now()->toDateString()]))->all(),
            'reservas' => DB::table('bib_reservas as r')->join('bib_libros as l', 'l.id', '=', 'r.bib_libro_id')->join('alumnos as a', 'a.id', '=', 'r.alumno_id')
                ->whereIn('r.alumno_id', $alumnos ?: [0])->whereIn('r.estado', ['en_espera', 'disponible'])->get(['r.id', 'r.estado', 'l.titulo', 'a.nombres as alumno'])->map(fn ($x) => (array) $x)->all(),
            'plan_lector' => DB::table('bib_plan_lector as pl')->join('bib_libros as l', 'l.id', '=', 'pl.bib_libro_id')->join('secciones as s', 's.grado_id', '=', 'pl.grado_id')
                ->join('matriculas as m', fn ($j) => $j->on('m.seccion_id', '=', 's.id')->where('m.estado', '=', 'matriculado'))->join('alumnos as a', 'a.id', '=', 'm.alumno_id')
                ->leftJoin('bib_plan_lector_avance as av', fn ($j) => $j->on('av.bib_plan_lector_id', '=', 'pl.id')->on('av.alumno_id', '=', 'm.alumno_id'))
                ->join('periodos as pe', 'pe.id', '=', 'pl.periodo_id')->whereIn('m.alumno_id', $alumnos ?: [0])->orderBy('pe.numero')
                ->get(['l.titulo', 'l.autor', 'pe.nombre as periodo', 'a.nombres as alumno', DB::raw("coalesce(av.estado, 'sin_empezar') as estado")])->map(fn ($x) => (array) $x)->all(),
            'digitales' => DB::table('bib_lecturas_digitales as d')->join('bib_libros as l', 'l.id', '=', 'd.bib_libro_id')->where('d.user_id', $userId)->whereNull('d.devuelto_at')->where('d.vence', '>', now())
                ->get(['l.id', 'l.titulo', 'd.vence'])->map(fn ($x) => (array) $x)->all(),
        ];
    }

    private function nombreLector(string $tipo, int $id): string
    {
        return (string) ($tipo === 'alumno'
            ? DB::table('alumnos')->where('id', $id)->value(DB::raw("apellido_paterno || ', ' || nombres"))
            : DB::table('personal')->where('id', $id)->value(DB::raw("apellidos || ', ' || nombres")));
    }

    /** @return list<int> usuario del alumno (si tiene) y de su familia */
    private function usuariosDeAlumno(int $alumnoId): array
    {
        return DB::table('alumno_apoderado as aa')->join('apoderados as p', 'p.id', '=', 'aa.apoderado_id')->where('aa.alumno_id', $alumnoId)->whereNotNull('p.user_id')->pluck('p.user_id')
            ->merge(DB::table('alumnos')->where('id', $alumnoId)->whereNotNull('user_id')->pluck('user_id'))->map(fn ($i) => (int) $i)->unique()->values()->all();
    }
}
