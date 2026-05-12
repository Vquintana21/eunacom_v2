<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/classes/Logger.php';

// ============================================
// CONFIGURACIÓN DE SESIÓN SEGURA
// ============================================

// ============================================
// CONFIGURACIÓN DE SESIÓN SEGURA EN BASE DE DATOS
// ============================================
if (session_status() == PHP_SESSION_NONE) {
    
    // Cargar el manejador de sesiones en BD
    require_once __DIR__ . '/classes/SessionHandler.php';
    
    // Configurar tiempo de vida de sesión
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    
    // Configuración de cookies seguras
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $httponly = true;
    $samesite = 'Strict';
    
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => $samesite
        ]);
    } else {
        session_set_cookie_params(
            SESSION_LIFETIME,
            '/; SameSite=' . $samesite,
            '',
            $secure,
            $httponly
        );
    }
    
    // Usar manejador de sesiones en base de datos
    try {
        $sessionPdo = Database::getInstance()->getConnection();
        $handler = new DatabaseSessionHandler($sessionPdo);
        session_set_save_handler($handler, true);
    } catch (Exception $e) {
        error_log("[Auth] Error al configurar session handler: " . $e->getMessage());
        // Fallback a sesiones en archivo si falla
    }
    
    session_start();
}

// Iniciar sesión si no está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// VERIFICAR SI USUARIO ESTÁ AUTENTICADO
// ============================================
function isLoggedIn() {
    return isset($_SESSION['usuario_id']) && isset($_SESSION['token']);
}

// ============================================
// REQUERIR AUTENTICACIÓN
// ============================================
function requireAuth() {
    if (!isLoggedIn()) {
        redirect(buildUrl('login.php'));
    }
    
    // Verificar que la sesión sea válida
    if (!verificarSesion()) {
        cerrarSesion();
        redirect(buildUrl('login.php?expired=1'));
    }
}

// ============================================
// REQUERIR NO AUTENTICACIÓN (para login/registro)
// ============================================
function requireGuest() {
    if (isLoggedIn()) {
        redirect(buildUrl('index.php'));
    }
}

// ============================================
// REGISTRAR USUARIO
// ============================================
function registrarUsuario($nombre, $email, $password) {
    $pdo = getDB();
    
    // Validar email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array('success' => false, 'mensaje' => 'Email inválido');
    }
    
    // Validar contraseña
    if (strlen($password) < 6) {
        return array('success' => false, 'mensaje' => 'La contraseña debe tener al menos 6 caracteres');
    }
    
    // Verificar si el email ya existe
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute(array($email));
    
    if ($stmt->fetch()) {
        return array('success' => false, 'mensaje' => 'El email ya está registrado');
    }
    
    // Hash de contraseña
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Insertar usuario
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nombre, email, password_hash, tipo_usuario, activo, fecha_registro)
            VALUES (?, ?, ?, 'estudiante', 1, NOW())
        ");
        $stmt->execute(array($nombre, $email, $password_hash));
        
        $usuario_id = $pdo->lastInsertId();
        
        // Registrar en log
        registrarActividad($usuario_id, 'registro', "Usuario registrado: $email");
        
        return array(
            'success' => true,
            'mensaje' => 'Registro exitoso',
            'usuario_id' => $usuario_id
        );
        
    } catch (PDOException $e) {
        error_log("[Auth] Error al registrar: " . $e->getMessage());
        return array('success' => false, 'mensaje' => 'Error al registrar. Intente nuevamente.');
    }
}

// ============================================
// VERIFICAR RATE LIMITING
// ============================================
function checkRateLimit($email, $ip) {
    $pdo = getDB();
    
    // Verificar intentos fallidos en últimos 15 minutos
    $sql = "
        SELECT COUNT(*) as intentos 
        FROM log_actividad 
        WHERE ip_address = ?
        AND accion IN ('login_fallido', 'login_bloqueado')
        AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array($ip));
    $result = $stmt->fetch();
    
    // Si tiene 5 o más intentos fallidos, bloquear
    if ($result['intentos'] >= 5) {
        // Registrar intento bloqueado
        registrarActividad(null, 'login_bloqueado', "IP bloqueada temporalmente: $email", $ip);
        return false;
    }
    
    return true;
}

// ============================================
// LIMPIAR INTENTOS FALLIDOS (después de login exitoso)
// ============================================
function limpiarIntentosLogin($ip) {
    $pdo = getDB();
    
    // Opcional: Marcar intentos anteriores como "resueltos"
    // O simplemente dejar que expiren después de 15 minutos
    
    // Por ahora no hacemos nada, dejan que expiren naturalmente
}

