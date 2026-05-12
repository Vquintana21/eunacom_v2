<?php
// IMPORTANTE: Iniciar sesión primero

// Cargar sistema de autenticación
require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/classes/Logger.php';

// Verificar que esté autenticado
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// SEGURIDAD: Validar token CSRF
verificarCSRF(true); // true = modo AJAX

// Obtener usuario actual
$usuario_actual = getCurrentUser();

header('Content-Type: application/json');

// Obtener conexión a BD
$pdo = getDB();

// Leer datos de la petición
// Leer datos de la petición
// Leer datos de la petición
$input = json_decode(file_get_contents('php://input'), true);

// Validar que el JSON sea válido
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'JSON inválido'
    ]);
    exit;
}

$action = isset($input['action']) ? trim($input['action']) : null;

// Validar que la acción sea una de las permitidas
$acciones_permitidas = [
    'guardar_respuesta',
    'marcar_revision',
    'guardar_tiempo',
    'finalizar_sesion',
    'cancelar_examen'
];

if (!in_array($action, $acciones_permitidas)) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Acción no permitida'
    ]);
    exit;
}

$codigo_examen = isset($_GET['examen']) ? $_GET['examen'] : null;


// ============================================
// CANCELAR EXAMEN (NUEVO)
// ============================================
// ============================================
// CANCELAR EXAMEN
// ============================================
if ($action === 'cancelar_examen') {
    $examen_id = isset($input['examen_id']) ? (int)$input['examen_id'] : 0;
    $sesion = isset($input['sesion']) ? (int)$input['sesion'] : 1;
    
    // Validar parámetros
    if ($examen_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'ID de examen inválido'
        ]);
        exit;
    }
    
    // Validar sesión
    if (!in_array($sesion, [1, 2])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Número de sesión inválido'
        ]);
        exit;
    }
    
    // Validar propiedad
    if (!verificarPropiedadExamen($pdo, $examen_id, $usuario_actual['id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Acceso denegado'
        ]);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $sql = "
            UPDATE examenes 
            SET estado = 'cancelado',
                fecha_finalizacion = NOW()
            WHERE id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$examen_id]);
        
        $pdo->commit();
          Logger::examen($examen_id, 'cancelado', array('sesion' => $sesion));
        echo json_encode([
            'success' => true,
            'mensaje' => 'Examen cancelado exitosamente'
        ]);
        
  } catch (Exception $e) {
        $pdo->rollBack();
        error_log("[simulacro_ajax] Error al cancelar examen: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'error' => 'Error al procesar la solicitud. Intente nuevamente.'
        ]);
    }
    
    exit;
}

// ============================================
// FUNCIÓN AUXILIAR: Verificar propiedad del examen
// ============================================
function verificarPropiedadExamen($pdo, $examen_id, $usuario_id) {
    $sql = "SELECT usuario_id FROM examenes WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$examen_id]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$examen) {
        return false;
    }
    
    return $examen['usuario_id'] == $usuario_id;
}

// ============================================
// GUARDAR RESPUESTA
// ============================================
// ============================================
// GUARDAR RESPUESTA
// ============================================
if ($action === 'guardar_respuesta') {
    $examen_id = isset($input['examen_id']) ? (int)$input['examen_id'] : 0;
    $pregunta_id = isset($input['pregunta_id']) ? (int)$input['pregunta_id'] : 0;
    $alternativa = isset($input['alternativa']) ? $input['alternativa'] : null;
    
    // Validar parámetros obligatorios
    if ($examen_id <= 0 || $pregunta_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Parámetros inválidos'
        ]);
        exit;
    }
    
    // Validar que alternativa sea NULL o una letra válida (A-E)
    if ($alternativa !== null) {
        $alternativa = strtoupper(trim($alternativa));
        if (!in_array($alternativa, ['A', 'B', 'C', 'D', 'E'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'error' => 'Alternativa inválida'
            ]);
            exit;
        }
    }
    
   // Validar propiedad
    if (!verificarPropiedadExamen($pdo, $examen_id, $usuario_actual['id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // Verificar si es correcta
        $es_correcta = 0;
        if ($alternativa !== null) {
            $sql = "SELECT es_correcta FROM alternativas WHERE pregunta_id = ? AND opcion = ? FOR UPDATE";  // <-- Agregar FOR UPDATE
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$pregunta_id, $alternativa]);
            $alt_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $es_correcta = isset($alt_data['es_correcta']) ? (int)$alt_data['es_correcta'] : 0;
        }
        
        // Actualizar respuesta
        $sql = "UPDATE respuestas_usuario SET alternativa_seleccionada = ?, es_correcta = ?, updated_at = NOW() WHERE examen_id = ? AND pregunta_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$alternativa, $es_correcta, $examen_id, $pregunta_id]);
        
        $pdo->commit(); 
        
        Logger::debug("Respuesta guardada", ['examen_id' => $examen_id, 'pregunta_id' => $pregunta_id, 'alternativa' => $alternativa]);
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $pdo->rollBack();  
        echo json_encode(['success' => false, 'error' => 'Error al guardar']);
    }
    exit;
}

