<?php
require_once 'auth.php';

if (isLoggedIn()) {
    header('Location: ' . buildUrl('index.php'));
    exit;
}

$error = '';
$success = '';

// Cargar universidades desde BD
$universidades = array();
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT id, nombre FROM universidades ORDER BY nombre ASC");
    $universidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[Registro] Error al cargar universidades: " . $e->getMessage());
}

// Años de estudio
$anios_estudio = array('1° año', '2° año', '3° año', '4° año', '5° año', '6° año', '7° año', 'Egresado/Interno');

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {

    verificarCSRF();

    // Recoger datos
    $nombre          = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $apellido_pat    = isset($_POST['apellido_paterno']) ? trim($_POST['apellido_paterno']) : '';
    $apellido_mat    = isset($_POST['apellido_materno']) ? trim($_POST['apellido_materno']) : '';
    $tipo_doc        = isset($_POST['tipo_documento']) ? trim($_POST['tipo_documento']) : '';
    $num_doc         = isset($_POST['numero_documento']) ? trim($_POST['numero_documento']) : '';
    $condicion       = isset($_POST['condicion']) ? trim($_POST['condicion']) : '';
    $universidad_id  = isset($_POST['universidad_id']) ? intval($_POST['universidad_id']) : 0;
    $universidad_otro= isset($_POST['universidad_otro']) ? trim($_POST['universidad_otro']) : '';
    $anio_estudio    = isset($_POST['anio_estudio']) ? trim($_POST['anio_estudio']) : '';
    $nombre_univ     = isset($_POST['nombre_universidad']) ? trim($_POST['nombre_universidad']) : '';
    $pais_estudio    = isset($_POST['pais_estudio']) ? trim($_POST['pais_estudio']) : '';
    $email           = isset($_POST['email']) ? trim($_POST['email']) : '';
    $telefono        = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
    $password        = isset($_POST['password']) ? $_POST['password'] : '';
    $password_confirm= isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

    // Validaciones
    if (empty($nombre) || empty($apellido_pat) || empty($apellido_mat) || empty($tipo_doc) ||
        empty($num_doc) || empty($condicion) || empty($email) || empty($telefono) ||
        empty($password) || empty($password_confirm)) {
        $error = 'Por favor complete todos los campos obligatorios';

    } elseif (!in_array($tipo_doc, array('rut', 'pasaporte'))) {
        $error = 'Tipo de documento inválido';

    } elseif ($tipo_doc === 'rut' && !validarRut($num_doc)) {
        $error = 'El RUT ingresado no es válido. Formato: 12345678-9';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido';

    } elseif ($password !== $password_confirm) {
        $error = 'Las contraseñas no coinciden';

    } elseif (strlen($password) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres';

    } elseif ($condicion === 'estudiante' && $universidad_id === 0 && empty($universidad_otro)) {
        $error = 'Debe seleccionar o ingresar su universidad';

    } elseif ($condicion === 'estudiante' && empty($anio_estudio)) {
        $error = 'Debe seleccionar su año de estudio';

    } elseif ($condicion === 'profesional' && empty($pais_estudio)) {
        $error = 'Debe ingresar el país donde estudió medicina';

    } else {
        // Armar nombre completo para columna "nombre" existente
        $nombre_completo = $nombre;

        $resultado = registrarUsuarioCompleto(
            $nombre_completo, $nombre, $apellido_pat, $apellido_mat,
            $tipo_doc, $num_doc, $condicion,
            $universidad_id > 0 ? $universidad_id : null,
            $universidad_otro, $anio_estudio, $nombre_univ,
            $pais_estudio, $email, $telefono, $password
        );

        if ($resultado['success']) {
            $login_result = iniciarSesion($email, $password);
            if ($login_result['success']) {
                regenerarTokenCSRF();
                header('Location: ' . buildUrl('index.php?bienvenida=1'));
                exit;
            } else {
                $success = 'Registro exitoso. Por favor inicia sesión.';
            }
        } else {
            $error = $resultado['mensaje'];
        }
    }
}

// ============================================
// FUNCIÓN VALIDAR RUT CHILENO
// ============================================
function validarRut($rut) {
    $rut = preg_replace('/[^0-9kK]/', '', $rut);
    if (strlen($rut) < 2) return false;
    $dv  = strtolower(substr($rut, -1));
    $num = substr($rut, 0, -1);
    if (!is_numeric($num)) return false;
    $suma = 0;
    $mul  = 2;
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $suma += $num[$i] * $mul;
        $mul = $mul < 7 ? $mul + 1 : 2;
    }
    $dvEsperado = 11 - ($suma % 11);
    if ($dvEsperado == 11) $dvEsperado = '0';
    elseif ($dvEsperado == 10) $dvEsperado = 'k';
    else $dvEsperado = (string)$dvEsperado;
    return $dv === $dvEsperado;
}

