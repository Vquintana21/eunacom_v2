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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f2fcfe 0%, #1c92d2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-image {
            max-width: 120px;
            width: 100%;
            height: auto;
            margin-bottom: 15px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }
        
        .logo h1 {
            color: #2c3e50;
            font-size: 2rem;
            margin-bottom: 5px;
        }
        
        .logo p {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        input:focus {
            outline: none;
            border-color: #f2fcfe;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #f2fcfe 0%, #1c92d2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        
        .divider {
            text-align: center;
            margin: 30px 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: #e9ecef;
        }
        
        .divider span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: #7f8c8d;
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
        }
        
        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        
        .forgot-password {
            text-align: right;
            margin-top: 10px;
        }
        
        .forgot-password a {
            color: #7f8c8d;
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .forgot-password a:hover {
            color: #667eea;
        }
        
        .alert-error.blocked {
            background: #ffebee;
            color: #c62828;
            border: 2px solid #ef5350;
            font-weight: 600;
        }
        
        /* Responsive para el logo */
        @media (max-width: 480px) {
            .logo-image {
                max-width: 100px;
            }
            
            .logo h1 {
                font-size: 1.6rem;
            }
        }
        
        @media (min-width: 768px) {
            .logo-image {
                max-width: 140px;
            }
        }
    </style>
</head>
<body>
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