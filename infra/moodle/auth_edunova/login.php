<?php
// Ingreso único desde Edunova: /auth/edunova/login.php?u=usuario&t=vence&s=firma[&curso=id]
// La firma es HMAC-SHA256 de «usuario|vence» con el secreto que muestra Edunova al generarlo.
// El enlace vence en un minuto y solo sirve para usuarios creados por Edunova (auth = edunova).
require_once(__DIR__ . '/../../config.php');

$usuario = required_param('u', PARAM_USERNAME);
$vence = required_param('t', PARAM_INT);
$firma = required_param('s', PARAM_ALPHANUM);
$curso = optional_param('curso', 0, PARAM_INT);

$secreto = (string) get_config('auth_edunova', 'secreto');
if ($secreto === '' || !is_enabled_auth('edunova')) {
    throw new moodle_exception('noconfigurado', 'auth_edunova');
}
if ($vence < time() || $vence > time() + 120) {
    throw new moodle_exception('vencido', 'auth_edunova');
}
$esperada = hash_hmac('sha256', $usuario . '|' . $vence, $secreto);
if (!hash_equals($esperada, $firma)) {
    throw new moodle_exception('firmainvalida', 'auth_edunova');
}

$user = $DB->get_record('user', ['username' => $usuario, 'auth' => 'edunova', 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$user || $user->suspended) {
    throw new moodle_exception('sinusuario', 'auth_edunova');
}

complete_user_login($user);
\core\session\manager::apply_concurrent_login_limit($user->id, session_id());

redirect($curso ? new moodle_url('/course/view.php', ['id' => $curso]) : new moodle_url('/my/'));
