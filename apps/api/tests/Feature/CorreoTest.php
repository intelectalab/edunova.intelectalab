<?php

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\Compartido\Correo\EnviarCorreo;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
| EDUN-208 · Correo transaccional: sale por cola, queda en bitácora, y los rebotes y
| quejas detienen los envíos a esa dirección.
*/

class CorreoDePrueba extends Mailable
{
    public function build(): static
    {
        return $this->subject('Confirmación de reclamo')->html('<p>Recibimos tu reclamo.</p>');
    }
}

function bitacora(int $id): object
{
    return DB::connection('plataforma')->table('correos_bitacora')->find($id);
}

function firmar(string $cuerpo): string
{
    return hash_hmac('sha256', $cuerpo, (string) config('services.correo.webhook_secreto'));
}

beforeEach(function () {
    DB::connection('plataforma')->table('correos_suprimidos')->delete();
});

it('el correo entra a la cola y queda en la bitácora', function () {
    Queue::fake();

    $id = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, ' Ana@Colegio.PE ', 'reclamo_confirmacion');

    Queue::assertPushedOn('correo', EnviarCorreo::class, fn ($job) => $job->bitacoraId === $id && $job->destinatario === 'ana@colegio.pe');
    expect(bitacora($id))->estado->toBe('en_cola')->plantilla->toBe('reclamo_confirmacion')->destinatario->toBe('ana@colegio.pe');
});

it('el job envía el correo y marca la bitácora como enviada', function () {
    Mail::fake();
    Queue::fake();
    $id = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'ana@colegio.pe', 'reclamo_confirmacion');

    (new EnviarCorreo($id, new CorreoDePrueba, 'ana@colegio.pe'))->handle();

    Mail::assertSent(CorreoDePrueba::class, fn ($m) => $m->hasTo('ana@colegio.pe'));
    expect(bitacora($id))->estado->toBe('enviado')->intentos->toBe(1)->enviado_at->not->toBeNull();
});

it('es idempotente: un correo ya enviado no se vuelve a enviar', function () {
    Mail::fake();
    Queue::fake();
    $id = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'ana@colegio.pe', 'x');
    $job = new EnviarCorreo($id, new CorreoDePrueba, 'ana@colegio.pe');

    $job->handle();
    $job->handle();

    Mail::assertSentCount(1);
});

it('si el envío falla tras los reintentos, la bitácora queda como fallida con el error', function () {
    Queue::fake();
    $id = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'ana@colegio.pe', 'x');

    (new EnviarCorreo($id, new CorreoDePrueba, 'ana@colegio.pe'))->failed(new RuntimeException('SMTP no responde'));

    expect(bitacora($id))->estado->toBe('fallido')->error->toBe('SMTP no responde');
});

it('el webhook rechaza eventos sin firma válida', function () {
    $cuerpo = json_encode(['eventos' => [['tipo' => 'rebote', 'email' => 'ana@colegio.pe']]]);

    $this->call('POST', '/api/v1/correo/eventos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EDUNOVA_FIRMA' => 'falsa'], $cuerpo)
        ->assertUnauthorized();

    expect(CorreoTransaccional::estaSuprimido('ana@colegio.pe'))->toBeFalse();
});

it('un rebote registrado detiene los nuevos envíos a esa dirección', function () {
    Mail::fake();
    Queue::fake();
    $previo = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'ana@colegio.pe', 'x');
    (new EnviarCorreo($previo, new CorreoDePrueba, 'ana@colegio.pe'))->handle();

    $cuerpo = json_encode(['eventos' => [['tipo' => 'rebote', 'email' => 'ANA@colegio.pe', 'detalle' => '550 buzón inexistente']]]);
    $this->call('POST', '/api/v1/correo/eventos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EDUNOVA_FIRMA' => firmar($cuerpo)], $cuerpo)
        ->assertOk()->assertJson(['registrados' => 1]);

    expect(bitacora($previo)->estado)->toBe('rebotado');

    Queue::fake();
    $nuevo = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'ana@colegio.pe', 'x');
    Queue::assertNothingPushed();
    expect(bitacora($nuevo)->estado)->toBe('suprimido');
});

it('una queja que llega con el correo ya en cola impide el envío', function () {
    Mail::fake();
    Queue::fake();
    $id = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'ana@colegio.pe', 'x');
    CorreoTransaccional::suprimir('ana@colegio.pe', 'queja');

    (new EnviarCorreo($id, new CorreoDePrueba, 'ana@colegio.pe'))->handle();

    Mail::assertNothingSent();
    expect(bitacora($id)->estado)->toBe('suprimido');
});

it('el colegio solo ve su bitácora y la app no ve la lista de supresión', function () {
    Queue::fake();
    $colegio = (int) DB::connection('propietario')->table('instituciones')->value('id');
    $propio = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'a@x.pe', 'x', $colegio);
    $ajeno = app(CorreoTransaccional::class)->enviar(new CorreoDePrueba, 'b@x.pe', 'x');
    CorreoTransaccional::suprimir('c@x.pe', 'manual');

    DB::transaction(function () use ($colegio, $propio, $ajeno) {
        ContextoInstitucion::fijar($colegio);
        $visibles = DB::table('correos_bitacora')->pluck('id')->all();
        expect($visibles)->toContain($propio)->not->toContain($ajeno);
    });

    expect(fn () => DB::table('correos_suprimidos')->count())->toThrow(QueryException::class);
});