// ============================================
// FUNCIÓN REGISTRAR USUARIO COMPLETO
// ============================================
function registrarUsuarioCompleto($nombre_completo, $nombre, $apellido_pat, $apellido_mat,
    $tipo_doc, $num_doc, $condicion, $universidad_id, $universidad_otro,
    $anio_estudio, $nombre_universidad, $pais_estudio, $email, $telefono, $password) {

    $pdo = getDB();

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array('success' => false, 'mensaje' => 'Email inválido');
    }

    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->execute(array($email));
    if ($stmt->fetch()) {
        return array('success' => false, 'mensaje' => 'El email ya está registrado');
    }

    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE numero_documento = ? AND tipo_documento = ?");
    $stmt->execute(array($num_doc, $tipo_doc));
    if ($stmt->fetch()) {
        return array('success' => false, 'mensaje' => 'El documento de identidad ya está registrado');
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (
                nombre, apellido_paterno, apellido_materno,
                tipo_documento, numero_documento,
                condicion, universidad_id, anio_estudio,
                universidad_otro, nombre_universidad, pais_estudio,
                email, telefono, password_hash,
                tipo_usuario, activo, fecha_registro
            ) VALUES (
                ?, ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                'estudiante', 1, NOW()
            )
        ");
        $stmt->execute(array(
            $nombre_completo, $apellido_pat, $apellido_mat,
            $tipo_doc, $num_doc,
            $condicion, $universidad_id, $anio_estudio,
            $universidad_otro, $nombre_universidad, $pais_estudio,
            $email, $telefono, $password_hash
        ));

        $usuario_id = $pdo->lastInsertId();
        registrarActividad($usuario_id, 'registro', "Usuario registrado: $email");

        return array('success' => true, 'mensaje' => 'Registro exitoso', 'usuario_id' => $usuario_id);

    } catch (PDOException $e) {
        error_log("[Auth] Error al registrar: " . $e->getMessage());
        return array('success' => false, 'mensaje' => 'Error al registrar. Intente nuevamente.');
    }
}