// ============================================
// MARCAR PARA REVISIÓN
// ============================================
// ============================================
// MARCAR PARA REVISIÓN
// ============================================
if ($action === 'marcar_revision') {
    $examen_id = isset($input['examen_id']) ? (int)$input['examen_id'] : 0;
    $pregunta_id = isset($input['pregunta_id']) ? (int)$input['pregunta_id'] : 0;
    $marcada = isset($input['marcada']) ? (bool)$input['marcada'] : false;
    
    // Validar parámetros
    if ($examen_id <= 0 || $pregunta_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Parámetros inválidos'
        ]);
        exit;
    }
    
    // Validar propiedad
    if (!verificarPropiedadExamen($pdo, $examen_id, $usuario_actual['id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Acceso denegado'
        ]);
        exit;
    }
    
    $marcada_int = $marcada ? 1 : 0;
    
    $sql = "
        UPDATE respuestas_usuario 
        SET marcada_revision = ?
        WHERE examen_id = ? AND pregunta_id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$marcada_int, $examen_id, $pregunta_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// GUARDAR TIEMPO
// ============================================
// ============================================
// GUARDAR TIEMPO
// ============================================
//if ($action === 'guardar_tiempo') {
//    $examen_id = isset($input['examen_id']) ? (int)$input['examen_id'] : 0;
//    $tiempo_restante = isset($input['tiempo_restante']) ? (int)$input['tiempo_restante'] : 0;
//    $campo = isset($input['campo']) ? trim($input['campo']) : '';
//    
//    // Validar parámetros
//    if ($examen_id <= 0) {
//        http_response_code(400);
//        echo json_encode([
//            'success' => false, 
//            'error' => 'ID de examen inválido'
//        ]);
//        exit;
//    }
//    
//    // Validar tiempo (no puede ser negativo ni mayor a 5400 segundos = 90 min)
//    if ($tiempo_restante < 0 || $tiempo_restante > 5400) {
//        http_response_code(400);
//        echo json_encode([
//            'success' => false, 
//            'error' => 'Tiempo inválido'
//        ]);
//        exit;
//    }
//    
//    // Validar que el campo sea uno de los permitidos
//    if (!in_array($campo, ['tiempo_restante_sesion1', 'tiempo_restante_sesion2'])) {
//        http_response_code(400);
//        echo json_encode([
//            'success' => false, 
//            'error' => 'Campo inválido'
//        ]);
//        exit;
//    }
//    
//    // Validar propiedad
//    if (!verificarPropiedadExamen($pdo, $examen_id, $usuario_actual['id'])) {
//        http_response_code(403);
//        echo json_encode([
//            'success' => false, 
//            'error' => 'Acceso denegado'
//        ]);
//        exit;
//    }
//    
//    // Construir query de forma segura
//    if ($campo === 'tiempo_restante_sesion1') {
//        $sql = "UPDATE examenes SET tiempo_restante_sesion1 = ? WHERE id = ?";
//    } else {
//        $sql = "UPDATE examenes SET tiempo_restante_sesion2 = ? WHERE id = ?";
//    }
//    
//    $stmt = $pdo->prepare($sql);
//    $stmt->execute([$tiempo_restante, $examen_id]);
//    
//    echo json_encode(['success' => true]);
//    exit;
//}