// ============================================
// INICIAR SESIÓN
// ============================================
function iniciarSesion($email, $password) {
    $pdo = getDB();
	
	// Verificar rate limit ANTES de hacer cualquier cosa
    if (!checkRateLimit($email, getClientIP())) {
        return array(
            'success' => false, 
            'mensaje' => '🚫 Demasiados intentos fallidos. Por favor espera 15 minutos antes de intentar nuevamente.'
        );
    }
    
    // Buscar usuario
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, password_hash, tipo_usuario, activo
        FROM usuarios
        WHERE email = ? AND activo = 1
    ");
    $stmt->execute(array($email));
    $usuario = $stmt->fetch();
    
   if (!$usuario) {
        registrarActividad(null, 'login_fallido', "Email no encontrado: $email", getClientIP());
        Logger::security("Intento de login - email no encontrado", array('email' => $email));
        return array('success' => false, 'mensaje' => 'Usuario o contraseña incorrectos');
    }
    
    // Verificar contraseña
    if (!password_verify($password, $usuario['password_hash'])) {
        registrarActividad($usuario['id'], 'login_fallido', "Contraseña incorrecta", getClientIP());
        Logger::security("Intento de login - contraseña incorrecta", array('usuario_id' => $usuario['id']));
        return array('success' => false, 'mensaje' => 'Usuario o contraseña incorrectos');
    }
    
    // Generar token de sesión (Compatible con PHP 5.6)
    $token = bin2hex(openssl_random_pseudo_bytes(32));
    $fecha_expiracion = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Guardar sesión en BD
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sesiones (usuario_id, token, ip_address, user_agent, fecha_expiracion, activa)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute(array(
            $usuario['id'],
            $token,
            getClientIP(),
            getUserAgent(),
            $fecha_expiracion
        ));
    } catch (PDOException $e) {
        error_log("[Auth] Error al crear sesión: " . $e->getMessage());
        return array('success' => false, 'mensaje' => 'Error al iniciar sesión');
    }
    
    // Actualizar último acceso
    $stmt = $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?");
    $stmt->execute(array($usuario['id']));
    
    // Regenerar ID de sesión ANTES de guardar datos (previene session fixation)
    session_regenerate_id(true);
    
    // Guardar datos de usuario en sesión
    $_SESSION['usuario_id'] = $usuario['id'];
    $_SESSION['usuario_nombre'] = $usuario['nombre'];
    $_SESSION['usuario_email'] = $usuario['email'];
    $_SESSION['usuario_tipo'] = $usuario['tipo_usuario'];
    $_SESSION['token'] = $token;
    $_SESSION['login_time'] = time();
    
    
    registrarActividad($usuario['id'], 'login', "Login exitoso");	
	// Log de seguridad
    Logger::security("Login exitoso", array('usuario_id' => $usuario['id'], 'email' => $email));
    limpiarIntentosLogin(getClientIP());
    
    return array(
        'success' => true,
        'mensaje' => 'Login exitoso',
        'usuario' => array(
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'email' => $usuario['email'],
            'tipo' => $usuario['tipo_usuario']
        )
    );
}

// ============================================
// VERIFICAR SESIÓN VÁLIDA
// ============================================
function verificarSesion() {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['token'])) {
        return false;
    }
    
    $pdo = getDB();
    
    $stmt = $pdo->prepare("
        SELECT id FROM sesiones 
        WHERE usuario_id = ? 
        AND token = ? 
        AND activa = 1 
        AND fecha_expiracion > NOW()
    ");
    $stmt->execute(array($_SESSION['usuario_id'], $_SESSION['token']));
    
    return $stmt->fetch() !== false;
}

// ============================================
// CERRAR SESIÓN
// ============================================
function cerrarSesion() {
    if (isset($_SESSION['usuario_id']) && isset($_SESSION['token'])) {
        $pdo = getDB();
        
        // Marcar sesión como inactiva en BD
        $stmt = $pdo->prepare("
            UPDATE sesiones 
            SET activa = 0 
            WHERE usuario_id = ? AND token = ?
        ");
        $stmt->execute(array($_SESSION['usuario_id'], $_SESSION['token']));
        
        // Registrar actividad
        registrarActividad($_SESSION['usuario_id'], 'logout', "Logout");
    }
    
    // Limpiar sesión PHP
    $_SESSION = array();
    
    // Destruir cookie de sesión
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Destruir sesión
    session_destroy();
}

// ============================================
// OBTENER DATOS DEL USUARIO ACTUAL
// ============================================
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return array(
        'id' => $_SESSION['usuario_id'],
        'nombre' => $_SESSION['usuario_nombre'],
        'email' => $_SESSION['usuario_email'],
        'tipo' => $_SESSION['usuario_tipo']
    );
}

