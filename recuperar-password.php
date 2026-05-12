<?php

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

// Si ya está logueado, redirigir
if (isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit();
}

$pdo = getDB(); // PDO connection

$paso = isset($_GET['paso']) ? (int)$_GET['paso'] : 1;
$mensaje = '';
$error = '';
$tipo_alerta = '';

// ============================================
// PASO 1: SOLICITAR CÓDIGO
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $paso == 1) {
    verificarCSRF();
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error = 'Debes ingresar tu email';
        $tipo_alerta = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido';
        $tipo_alerta = 'error';
    } else {
        try {
            // Verificar que el email existe
            $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE email = ? AND activo = 1");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($usuario) {
                // Generar código de 6 dígitos
                $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // Calcular expiración (15 minutos desde ahora)
                $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                
                // Guardar código en BD
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET codigo_recuperacion = ?, 
                        codigo_expiracion = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$codigo, $expiracion, $usuario['id']]);
                
                // Guardar datos en sesión para el siguiente paso
                $_SESSION['recuperar_email'] = $email;
                $_SESSION['recuperar_codigo'] = $codigo; // Para mostrarlo
                $_SESSION['recuperar_nombre'] = $usuario['nombre'];
                
                // Redirigir al paso 2
                header('Location: recuperar-password.php?paso=2');
                exit();
                
            } else {
                $error = 'No existe una cuenta con ese email';
                $tipo_alerta = 'error';
            }
            
        } catch (Exception $e) {
            error_log("Error en recuperación: " . $e->getMessage());
            $error = 'Error al procesar solicitud. Intenta nuevamente.';
            $tipo_alerta = 'error';
        }
    }
}

