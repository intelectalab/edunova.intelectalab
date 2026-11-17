<?php
// Los usuarios con auth «edunova» no tienen contraseña en Moodle: entran solo con el enlace firmado.
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

class auth_plugin_edunova extends auth_plugin_base {
    public function __construct() {
        $this->authtype = 'edunova';
        $this->config = get_config('auth_edunova');
    }

    /** No se aceptan contraseñas: el ingreso es por login.php con la firma de Edunova. */
    public function user_login($username, $password) {
        return false;
    }

    public function is_internal() {
        return false;
    }

    public function can_change_password() {
        return false;
    }

    public function prevent_local_passwords() {
        return true;
    }
}
