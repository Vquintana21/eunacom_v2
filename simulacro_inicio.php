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
            max-width: 800px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
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
        
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .info-box h3 {
            color: #1976d2;
            margin-bottom: 15px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .info-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .info-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2196f3;
        }
        
        .info-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .warning-box h3 {
            color: #856404;
            margin-bottom: 15px;
        }
        
        .warning-box ul {
            margin-left: 20px;
            color: #856404;
        }
        
        .warning-box li {
            margin-bottom: 8px;
        }
        
        .btn {
            background: #2196f3;
            color: white;
            border: none;
            padding: 18px 40px;
            font-size: 1.2rem;
            font-weight: 600;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 20px;
        }
        
        .btn:hover {
            background: #1976d2;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(33, 150, 243, 0.4);
        }
        
        .btn-continuar {
            background: #ff9800;
        }
        
        .btn-continuar:hover {
            background: #f57c00;
        }
        
        .error-box {
            background: #f8d7da;
            color: #721c24;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
        }
        
        .error-box strong {
            display: block;
            margin-bottom: 10px;
        }
        
        .error-trace {
            background: #fff;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            font-family: monospace;
            font-size: 0.85rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        /* Modal de Cuenta Regresiva */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.4s ease;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-icon {
            font-size: 5rem;
            margin-bottom: 20px;
            animation: bounce 1s ease infinite;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .modal-title {
            font-size: 2rem;
            color: #2c3e50;
            margin-bottom: 15px;
            font-weight: 700;
        }
        
        .modal-subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        
        .countdown {
            font-size: 6rem;
            font-weight: 900;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 30px 0;
            animation: pulse 1s ease infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .loading-bar {
            width: 100%;
            height: 6px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 30px;
        }
        
        .loading-progress {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            animation: loading 3s linear;
        }
        
        @keyframes loading {
            from { width: 0%; }
            to { width: 100%; }
        }
    </style>
</head>
<body>
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
                <a href="<?= buildUrl('index.php') ?>" style="color: #2196f3; text-decoration: none;">
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