// ============================================
// VERIFICAR SI ES ADMIN
// ============================================
function isAdmin() {
    return isLoggedIn() && $_SESSION['usuario_tipo'] === 'admin';
}

// ============================================
// REQUERIR ADMIN
// ============================================
function requireAdmin() {
    requireAuth();
    
    if (!isAdmin()) {
        redirect(buildUrl('index.php?error=no_autorizado'));
    }
}

// ============================================
// REGISTRAR ACTIVIDAD
// ============================================
function registrarActividad($usuario_id, $accion, $descripcion = '', $ip = null) {
    $pdo = getDB();
    
    $ip = $ip ?: getClientIP();
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO log_actividad (usuario_id, accion, descripcion, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute(array(
            $usuario_id,
            $accion,
            $descripcion,
            $ip,
            getUserAgent()
        ));
    } catch (PDOException $e) {
        error_log("[Auth] Error al registrar actividad: " . $e->getMessage());
    }
}

// ============================================
// OBTENER IP DEL CLIENTE
// ============================================
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// ============================================
// OBTENER USER AGENT
// ============================================
function getUserAgent() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
}

// ============================================
// CAMBIAR CONTRASEÑA
// ============================================
function cambiarPassword($usuario_id, $password_actual, $password_nueva) {
    $pdo = getDB();
    
    // Validar nueva contraseña
    if (strlen($password_nueva) < 6) {
        return array('success' => false, 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres');
    }
    
    // Verificar contraseña actual
    $stmt = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ?");
    $stmt->execute(array($usuario_id));
    $usuario = $stmt->fetch();
    
    if (!$usuario || !password_verify($password_actual, $usuario['password_hash'])) {
        return array('success' => false, 'mensaje' => 'La contraseña actual es incorrecta');
    }
    
    // Actualizar contraseña
    $new_hash = password_hash($password_nueva, PASSWORD_BCRYPT);
    
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET password_hash = ? WHERE id = ?");
        $stmt->execute(array($new_hash, $usuario_id));
        
        registrarActividad($usuario_id, 'cambio_password', "Contraseña cambiada");
        
        return array('success' => true, 'mensaje' => 'Contraseña actualizada correctamente');
        
    } catch (PDOException $e) {
        error_log("[Auth] Error al cambiar contraseña: " . $e->getMessage());
        return array('success' => false, 'mensaje' => 'Error al cambiar contraseña');
    }
}

// ============================================
// PROTECCIÓN CSRF
// ============================================

/**
 * Genera un token CSRF y lo guarda en sesión
 * @return string Token CSRF
 */
function generarTokenCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Obtiene el token CSRF actual (sin regenerar)
 * @return string|null Token CSRF o null si no existe
 */
function obtenerTokenCSRF() {
    return isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null;
}

/**
 * Valida el token CSRF recibido
 * @param string $token Token a validar
 * @return bool True si es válido
 */
function validarTokenCSRF($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Regenera el token CSRF (usar después de operaciones sensibles)
 * @return string Nuevo token
 */
function regenerarTokenCSRF() {
    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * Genera el campo hidden HTML para formularios
 * @return string HTML del campo hidden
 */
function campoCSRF() {
    $token = generarTokenCSRF();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Valida CSRF en peticiones POST y termina si es inválido
 * @param bool $es_ajax Si es petición AJAX (busca en JSON)
 */
function verificarCSRF($es_ajax = false) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return; // Solo validar en POST
    }
    
    if ($es_ajax) {
        // Para peticiones AJAX, buscar en header o en JSON body
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null;
        
        if (!$token) {
            $input = json_decode(file_get_contents('php://input'), true);
            $token = isset($input['csrf_token']) ? $input['csrf_token'] : null;
        }
    } else {
        // Para formularios normales
        $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    }
    
    if (!validarTokenCSRF($token)) {
         Logger::security("Token CSRF inválido", array(
            'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'unknown',
            'method' => $_SERVER['REQUEST_METHOD']
        ));
        if ($es_ajax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido']);
            exit;
        } else {
            http_response_code(403);
            die('Error de seguridad: Token inválido. Por favor recarga la página e intenta nuevamente.');
        }
    }
}