<?php
// Plugin de autenticación «Edunova»: ingreso único desde Edunova (enlace firmado de un minuto).
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'auth_edunova';
$plugin->version = 2026111600;
$plugin->requires = 2022112800; // Moodle 4.1 o superior.
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0';
