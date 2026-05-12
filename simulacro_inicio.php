<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

// Requiere autenticación
requireAuth();

// Obtener usuario actual
$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

$pdo = getDB();

// Verificar si hay examen en curso
$sql = "
    SELECT * FROM examenes 
    WHERE usuario_id = ? 
    AND estado IN ('en_curso', 'sesion1_completa')
    ORDER BY id DESC 
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$usuario_id]);
$examen_en_curso = $stmt->fetch(PDO::FETCH_ASSOC);

// Procesar inicio de nuevo simulacro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['iniciar_simulacro'])) {
     verificarCSRF();
    // Verificar que el archivo existe
    if (!file_exists('classes/SimulacroGenerator.php')) {
        $error = "⛔ ERROR: No se encuentra el archivo classes/SimulacroGenerator.php";
    } else {
        require_once 'classes/SimulacroGenerator.php';
        
        try {
            $generator = new SimulacroGenerator($pdo);
            $resultado = $generator->generarSimulacro($usuario_id);
            
            if ($resultado['success']) {
                // Exito - Mostrar modal con cuenta regresiva
                $examen_generado = true;
                $codigo_examen = $resultado['codigo_examen'];
            } else {
                $error = $resultado['error'];
                if (isset($resultado['trace'])) {
                    $error_trace = $resultado['trace'];
                }
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            $error_trace = $e->getTraceAsString();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulacro EUNACOM</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-simulacro-inicio">
    <div class="container">
        <div class="card">
            <h1>🏥 Simulacro EUNACOM</h1>
            <p class="subtitle">Examen de Habilitación Médica</p>
            
            <?php if (isset($error)): ?>
                <div class="error-box">
                    <strong>⚠️ Error al generar el simulacro</strong>
                    <p><?= e($error) ?></p>
                    
                    <?php if (isset($error_trace)): ?>
                        <details style="margin-top: 15px;">
                            <summary style="cursor: pointer; color: #721c24; font-weight: 600;">
                                Ver detalles técnicos
                            </summary>
                            <div class="error-trace">
                                <?= nl2br(e($error_trace)) ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($examen_en_curso): ?>
                <!-- Examen en curso -->
                <div class="warning-box">
                    <h3>⚠️ Tienes un examen en curso</h3>
                    <p><strong>Código:</strong> <?= e($examen_en_curso['codigo_examen']) ?></p>
                    <p><strong>Estado:</strong> <?= $examen_en_curso['estado'] === 'en_curso' ? 'Sesión 1' : 'Sesión 2' ?></p>
                    <p style="margin-top: 10px; color: #856404;">
                        Debes completar o abandonar este examen antes de iniciar uno nuevo.
                    </p>
                </div>
                
                <form method="GET" action="simulacro_examen.php">
                    <input type="hidden" name="examen" value="<?= e($examen_en_curso['codigo_examen']) ?>">
                    <button type="submit" class="btn btn-continuar">
                        ▶️ Continuar Examen
                    </button>
                </form>
                
            <?php else: ?>
                <!-- Información del simulacro -->
                <div class="info-box">
                    <h3>📋 Características del Simulacro</h3>
                    
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-number">180</div>
                            <div class="info-label">Preguntas</div>
                        </div>
                        <div class="info-item">
                            <div class="info-number">2</div>
                            <div class="info-label">Sesiones</div>
                        </div>
                        <div class="info-item">
                            <div class="info-number">90</div>
                            <div class="info-label">Min / Sesión</div>
                        </div>
                    </div>
                </div>
                
                <div class="warning-box">
                    <h3>⚠️ Instrucciones Importantes</h3>
                    <ul>
                        <li>El simulacro consta de <strong>2 sesiones de 90 minutos</strong> cada una</li>
                        <li>Cada sesión tiene <strong>90 preguntas</strong></li>
                        <li>Una vez iniciado, el <strong>timer no se puede detener</strong></li>
                        <li>Puedes cerrar el navegador, <strong>tu progreso se guarda</strong></li>
                        <li>Al finalizar el tiempo, <strong>se envía automáticamente</strong></li>
                        <li>No podrás retroceder a la sesión 1 una vez iniciada la sesión 2</li>
                    </ul>
                </div>
                
                <form method="POST">
                     <?php echo campoCSRF(); ?>
                    <button type="submit" name="iniciar_simulacro" class="btn">
                        🚀 Iniciar Simulacro
                    </button>
                </form>
            <?php endif; ?>
            
            <p style="text-align: center; margin-top: 20px;">
                <a href="<?= buildUrl('index.php') ?>" style="color: var(--color-primary-light); text-decoration: none;">
                    ← Volver al Inicio
                </a>
            </p>
        </div>
    </div>
    
    <?php if (isset($examen_generado) && $examen_generado): ?>
    <!-- MODAL DE CUENTA REGRESIVA -->
    <div class="modal-overlay" id="modalCountdown">
        <div class="modal-content">
            <div class="modal-icon">🎯</div>
            <h2 class="modal-title">¡Simulacro Generado!</h2>
            <p class="modal-subtitle">
                Código: <strong><?= e($codigo_examen) ?></strong>
            </p>
            <p style="color: #7f8c8d; margin-bottom: 20px;">
                Comenzamos en...
            </p>
            <div class="countdown" id="countdown">3</div>
            <div class="loading-bar">
                <div class="loading-progress"></div>
            </div>
        </div>
    </div>
    
    <script>
        // Cuenta regresiva
        let contador = 3;
        const countdownEl = document.getElementById('countdown');
        
        const interval = setInterval(() => {
            contador--;
            if (contador > 0) {
                countdownEl.textContent = contador;
            } else {
                clearInterval(interval);
                countdownEl.textContent = '¡Vamos!';
                
                // Redirigir al simulacro
                setTimeout(() => {
                    window.location.href = 'simulacro_examen.php?examen=<?= e($codigo_examen) ?>';
                }, 500);
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>