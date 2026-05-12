<?php
/**
 * ============================================
 * CONTROLADOR DE DESCARGAS CON RATE LIMITING
 * ============================================
 * 
 * LINEAMIENTOS DE SEGURIDAD IMPLEMENTADOS:
 * - Autenticación requerida (requireAuth)
 * - Prepared statements (SQL injection)
 * - Validación de entrada
 * - Integración con Logger
 * - Rate limiting
 * - Verificación de referer
 * - Escape de salida (XSS)
 * ============================================
 */

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/classes/RateLimiter.php';
require_once __DIR__ . '/classes/Logger.php';

// ============================================
// SEGURIDAD: Requiere autenticación
// ============================================
requireAuth();

$usuario = getCurrentUser();
$usuario_id = $usuario['id'];
$ip = getClientIP();

$pdo = getDB();
$rateLimiter = new RateLimiter($pdo);

// ============================================
// VALIDACIÓN DE ENTRADA
// ============================================
$tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validar parámetros
$tipos_validos = array('pdf', 'zip_esp', 'zip_area');
if (empty($tipo) || !in_array($tipo, $tipos_validos) || $id <= 0) {
    Logger::warning("Descarga: parámetros inválidos", array(
        'tipo' => $tipo,
        'id' => $id,
        'usuario_id' => $usuario_id
    ));
    mostrarError('Parámetros de descarga inválidos');
}

// ============================================
// VERIFICACIÓN DE REFERER (Protección adicional)
// ============================================
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$sitio_valido = false;

if (!empty($referer)) {
    $parsed = parse_url($referer);
    $host_referer = isset($parsed['host']) ? $parsed['host'] : '';
    $host_actual = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    
    // Verificar que el referer sea del mismo dominio
    if ($host_referer === $host_actual) {
        $sitio_valido = true;
    }
}

// Si no hay referer válido, registrar pero permitir (algunos navegadores bloquean referer)
if (!$sitio_valido && !empty($referer)) {
    Logger::warning("Descarga: referer externo", array(
        'referer' => $referer,
        'usuario_id' => $usuario_id,
        'tipo' => $tipo,
        'id' => $id
    ));
}

// ============================================
// OBTENER INFORMACIÓN DEL ARCHIVO
// ============================================
$archivo_info = null;
$tipo_rate_limit = '';
$ruta_archivo = '';
$nombre_descarga = '';

switch ($tipo) {
    case 'pdf':
        $tipo_rate_limit = 'pdf';
        $sql = "SELECT id, nombre_documento, nombre_archivo, ruta_relativa 
                FROM documentos_estudio 
                WHERE id = ? AND activo = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($id));
        $archivo_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($archivo_info) {
            $ruta_archivo = $_SERVER['DOCUMENT_ROOT'] . '/materiales/' . ltrim($archivo_info['ruta_relativa'], '/');
            $nombre_descarga = $archivo_info['nombre_archivo'];
        }
        break;
        
    case 'zip_esp':
        $tipo_rate_limit = 'zip_especialidad';
        $sql = "SELECT id, nombre_zip, ruta_zip, tamano_kb 
                FROM zips_materiales 
                WHERE id = ? AND nivel = 'especialidad' AND activo = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($id));
        $archivo_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($archivo_info) {
            $ruta_archivo = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($archivo_info['ruta_zip'], '/');
            $nombre_descarga = $archivo_info['nombre_zip'];
        }
        break;
        
    case 'zip_area':
        $tipo_rate_limit = 'zip_area';
        $sql = "SELECT id, nombre_zip, ruta_zip, tamano_kb 
                FROM zips_materiales 
                WHERE id = ? AND nivel = 'area' AND activo = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($id));
        $archivo_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($archivo_info) {
            $ruta_archivo = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($archivo_info['ruta_zip'], '/');
            $nombre_descarga = $archivo_info['nombre_zip'];
        }
        break;
}

// ============================================
// VERIFICAR EXISTENCIA DEL ARCHIVO
// ============================================
if (!$archivo_info) {
    Logger::warning("Descarga: archivo no existe en BD", array(
        'tipo' => $tipo,
        'id' => $id,
        'usuario_id' => $usuario_id
    ));
    mostrarError('Archivo no encontrado');
}

if (!file_exists($ruta_archivo)) {
    Logger::error("Descarga: archivo físico no existe", array(
        'tipo' => $tipo,
        'id' => $id,
        'ruta' => $ruta_archivo,
        'usuario_id' => $usuario_id
    ));
    mostrarError('El archivo no está disponible en este momento');
}

// ============================================
// VERIFICAR RATE LIMITING
// ============================================
$verificacion = $rateLimiter->verificarLimite($usuario_id, $tipo_rate_limit, $ip);

if (!$verificacion['permitido']) {
    mostrarLimiteExcedido($verificacion['mensaje']);
}

// ============================================
// REGISTRAR Y SERVIR DESCARGA
// ============================================
$tamano_kb = isset($archivo_info['tamano_kb']) && $archivo_info['tamano_kb'] > 0 
    ? $archivo_info['tamano_kb'] 
    : round(filesize($ruta_archivo) / 1024);

// Registrar descarga
$rateLimiter->registrarDescarga(
    $usuario_id,
    $tipo_rate_limit,
    $id,
    $nombre_descarga,
    $tamano_kb,
    $ip
);

// Log de acceso
Logger::acceso('descarga', $id, $tipo_rate_limit);

// Servir archivo
servirArchivo($ruta_archivo, $nombre_descarga);

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function servirArchivo($ruta, $nombre) {
    $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
    $mime_types = array(
        'pdf' => 'application/pdf',
        'zip' => 'application/zip'
    );
    $mime = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    
    // Limpiar buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Headers de seguridad y descarga
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($nombre) . '"');
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    
    set_time_limit(0);
    readfile($ruta);
    exit;
}

function mostrarError($mensaje) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - Descarga</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body class="page-download-error">
        <div class="box">
            <div class="icon">❌</div>
            <h1>Error de Descarga</h1>
            <p><?php echo e($mensaje); ?></p>
            <a href="javascript:history.back()" class="btn">← Volver</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function mostrarLimiteExcedido($mensaje) {
    http_response_code(429);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Límite de Descargas</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body class="page-download-limit">
        <div class="box">
            <div class="icon">⚠️</div>
            <h1>Límite de Descargas Alcanzado</h1>
            <div class="info"><p><?php echo e($mensaje); ?></p></div>
            <p>Este límite garantiza un servicio equitativo para todos los usuarios.</p>
            <a href="javascript:history.back()" class="btn">← Volver</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}