<?php
// prueba de variables de entorno, eliminar al pasar a rpoduccion final.
//$local_env = __DIR__ . '/env.local.php';
//if (file_exists($local_env)) {
//    require_once $local_env;
//}

//
define('ENTORNO', getenv('APP_ENV') ?: 'produccion');

define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));


if (!DB_HOST || !DB_NAME || !DB_USER) {
    error_log('[EUNACOM Config] Variables de entorno de base de datos no configuradas');
    if (ENTORNO !== 'produccion') {
        die('Error: Variables de entorno de base de datos no configuradas (DB_HOST, DB_NAME, DB_USER, DB_PASS)');
    } else {
        die('Error de configuración. Por favor contacte al administrador.');
    }
}

define('BASE_URL', getenv('APP_URL') ?: '/');
define('MATERIALES_URL', getenv('MATERIALES_URL') ?: (BASE_URL . 'materiales'));


define('SESSION_LIFETIME', getenv('SESSION_LIFETIME') ?: 86400); 

// Configuración de caché
define('CACHE_ENABLED', getenv('CACHE_ENABLED') !== 'false'); // Activado por defecto
define('CACHE_TTL_STATS', 300); // 5 minutos para estadísticas del sistema
define('CACHE_TTL_SHORT', 60);  // 1 minuto para datos que cambian frecuentemente

define('MYSQL_STRICT_MODE', true);

// ============================================
// CONFIGURACIÓN DE LOGGING
// ============================================
define('LOG_ENABLED', true);
define('LOG_TO_FILE', true);
define('LOG_TO_DB', true);
define('LOG_LEVEL', ENTORNO === 'desarrollo' ? 'DEBUG' : 'WARNING');

// Días a mantener logs en archivo
define('LOG_RETENTION_DAYS', 30);

if (ENTORNO === 'desarrollo') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);

} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

}

date_default_timezone_set('America/Santiago');


spl_autoload_register(function ($class_name) {
    $file = __DIR__ . '/../classes/' . $class_name . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});


function buildUrl($path = '') {
    return BASE_URL . ltrim($path, '/');
}

function buildMaterialUrl($path = '') {
    return MATERIALES_URL . '/' . ltrim($path, '/');
}

function redirect($url, $code = 302) {
    header("Location: " . $url, true, $code);
    exit;
}


function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}


function formatBytes($bytes, $precision = 2) {
    $bytes = (int)$bytes;
    
    if ($bytes <= 0) {
        return '0 B';
    }
    
    $units = array('KB', 'MB', 'GB', 'TB');
    $factor = floor(log($bytes, 1024));
    
    if ($factor >= count($units)) {
        $factor = count($units) - 1;
    }
    
    return sprintf("%.{$precision}f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
}

function getDB() {
    return Database::getInstance()->getConnection();
}

define('SITE_NAME', 'EUNACOM');
define('SITE_VERSION', '1.0.0');
define('MAINTENANCE_MODE', getenv('MAINTENANCE_MODE') === 'true');

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: (BASE_URL . 'google-callback.php'));
