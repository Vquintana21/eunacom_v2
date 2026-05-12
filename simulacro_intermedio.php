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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1b7db8 0%, #3ab4f2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 900px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 20px;
        }
        
        h1 {
            color: #2c3e50;
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .subtitle {
            color: #7f8c8d;
            text-align: center;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        
        .success-icon {
            text-align: center;
            font-size: 5rem;
            margin: 30px 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .stat-box {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            border: 3px solid #e9ecef;
        }
        
        .stat-box.highlight {
            background: #e3f2fd;
            border-color: #2196f3;
        }
        
        .stat-number {
            font-size: 3rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 1rem;
            font-weight: 500;
        }
        
        .info-box {
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 20px;
            border-radius: 10px;
            margin: 30px 0;
        }
        
        .info-box h3 {
            color: #2e7d32;
            margin-bottom: 15px;
        }
        
        .info-box ul {
            margin-left: 20px;
            color: #2e7d32;
        }
        
        .info-box li {
            margin-bottom: 8px;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 10px;
            margin: 30px 0;
        }
        
        .warning-box h3 {
            color: #856404;
            margin-bottom: 15px;
        }
        
        .btn {
            background: #4caf50;
            color: white;
            border: none;
            padding: 20px 50px;
            font-size: 1.3rem;
            font-weight: 600;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 30px;
        }
        
        .btn:hover {
            background: #45a049;
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(76, 175, 80, 0.4);
        }
        
        .timer-descanso {
            text-align: center;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 15px;
            margin: 30px 0;
        }
        
        .timer-descanso h3 {
            color: #7f8c8d;
            margin-bottom: 15px;
        }
        
        .timer-descanso .tiempo {
            font-size: 3rem;
            font-weight: bold;
            color: #2c3e50;
            font-family: 'Courier New', monospace;
        }
        
        .progress-sesiones {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin: 40px 0;
        }
        
        .sesion-indicator {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            border: 4px solid;
        }
        
        .sesion-indicator.completada {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        
        .sesion-indicator.pendiente {
            background: #e9ecef;
            border-color: #6c757d;
            color: #495057;
        }
        
        .sesion-arrow {
            font-size: 2rem;
            color: #7f8c8d;
        }
    </style>
</head>
<body>
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