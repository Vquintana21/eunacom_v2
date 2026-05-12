<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/classes/SimulacroGenerator.php';

// Requiere autenticación
requireAuth();

// Obtener usuario actual
$usuario = getCurrentUser();
$usuario_id = $usuario['id'];

$pdo = getDB();

// Procesar generación de simulacro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generar_simulacro'])) {
     verificarCSRF();
    $generator = new SimulacroGenerator($pdo);
    $resultado = $generator->generarSimulacro($usuario_id);
    
    if ($resultado['success']) {
        // Redirigir con modal de éxito
        $examen_id = $resultado['examen_id'];
        $codigo_examen = $resultado['codigo_examen'];
        $mostrar_modal = true;
    } else {
        $error = $resultado['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Simulacro - EUNACOM</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .container {
            max-width: 600px;
            width: 100%;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 2rem;
        }
        
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn:active {
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #95a5a6;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
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
        
        .error-box {
            background: #f8d7da;
            border: 2px solid #dc3545;
            border-radius: 10px;
            padding: 20px;
            color: #721c24;
            margin-top: 20px;
        }
        
        .error-box strong {
            display: block;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($error)): ?>
            <!-- ERROR -->
            <div class="card">
                <h1>❌ Error</h1>
                <div class="error-box">
                    <strong>No se pudo generar el simulacro</strong>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
                <div style="margin-top: 30px;">
                    <a href="<?= buildUrl('index.php') ?>" class="btn btn-secondary">
                        ← Volver al Inicio
                    </a>
                </div>
            </div>
            
        <?php elseif (!isset($mostrar_modal)): ?>
            <!-- PANTALLA INICIAL -->
            <div class="card">
                <div style="font-size: 5rem; margin-bottom: 20px;">📝</div>
                <h1>Simulacro EUNACOM</h1>
                <p class="subtitle">
                    Prepárate para rendir un simulacro completo de 180 preguntas<br>
                    dividido en 2 sesiones de 90 minutos cada una.
                </p>
                
                <div style="background: #e3f2fd; padding: 20px; border-radius: 10px; margin: 30px 0; text-align: left;">
                    <h3 style="color: #1976d2; margin-bottom: 15px;">📋 Características:</h3>
                    <ul style="color: #2c3e50; line-height: 2;">
                        <li>✓ 180 preguntas distribuidas según EUNACOM oficial</li>
                        <li>✓ 2 sesiones de 90 minutos cronometradas</li>
                        <li>✓ Preguntas aleatorias de todas las áreas</li>
                        <li>✓ Resultados detallados al finalizar</li>
                    </ul>
                </div>
                
                <form method="POST">
                    <?php echo campoCSRF(); ?>
                    <button type="submit" name="generar_simulacro" class="btn">
                        🚀 Comenzar Simulacro
                    </button>
                    <a href="<?= buildUrl('index.php') ?>" class="btn btn-secondary">
                        ← Volver
                    </a>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <?php if (isset($mostrar_modal)): ?>
    <!-- MODAL DE CUENTA REGRESIVA -->
    <div class="modal-overlay" id="modalCountdown">
        <div class="modal-content">
            <div class="modal-icon">🎯</div>
            <h2 class="modal-title">¡Simulacro Generado!</h2>
            <p class="modal-subtitle">
                Código: <strong><?= htmlspecialchars($codigo_examen) ?></strong>
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
                    window.location.href = '<?= buildUrl("simulacro.php?examen_id={$examen_id}") ?>';
                }, 500);
            }
        }, 1000);
    </script>
    <?php endif; ?>
</body>
</html>