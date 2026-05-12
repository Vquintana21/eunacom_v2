<?php
/**
 * ============================================
 * PÁGINA DE LOGIN
 * ============================================
 */

require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

// Si ya está logueado, redirigir al dashboard
requireGuest();

// Variables
$error = '';
$success = '';

// Procesar formulario de login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    
    // Validar token CSRF
    verificarCSRF();
    
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($email) || empty($password)) {
        $error = 'Por favor complete todos los campos';
    } else {
        $resultado = iniciarSesion($email, $password);
        
        if ($resultado['success']) {
            // Regenerar token CSRF después de login exitoso
            regenerarTokenCSRF();
            // Redirigir al dashboard
            redirect(buildUrl('index.php'));
        } else {
            $error = $resultado['mensaje'];
        }
    }
}

// Verificar mensajes de query string
if (isset($_GET['expired'])) {
    $error = 'Tu sesión ha expirado. Por favor inicia sesión nuevamente.';
}
if (isset($_GET['registered'])) {
    $success = '¡Registro exitoso! Ahora puedes iniciar sesión.';
}
if (isset($_GET['logout'])) {
    $success = 'Has cerrado sesión correctamente.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-login">
    <div class="login-container">
        <div class="logo">
            <img src="<?= buildUrl('img/logo.png') ?>" alt="Logo <?= SITE_NAME ?>" class="logo-image">
            <h3>🏥 <?= SITE_NAME ?></h3>
            <p>Plataforma de Preparación</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                ⚠️ <?= e($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                ✅ <?= e($success) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
             <?php echo campoCSRF(); ?>
            <div class="form-group">
                <label for="email">Email</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="tu@email.com"
                    value="<?= isset($_POST['email']) ? e($_POST['email']) : '' ?>"
                    required
                >
            </div>
            
            <div class="form-group">
                <label for="password">Contraseña</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    placeholder="••••••••"
                    required>
            </div>	
            
            <button type="submit" name="login" class="btn">
                🔐 Iniciar Sesión
            </button>
            </br>
            <div class="register-link">
                <a href="<?= buildUrl('recuperar-password.php') ?>">¿Olvidaste tu contraseña?</a>
            </div>
        </form>
        
        <div class="divider">
            <span>o</span>
        </div>
        
        <div class="register-link">
            <b>¿No tienes cuenta?</b> <a href="<?= buildUrl('registro.php') ?>">Regístrate aquí</a>
        </div>
    </div>
</body>
</html>