// ============================================
// PASO 2: VERIFICAR CÓDIGO
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $paso == 2) {
    
    if (!isset($_SESSION['recuperar_email'])) {
        header('Location: recuperar-password.php?paso=1');
        exit();
    }
    
    $codigo_ingresado = trim($_POST['codigo']);
    $email = $_SESSION['recuperar_email'];
    
    if (empty($codigo_ingresado)) {
        $error = 'Debes ingresar el código';
        $tipo_alerta = 'error';
    } else {
        try {
            // Verificar código y que no haya expirado
            $stmt = $pdo->prepare("
                SELECT id, nombre 
                FROM usuarios 
                WHERE email = ? 
                AND codigo_recuperacion = ? 
                AND codigo_expiracion > NOW()
                AND activo = 1
            ");
            $stmt->execute([$email, $codigo_ingresado]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($usuario) {
                // Código válido, guardar usuario_id para paso 3
                $_SESSION['recuperar_id'] = $usuario['id'];
                $_SESSION['codigo_verificado'] = true;
                
                // Redirigir al paso 3
                header('Location: recuperar-password.php?paso=3');
                exit();
                
            } else {
                // Verificar si el código expiró
                $stmt = $pdo->prepare("
                    SELECT codigo_expiracion 
                    FROM usuarios 
                    WHERE email = ? AND codigo_recuperacion = ?
                ");
                $stmt->execute([$email, $codigo_ingresado]);
                $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($resultado) {
                    $error = 'El código ha expirado. Solicita uno nuevo.';
                } else {
                    $error = 'Código incorrecto';
                }
                $tipo_alerta = 'error';
            }
            
        } catch (Exception $e) {
            error_log("Error verificando código: " . $e->getMessage());
            $error = 'Error al verificar código. Intenta nuevamente.';
            $tipo_alerta = 'error';
        }
    }
}

// ============================================
// PASO 3: CAMBIAR CONTRASEÑA
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $paso == 3) {
    
    if (!isset($_SESSION['codigo_verificado']) || !isset($_SESSION['recuperar_id'])) {
        header('Location: recuperar-password.php?paso=1');
        exit();
    }
    
    $nueva_password = trim($_POST['nueva_password']);
    $confirmar_password = trim($_POST['confirmar_password']);
    $usuario_id = $_SESSION['recuperar_id'];
    
    if (empty($nueva_password) || empty($confirmar_password)) {
        $error = 'Debes completar ambos campos';
        $tipo_alerta = 'error';
    } elseif ($nueva_password !== $confirmar_password) {
        $error = 'Las contraseñas no coinciden';
        $tipo_alerta = 'error';
    } elseif (strlen($nueva_password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';
        $tipo_alerta = 'error';
    } else {
        try {
            // Generar hash de la nueva contraseña
            $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
            
            // Actualizar contraseña y limpiar código
            $stmt = $pdo->prepare("
                UPDATE usuarios 
                SET password_hash = ?, 
                    codigo_recuperacion = NULL, 
                    codigo_expiracion = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$password_hash, $usuario_id]);
            
            if ($stmt->rowCount() > 0) {
                // Registrar actividad
                $stmt_log = $pdo->prepare("
                    INSERT INTO log_actividad (usuario_id, accion, descripcion, ip_address, created_at) 
                    VALUES (?, 'cambio_password', 'Contraseña recuperada exitosamente', ?, NOW())
                ");
                $ip = $_SERVER['REMOTE_ADDR'];
                $stmt_log->execute([$usuario_id, $ip]);
                
                // Limpiar variables de sesión
                unset($_SESSION['recuperar_email']);
                unset($_SESSION['recuperar_codigo']);
                unset($_SESSION['recuperar_nombre']);
                unset($_SESSION['recuperar_id']);
                unset($_SESSION['codigo_verificado']);
                
                // Redirigir al login con mensaje de éxito
                $_SESSION['mensaje_exito'] = 'Contraseña actualizada exitosamente. Ya puedes iniciar sesión.';
                header('Location: login.php');
                exit();
                
            } else {
                $error = 'Error al actualizar contraseña. Intenta nuevamente.';
                $tipo_alerta = 'error';
            }
            
        } catch (Exception $e) {
            error_log("Error cambiando contraseña: " . $e->getMessage());
            $error = 'Error al cambiar contraseña. Intenta nuevamente.';
            $tipo_alerta = 'error';
        }
    }
}

// Obtener datos del usuario para paso 2 (si existe)
$usuario_paso2 = null;
if ($paso == 2 && isset($_SESSION['recuperar_nombre'])) {
    $usuario_paso2 = array('nombre' => $_SESSION['recuperar_nombre']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - EUNACOM</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container-login">
        <div class="login-box">
            <h2>Recuperar Contraseña</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-<?php echo $tipo_alerta; ?>">
                    <?php echo e($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo $tipo_alerta; ?>">
                    <?php echo e($mensaje); ?>
                </div>
            <?php endif; ?>
            
            <!-- ========================================== -->
            <!-- PASO 1: SOLICITAR EMAIL -->
            <!-- ========================================== -->
            <?php if ($paso == 1): ?>
                <p style="color: #666; margin-bottom: 20px;">
                    Ingresa tu email para recibir un código de recuperación
                </p>
                
                <form method="POST" action="recuperar-password.php?paso=1">
                    <?php echo campoCSRF(); ?>
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" placeholder="tu@email.com" required autofocus>
                    </div>
                    
                    <button type="submit" class="btn-primary">Solicitar Código</button>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <a href="login.php" class="link-secundario">Volver al login</a>
                    </div>
                </form>
                
            <!-- ========================================== -->
            <!-- PASO 2: MOSTRAR CÓDIGO Y VERIFICAR -->
            <!-- ========================================== -->
            <?php elseif ($paso == 2): ?>
                
                <?php if (isset($_SESSION['recuperar_codigo']) && isset($_SESSION['recuperar_nombre'])): ?>
                    <!-- Mostrar código generado -->
                    <p>Hola <strong><?php echo e($_SESSION['recuperar_nombre']); ?></strong></p>
                    
                    <div class="codigo-box">
                        <p style="margin: 0; color: #0c4a6e; font-weight: 600;">Tu código de recuperación es:</p>
                        <div class="codigo-display">
                            <?php echo $_SESSION['recuperar_codigo']; ?>
                        </div>
                        <p class="codigo-instruccion">
                            ⏰ Este código expira en <strong>15 minutos</strong><br>
                            📝 Anótalo e ingrésalo abajo para continuar
                        </p>
                    </div>
                    
                    <div class="expiracion-warning">
                        ⚠️ Si cierras esta ventana antes de usar el código, deberás solicitar uno nuevo
                    </div>
                    
                <?php endif; ?>
                
                <form method="POST" action="recuperar-password.php?paso=2" style="margin-top: 20px;">
                <?php echo campoCSRF(); ?>    
                <div class="form-group">
                        <label>Ingresa el código:</label>
                        <input 
                            type="text" 
                            name="codigo" 
                            placeholder="000000" 
                            maxlength="6" 
                            pattern="[0-9]{6}"
                            required 
                            autofocus
                            style="text-align: center; font-size: 24px; letter-spacing: 5px; font-family: monospace;"
                        >
                    </div>
                    
                    <button type="submit" class="btn-primary">Verificar Código</button>
                    
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="recuperar-password.php?paso=1" class="link-secundario">Solicitar nuevo código</a>
                    </div>
                </form>
                
            <!-- ========================================== -->
            <!-- PASO 3: NUEVA CONTRASEÑA -->
            <!-- ========================================== -->
            <?php elseif ($paso == 3): ?>
                <p style="color: #059669; margin-bottom: 20px;">
                    ✓ Código verificado. Ahora establece tu nueva contraseña
                </p>
                
                <form method="POST" action="recuperar-password.php?paso=3">
                    <?php echo campoCSRF(); ?>
                    <div class="form-group">
                        <label>Nueva Contraseña:</label>
                        <input type="password" name="nueva_password" required minlength="6" autofocus>
                        <small style="color: #666;">Mínimo 6 caracteres</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Confirmar Contraseña:</label>
                        <input type="password" name="confirmar_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn-primary">Cambiar Contraseña</button>
                </form>
                
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-focus en inputs
        document.addEventListener('DOMContentLoaded', function() {
            var input = document.querySelector('input[autofocus]');
            if (input) {
                input.focus();
            }
        });
    </script>
</body>
</html>