<?php
/**
 * ============================================
 * CERRAR SESIÓN
 * ============================================
 */

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

// Cerrar sesión
cerrarSesion();

// Redirigir al login con mensaje
redirect(buildUrl('login.php?logout=1'));