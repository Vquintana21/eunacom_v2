<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

requireAuth();
$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

$pdo = getDB();

// Obtener código del examen
$codigo_examen = isset($_GET['examen']) ? $_GET['examen'] : null;

if (!$codigo_examen) {
    header("Location: simulacro_inicio.php");
    exit;
}

// Obtener datos del examen
$sql = "
    SELECT * FROM examenes 
    WHERE codigo_examen = ? AND usuario_id = ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$codigo_examen, $usuario_id]);
$examen = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$examen || $examen['estado'] !== 'sesion1_completa') {
    header("Location: simulacro_inicio.php");
    exit;
}

// Obtener estadísticas de sesión 1
$sql = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN alternativa_seleccionada IS NOT NULL THEN 1 ELSE 0 END) as respondidas,
        SUM(CASE WHEN marcada_revision = 1 THEN 1 ELSE 0 END) as marcadas
    FROM respuestas_usuario ru
    INNER JOIN examen_preguntas ep ON ru.examen_id = ep.examen_id AND ru.pregunta_id = ep.pregunta_id
    WHERE ru.examen_id = ? AND ep.sesion = 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$examen['id']]);
$stats_sesion1 = $stmt->fetch(PDO::FETCH_ASSOC);

// Procesar inicio de sesión 2
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['iniciar_sesion2'])) {
    $sql = "
        UPDATE examenes 
        SET fecha_inicio_sesion2 = NOW(),
            sesion_actual = 2,
            estado = 'en_curso'
        WHERE id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$examen['id']]);
    
    header("Location: simulacro_examen.php?examen=" . $codigo_examen);
    exit;
}

// Calcular tiempo transcurrido desde fin de sesión 1
$tiempo_descanso = 0;
if ($examen['fecha_fin_sesion1']) {
    $inicio = new DateTime($examen['fecha_fin_sesion1']);
    $ahora = new DateTime();
    $diferencia = $ahora->diff($inicio);
    $tiempo_descanso = ($diferencia->h * 60) + $diferencia->i;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descanso - Simulacro EUNACOM</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-simulacro-intermedio">
    <div class="container">
        
        <div class="card">
            <div class="success-icon">✅</div>
            <h1>¡Sesión 1 Completada!</h1>
            <p class="subtitle">Has terminado la primera mitad del simulacro</p>
            
            <!-- Indicadores de Progreso -->
            <div class="progress-sesiones">
                <div class="sesion-indicator completada">
                    <div>✓</div>
                    <div>Sesión 1</div>
                    <small>90 preguntas</small>
                </div>
                
                <div class="sesion-arrow">→</div>
                
                <div class="sesion-indicator pendiente">
                    <div>2</div>
                    <div>Sesión 2</div>
                    <small>90 preguntas</small>
                </div>
            </div>
            
            <!-- Estadísticas Sesión 1 -->
            <div class="stats-grid">
                <div class="stat-box highlight">
                    <div class="stat-number"><?= $stats_sesion1['respondidas'] ?></div>
                    <div class="stat-label">Respondidas</div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-number"><?= 90 - $stats_sesion1['respondidas'] ?></div>
                    <div class="stat-label">Omitidas</div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-number"><?= $stats_sesion1['marcadas'] ?></div>
                    <div class="stat-label">Marcadas</div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-number">90</div>
                    <div class="stat-label">Total</div>
                </div>
            </div>
            
            <!-- Tiempo de Descanso -->
            <?php if ($tiempo_descanso > 0): ?>
                <div class="timer-descanso">
                    <h3>⏱️ Tiempo de descanso</h3>
                    <div class="tiempo"><?= $tiempo_descanso ?> minutos</div>
                </div>
            <?php endif; ?>
            
            <!-- Información -->
            <div class="info-box">
                <h3>✨ Buen trabajo</h3>
                <ul>
                    <li>Has completado la primera mitad del examen</li>
                    <li>Puedes tomar un descanso antes de continuar</li>
                    <li>La Sesión 2 tendrá otras 90 preguntas diferentes</li>
                    <li>Tendrás otros 90 minutos para completarla</li>
                </ul>
            </div>
            
            <!-- Advertencia -->
            <div class="warning-box">
                <h3>⚠️ Importante</h3>
                <p>
                    <strong>Una vez que inicies la Sesión 2, no podrás regresar a las preguntas de la Sesión 1.</strong>
                    Asegúrate de estar listo antes de continuar.
                </p>
            </div>
            
            <!-- Botón de Inicio Sesión 2 -->
            <form method="POST">
                <button type="submit" name="iniciar_sesion2" class="btn">
                    ▶️ Iniciar Sesión 2
                </button>
            </form>
            
            <p style="text-align: center; margin-top: 20px; color: #7f8c8d;">
                <strong>Código de examen:</strong> <?= e($examen['codigo_examen']) ?>
            </p>
        </div>
        
    </div>
</body>
</html>