// Helper para repoblar campos
function old($field, $default = '') {
    return isset($_POST[$field]) ? htmlspecialchars(trim($_POST[$field]), ENT_QUOTES, 'UTF-8') : $default;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro - EUNACOM</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 30px 20px;
        }

        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 600px;
            width: 100%;
            margin-bottom: 30px;
        }

        .register-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 35px 30px;
            text-align: center;
        }

        .register-header h1 { font-size: 2rem; margin-bottom: 8px; }
        .register-header p { opacity: 0.9; font-size: 1rem; }

        .register-body { padding: 35px 30px; }

        .section-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #667eea;
            margin: 25px 0 15px 0;
            padding-bottom: 6px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-title:first-of-type { margin-top: 0; }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group { flex: 1; }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            margin-bottom: 6px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.9rem;
        }

        label .req { color: #e74c3c; margin-left: 2px; }

        input[type="email"],
        input[type="password"],
        input[type="text"],
        input[type="tel"],
        select {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            color: #2c3e50;
            background: #fff;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        select { cursor: pointer; }

        .radio-group {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }

        .radio-option {
            flex: 1;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 15px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .radio-option:hover { border-color: #667eea; background: #f8f7ff; }

        .radio-option input[type="radio"] {
            width: auto;
            padding: 0;
            border: none;
            accent-color: #667eea;
        }

        .radio-option.selected {
            border-color: #667eea;
            background: #f0eeff;
        }

        .radio-option span { font-weight: 600; color: #2c3e50; font-size: 0.9rem; }

        /* Secciones condicionales */
        .conditional-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 5px;
            display: none;
            border-left: 3px solid #667eea;
        }

        .conditional-section.visible { display: block; }

        /* Otro campo universidad */
        #div_universidad_otro { display: none; margin-top: 12px; }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .alert-error { background: #fee; color: #c33; border: 1px solid #fcc; }
        .alert-success { background: #efe; color: #3c3; border: 1px solid #cfc; }

        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
        }

        .password-strength-bar { height: 100%; width: 0%; transition: all 0.3s; }
        .strength-weak   { width: 33%; background: #e74c3c; }
        .strength-medium { width: 66%; background: #f39c12; }
        .strength-strong { width: 100%; background: #27ae60; }

        .password-hint { font-size: 0.8rem; color: #7f8c8d; margin-top: 4px; }

        .hint { font-size: 0.78rem; color: #95a5a6; margin-top: 4px; }

        .divider {
            text-align: center;
            margin: 25px 0;
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            left: 0; top: 50%;
            width: 100%; height: 1px;
            background: #e9ecef;
        }

        .divider span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .login-link { text-align: center; margin-top: 15px; font-size: 0.95rem; }
        .login-link a { color: #667eea; text-decoration: none; font-weight: 600; }
        .login-link a:hover { text-decoration: underline; }

        @media (max-width: 500px) {
            .form-row { flex-direction: column; gap: 0; }
            .radio-group { flex-direction: column; }
            .register-body { padding: 25px 20px; }
        }
    </style>
</head>
<body>
<div class="register-container">
    <div class="register-header">
        <h1>🎓 EUNACOM</h1>
        <p>Crear Nueva Cuenta</p>
    </div>

    <div class="register-body">

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">✓ <?= e($success) ?></div>
        <?php endif; ?>

        <form method="POST" id="registerForm">
            <?php echo campoCSRF(); ?>
            <input type="hidden" name="action" value="register">

            <!-- DATOS PERSONALES -->
            <div class="section-title">👤 Datos Personales</div>

            <div class="form-group">
                <label for="nombre">Nombres <span class="req">*</span></label>
                <input type="text" id="nombre" name="nombre" required
                       placeholder="Ej: Juan Carlos"
                       value="<?= old('nombre') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="apellido_paterno">Apellido Paterno <span class="req">*</span></label>
                    <input type="text" id="apellido_paterno" name="apellido_paterno" required
                           placeholder="Ej: González"
                           value="<?= old('apellido_paterno') ?>">
                </div>
                <div class="form-group">
                    <label for="apellido_materno">Apellido Materno <span class="req">*</span></label>
                    <input type="text" id="apellido_materno" name="apellido_materno" required
                           placeholder="Ej: Pérez"
                           value="<?= old('apellido_materno') ?>">
                </div>
            </div>

            <!-- DOCUMENTO DE IDENTIDAD -->
            <div class="section-title">🪪 Documento de Identificación</div>

            <div class="form-group">
                <label>Tipo de Documento <span class="req">*</span></label>
                <div class="radio-group">
                    <label class="radio-option <?= old('tipo_documento') === 'rut' || old('tipo_documento') === '' ? 'selected' : '' ?>" id="label_rut">
                        <input type="radio" name="tipo_documento" value="rut"
                               <?= old('tipo_documento', 'rut') === 'rut' ? 'checked' : '' ?>>
                        <span>🇨🇱 RUT</span>
                    </label>
                    <label class="radio-option <?= old('tipo_documento') === 'pasaporte' ? 'selected' : '' ?>" id="label_pasaporte">
                        <input type="radio" name="tipo_documento" value="pasaporte"
                               <?= old('tipo_documento') === 'pasaporte' ? 'checked' : '' ?>>
                        <span>🌍 Pasaporte</span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="numero_documento" id="label_num_doc">
                    Número de RUT <span class="req">*</span>
                </label>
                <input type="text" id="numero_documento" name="numero_documento" required
                       placeholder="Ej: 12345678-9"
                       maxlength="12"
                       value="<?= old('numero_documento') ?>">
                <div class="hint" id="hint_doc">Sin puntos, con guión. Ej: 12345678-9</div>
            </div>

            <!-- CONDICIÓN -->
            <div class="section-title">🏥 Condición</div>

            <div class="form-group">
                <label>¿Eres estudiante o profesional médico? <span class="req">*</span></label>
                <div class="radio-group">
                    <label class="radio-option <?= old('condicion', 'estudiante') === 'estudiante' ? 'selected' : '' ?>" id="label_estudiante">
                        <input type="radio" name="condicion" value="estudiante"
                               <?= old('condicion', 'estudiante') === 'estudiante' ? 'checked' : '' ?>>
                        <span>📚 Estudiante</span>
                    </label>
                    <label class="radio-option <?= old('condicion') === 'profesional' ? 'selected' : '' ?>" id="label_profesional">
                        <input type="radio" name="condicion" value="profesional"
                               <?= old('condicion') === 'profesional' ? 'checked' : '' ?>>
                        <span>👨‍⚕️ Profesional Médico</span>
                    </label>
                </div>
            </div>

            <!-- SECCIÓN ESTUDIANTE -->
            <div class="conditional-section <?= old('condicion', 'estudiante') === 'estudiante' ? 'visible' : '' ?>"
                 id="seccion_estudiante">
                <div class="form-group">
                    <label for="universidad_id">Universidad de Estudio <span class="req">*</span></label>
                    <select id="universidad_id" name="universidad_id">
                        <option value="0">-- Seleccione su universidad --</option>
                        <?php foreach ($universidades as $u): ?>
                            <option value="<?= $u['id'] ?>"
                                <?= old('universidad_id') == $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="-1" <?= old('universidad_id') == -1 ? 'selected' : '' ?>>
                            Otra universidad (no está en la lista)
                        </option>
                    </select>
                </div>

                <div id="div_universidad_otro" class="form-group"
                     style="display:<?= old('universidad_id') == -1 ? 'block' : 'none' ?>">
                    <label for="universidad_otro">Nombre de su Universidad <span class="req">*</span></label>
                    <input type="text" id="universidad_otro" name="universidad_otro"
                           placeholder="Ingrese el nombre de su universidad"
                           value="<?= old('universidad_otro') ?>">
                </div>

                <div class="form-group">
                    <label for="anio_estudio">Año de Estudio <span class="req">*</span></label>
                    <select id="anio_estudio" name="anio_estudio">
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($anios_estudio as $anio): ?>
                            <option value="<?= e($anio) ?>"
                                <?= old('anio_estudio') === $anio ? 'selected' : '' ?>>
                                <?= e($anio) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- SECCIÓN PROFESIONAL -->
            <div class="conditional-section <?= old('condicion') === 'profesional' ? 'visible' : '' ?>"
                 id="seccion_profesional">
                <div class="form-group">
                    <label for="pais_estudio">País donde estudió Medicina <span class="req">*</span></label>
                    <input type="text" id="pais_estudio" name="pais_estudio"
                           placeholder="Ej: Chile, Argentina, Cuba..."
                           value="<?= old('pais_estudio') ?>">
                </div>
                <div class="form-group">
                    <label for="nombre_universidad">Universidad donde estudió</label>
                    <input type="text" id="nombre_universidad" name="nombre_universidad"
                           placeholder="Nombre de su universidad"
                           value="<?= old('nombre_universidad') ?>">
                </div>
            </div>

            <!-- CONTACTO -->
            <div class="section-title">📬 Datos de Contacto</div>

            <div class="form-group">
                <label for="email">Correo Electrónico <span class="req">*</span></label>
                <input type="email" id="email" name="email" required
                       placeholder="correo@ejemplo.com"
                       value="<?= old('email') ?>">
            </div>

            <div class="form-group">
                <label for="telefono">Número de Teléfono <span class="req">*</span></label>
                <input type="tel" id="telefono" name="telefono" required
                       placeholder="Ej: +56912345678"
                       maxlength="20"
                       value="<?= old('telefono') ?>">
                <div class="hint">Incluya código de país. Ej: +56912345678</div>
            </div>

            <!-- CONTRASEÑA -->
            <div class="section-title">🔒 Contraseña</div>

            <div class="form-group">
                <label for="password">Contraseña <span class="req">*</span></label>
                <input type="password" id="password" name="password" required>
                <div class="password-strength">
                    <div class="password-strength-bar" id="strengthBar"></div>
                </div>
                <div class="password-hint">Mínimo 6 caracteres</div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmar Contraseña <span class="req">*</span></label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>

            <button type="submit" class="btn">✅ Crear Cuenta</button>
        </form>

        <div class="divider"><span>o</span></div>

        <div class="login-link">
            ¿Ya tienes cuenta? <a href="<?= buildUrl('login.php') ?>">Inicia sesión aquí</a>
        </div>
    </div>
</div>

<script>
// ---- Tipo documento ----
var radiosDoc = document.querySelectorAll('input[name="tipo_documento"]');
var labelNumDoc = document.getElementById('label_num_doc');
var hintDoc = document.getElementById('hint_doc');
var inputDoc = document.getElementById('numero_documento');

radiosDoc.forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.radio-option').forEach(function(el) {
            // solo resetear los del grupo tipo_documento
        });
        document.getElementById('label_rut').classList.toggle('selected', document.querySelector('input[name="tipo_documento"][value="rut"]').checked);
        document.getElementById('label_pasaporte').classList.toggle('selected', document.querySelector('input[name="tipo_documento"][value="pasaporte"]').checked);

        if (this.value === 'rut') {
            labelNumDoc.innerHTML = 'Número de RUT <span class="req">*</span>';
            hintDoc.textContent = 'Sin puntos, con guión. Ej: 12345678-9';
            inputDoc.placeholder = 'Ej: 12345678-9';
            inputDoc.maxLength = 12;
        } else {
            labelNumDoc.innerHTML = 'Número de Pasaporte <span class="req">*</span>';
            hintDoc.textContent = 'Ingrese el número tal como aparece en su pasaporte';
            inputDoc.placeholder = 'Ej: AB1234567';
            inputDoc.maxLength = 20;
        }
    });
});

// ---- Condición (estudiante / profesional) ----
var radiosCond = document.querySelectorAll('input[name="condicion"]');
var secEstudiante = document.getElementById('seccion_estudiante');
var secProfesional = document.getElementById('seccion_profesional');

radiosCond.forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.getElementById('label_estudiante').classList.toggle('selected', this.value === 'estudiante');
        document.getElementById('label_profesional').classList.toggle('selected', this.value === 'profesional');

        if (this.value === 'estudiante') {
            secEstudiante.classList.add('visible');
            secProfesional.classList.remove('visible');
        } else {
            secProfesional.classList.add('visible');
            secEstudiante.classList.remove('visible');
        }
    });
});

