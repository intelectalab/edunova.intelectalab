<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configpasswordunmask('auth_edunova/secreto',
        get_string('secreto', 'auth_edunova'), get_string('secreto_desc', 'auth_edunova'), ''));
}
