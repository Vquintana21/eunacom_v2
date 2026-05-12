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
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-simulacro-gen">
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