// ---- Otra universidad ----
document.getElementById('universidad_id').addEventListener('change', function() {
    var div = document.getElementById('div_universidad_otro');
    if (this.value === '-1') {
        div.style.display = 'block';
        document.getElementById('universidad_otro').required = true;
    } else {
        div.style.display = 'none';
        document.getElementById('universidad_otro').required = false;
        document.getElementById('universidad_otro').value = '';
    }
});

// ---- Medidor de contraseña ----
document.getElementById('password').addEventListener('input', function() {
    var pwd = this.value;
    var bar = document.getElementById('strengthBar');
    var score = 0;
    if (pwd.length >= 6) score++;
    if (pwd.length >= 10) score++;
    if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
    if (/\d/.test(pwd)) score++;
    if (/[^a-zA-Z0-9]/.test(pwd)) score++;
    bar.className = 'password-strength-bar';
    if (score <= 2) bar.classList.add('strength-weak');
    else if (score <= 4) bar.classList.add('strength-medium');
    else bar.classList.add('strength-strong');
});

// ---- Validación antes de enviar ----
document.getElementById('registerForm').addEventListener('submit', function(e) {
    var pwd = document.getElementById('password').value;
    var confirm = document.getElementById('password_confirm').value;
    if (pwd !== confirm) {
        e.preventDefault();
        alert('Las contraseñas no coinciden');
        return;
    }

    // Validar RUT si aplica
    var tipoDoc = document.querySelector('input[name="tipo_documento"]:checked').value;
    if (tipoDoc === 'rut') {
        var rut = document.getElementById('numero_documento').value.trim();
        if (!validarRutJS(rut)) {
            e.preventDefault();
            alert('El RUT ingresado no es válido. Formato: 12345678-9');
            document.getElementById('numero_documento').focus();
            return;
        }
    }
});