// ============================================
// GUARDAR TIEMPO CON VALIDACIÓN DEL SERVIDOR V2
// ============================================
if ($action === 'guardar_tiempo') {
    $examen_id = isset($input['examen_id']) ? (int)$input['examen_id'] : 0;
    $tiempo_cliente = isset($input['tiempo_restante']) ? (int)$input['tiempo_restante'] : 0;
    $campo = isset($input['campo']) ? trim($input['campo']) : '';
    
    // Validar parámetros
    if ($examen_id <= 0 || !in_array($campo, ['tiempo_restante_sesion1', 'tiempo_restante_sesion2'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Parámetros inválidos'
        ]);
        exit;
    }
    
    // Validar propiedad del examen
    if (!verificarPropiedadExamen($pdo, $examen_id, $usuario_actual['id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Acceso denegado'
        ]);
        exit;
    }
    
    // Obtener datos del examen
    $sql = "SELECT sesion_actual, fecha_inicio, fecha_inicio_sesion2 FROM examenes WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$examen_id]);
    $examen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$examen) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Examen no encontrado']);
        exit;
    }
    
    // Determinar sesión actual y fecha de inicio
    $sesion_actual = $examen['sesion_actual'];
    $fecha_inicio = $sesion_actual == 1 ? 
        $examen['fecha_inicio'] : 
        $examen['fecha_inicio_sesion2'];
    
    // Calcular tiempo REAL desde el servidor
    $TIEMPO_TOTAL = 90 * 60; // 5400 segundos
    $timestamp_inicio = strtotime($fecha_inicio);
    $timestamp_actual = time();
    $tiempo_transcurrido = $timestamp_actual - $timestamp_inicio;
    $tiempo_real_servidor = $TIEMPO_TOTAL - $tiempo_transcurrido;
    
    // Si el tiempo ya expiró
    if ($tiempo_real_servidor <= 0) {
        echo json_encode([
            'success' => false,
            'tiempo_expirado' => true,
            'tiempo_servidor' => 0,
            'mensaje' => 'El tiempo ha expirado'
        ]);
        exit;
    }
    
    // Calcular diferencia entre cliente y servidor
    $diferencia = abs($tiempo_cliente - $tiempo_real_servidor);
    
    // Si la diferencia es mayor a 5 segundos, el cliente está desincronizado
    $desincronizado = $diferencia > 5;
    
 // Guardar el tiempo REAL del servidor en la BD
    if ($campo === 'tiempo_restante_sesion1') {
        $sql = "UPDATE examenes SET tiempo_restante_sesion1 = ? WHERE id = ?";
    } else {
        $sql = "UPDATE examenes SET tiempo_restante_sesion2 = ? WHERE id = ?";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tiempo_real_servidor, $examen_id]);
    
    // Responder con el tiempo correcto
    echo json_encode([
        'success' => true,
        'tiempo_servidor' => $tiempo_real_servidor,
        'tiempo_cliente' => $tiempo_cliente,
        'desincronizado' => $desincronizado,
        'diferencia_segundos' => $diferencia
    ]);
    exit;
}

// ============================================
// FINALIZAR SESIÓN
// ============================================
// ============================================
// FINALIZAR SESIÓN
// ============================================
if ($action === 'finalizar_sesion') {
    $examen_id = isset($input['examen_id']) ? (int)$input['examen_id'] : 0;
    $sesion = isset($input['sesion']) ? (int)$input['sesion'] : 0;
    $tiempo_restante = isset($input['tiempo_restante']) ? (int)$input['tiempo_restante'] : 0;
    
    // Validar parámetros
    if ($examen_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'ID de examen inválido'
        ]);
        exit;
    }
    
    // Validar sesión (solo 1 o 2)
    if (!in_array($sesion, [1, 2])) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Número de sesión inválido'
        ]);
        exit;
    }
    
    // Validar tiempo
    if ($tiempo_restante < 0 || $tiempo_restante > 5400) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Tiempo inválido'
        ]);
        exit;
    }
    
    // Validar propiedad
    if (!verificarPropiedadExamen($pdo, $examen_id, $usuario_actual['id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Acceso denegado'
        ]);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        if ($sesion == 1) {
            // Finalizar sesión 1
            $sql = "
                UPDATE examenes 
                SET estado = 'sesion1_completa',
                    sesion_actual = 2,
                    fecha_fin_sesion1 = NOW(),
                    tiempo_restante_sesion1 = ?
                WHERE id = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tiempo_restante, $examen_id]);
            
        } else {
            // Finalizar examen completo
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN alternativa_seleccionada IS NOT NULL THEN 1 ELSE 0 END) as respondidas,
                    SUM(CASE WHEN es_correcta = 1 THEN 1 ELSE 0 END) as correctas
                FROM respuestas_usuario
                WHERE examen_id = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$examen_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $total = (int)$stats['total'];
            $respondidas = (int)$stats['respondidas'];
            $correctas = (int)$stats['correctas'];
            $incorrectas = $respondidas - $correctas;
            $omitidas = $total - $respondidas;
            $porcentaje = $total > 0 ? ($correctas / $total) * 100 : 0;
            
            $sql = "
                UPDATE examenes 
                SET estado = 'finalizado',
                    fecha_finalizacion = NOW(),
                    tiempo_restante_sesion2 = ?,
                    preguntas_respondidas = ?,
                    respuestas_correctas = ?,
                    respuestas_incorrectas = ?,
                    preguntas_omitidas = ?,
                    puntaje_porcentaje = ?
                WHERE id = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tiempo_restante,
                $respondidas,
                $correctas,
                $incorrectas,
                $omitidas,
                $porcentaje,
                $examen_id
            ]);
        }
        
        $pdo->commit();
         Logger::examen($examen_id, 'sesion_finalizada', array(
            'sesion' => $sesion,
            'tiempo_restante' => $tiempo_restante
        ));
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("[simulacro_ajax] Error al finalizar sesión: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'error' => 'Error al procesar la solicitud. Intente nuevamente.'
        ]);
    }
    
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción no válida']);