// Validación RUT en JS (doble validación cliente)
function validarRutJS(rut) {
    rut = rut.replace(/[^0-9kK]/g, '');
    if (rut.length < 2) return false;
    var dv  = rut.slice(-1).toLowerCase();
    var num = rut.slice(0, -1);
    if (isNaN(num)) return false;
    var suma = 0, mul = 2;
    for (var i = num.length - 1; i >= 0; i--) {
        suma += parseInt(num[i]) * mul;
        mul = mul < 7 ? mul + 1 : 2;
    }
    var dvEsperado = 11 - (suma % 11);
    if (dvEsperado === 11) dvEsperado = '0';
    else if (dvEsperado === 10) dvEsperado = 'k';
    else dvEsperado = String(dvEsperado);
    return dv === dvEsperado;
}

// Formatear RUT automáticamente mientras escribe
document.getElementById('numero_documento').addEventListener('input', function() {
    var tipoDoc = document.querySelector('input[name="tipo_documento"]:checked').value;
    if (tipoDoc !== 'rut') return;
    var val = this.value.replace(/[^0-9kK]/g, '');
    if (val.length > 1) {
        var cuerpo = val.slice(0, -1);
        var dv = val.slice(-1);
        this.value = cuerpo + '-' + dv;
    }
});
</script>
</body>
</html>
