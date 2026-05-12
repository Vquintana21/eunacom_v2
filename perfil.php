<?php
require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

requireAuth();

$usuario_session = getCurrentUser();
$pdo = getDB();

$error   = '';
$success = '';

// Cargar datos completos del usuario desde BD
$stmt = $pdo->prepare("
    SELECT u.*, un.nombre AS universidad_nombre
    FROM usuarios u
    LEFT JOIN universidades un ON un.id = u.universidad_id
    WHERE u.id = ?
");
$stmt->execute(array($usuario_session['id']));
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

// Procesar formulario de actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'actualizar_perfil') {

    verificarCSRF();

    $email_nuevo    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $telefono_nuevo = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';

    if (empty($email_nuevo) || empty($telefono_nuevo)) {
        $error = 'El correo y teléfono son obligatorios';

    } elseif (!filter_var($email_nuevo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo electrónico no es válido';

    } else {
        // Verificar que el email no esté en uso por otro usuario
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmt->execute(array($email_nuevo, $usuario_session['id']));
        if ($stmt->fetch()) {
            $error = 'El correo electrónico ya está en uso por otra cuenta';
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE usuarios SET email = ?, telefono = ? WHERE id = ?
                ");
                $stmt->execute(array($email_nuevo, $telefono_nuevo, $usuario_session['id']));

                registrarActividad($usuario_session['id'], 'actualizar_perfil', 'Perfil actualizado');

                // Recargar datos
                $stmt = $pdo->prepare("
                    SELECT u.*, un.nombre AS universidad_nombre
                    FROM usuarios u
                    LEFT JOIN universidades un ON un.id = u.universidad_id
                    WHERE u.id = ?
                ");
                $stmt->execute(array($usuario_session['id']));
                $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

                $success = 'Perfil actualizado correctamente';

            } catch (PDOException $e) {
                error_log("[Perfil] Error al actualizar: " . $e->getMessage());
                $error = 'Error al actualizar. Intente nuevamente.';
            }
        }
    }
}

// Armar nombre universidad para mostrar
function getNombreUniversidad($usuario) {
    if ($usuario['condicion'] === 'profesional') {
        return $usuario['nombre_universidad'] ?: '—';
    }
    if (!empty($usuario['universidad_nombre'])) {
        return $usuario['universidad_nombre'];
    }
    if (!empty($usuario['universidad_otro'])) {
        return $usuario['universidad_otro'];
    }
    return '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?= buildUrl('css/style.css') ?>">
</head>
<body class="page-perfil">
<div class="container">

    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <div class="avatar">
                <?php echo strtoupper(substr($usuario['nombre'], 0, 1)); ?>
            </div>
            <div class="header-info">
                <h2><?php echo e($usuario['nombre']); ?>
                    <?php if (!empty($usuario['apellido_paterno'])): ?>
                        <?php echo e($usuario['apellido_paterno']); ?>
                    <?php endif; ?>
                </h2>
                <p><?php echo e($usuario['email']); ?></p>
            </div>
        </div>
        <a href="<?php echo buildUrl('index.php'); ?>" class="btn-volver">← Volver</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">✓ <?php echo e($success); ?></div>
    <?php endif; ?>

    <!-- DATOS PERSONALES (solo lectura) -->
    <div class="card">
        <div class="card-title">👤 Datos Personales</div>
        <div class="field-grid">
            <div class="field">
                <div class="field-label">Nombres</div>
                <div class="field-value"><?php echo e($usuario['nombre']); ?></div>
            </div>
            <div class="field">
                <div class="field-label">Apellido Paterno</div>
                <div class="field-value"><?php echo e($usuario['apellido_paterno'] ?: '—'); ?></div>
            </div>
            <div class="field">
                <div class="field-label">Apellido Materno</div>
                <div class="field-value"><?php echo e($usuario['apellido_materno'] ?: '—'); ?></div>
            </div>
            <div class="field">
                <div class="field-label">Condición</div>
                <div class="field-value">
                    <?php if ($usuario['condicion'] === 'profesional'): ?>
                        <span class="badge badge-profesional">👨‍⚕️ Profesional Médico</span>
                    <?php else: ?>
                        <span class="badge badge-estudiante">📚 Estudiante</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- DOCUMENTO DE IDENTIFICACIÓN (solo lectura) -->
    <div class="card">
        <div class="card-title">🪪 Documento de Identificación</div>
        <div class="field-grid">
            <div class="field">
                <div class="field-label">Tipo de Documento</div>
                <div class="field-value">
                    <?php if ($usuario['tipo_documento'] === 'rut'): ?>
                        <span class="badge badge-rut">🇨🇱 RUT</span>
                    <?php else: ?>
                        <span class="badge badge-pasaporte">🌍 Pasaporte</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="field">
                <div class="field-label">Número de Documento</div>
                <div class="field-value"><?php echo e($usuario['numero_documento'] ?: '—'); ?></div>
            </div>
        </div>
    </div>

    <!-- DATOS ACADÉMICOS (solo lectura) -->
    <div class="card">
        <div class="card-title">🏥 Datos Académicos</div>
        <div class="field-grid">
            <?php if ($usuario['condicion'] === 'estudiante'): ?>
                <div class="field">
                    <div class="field-label">Universidad</div>
                    <div class="field-value"><?php echo e(getNombreUniversidad($usuario)); ?></div>
                </div>
                <div class="field">
                    <div class="field-label">Año de Estudio</div>
                    <div class="field-value"><?php echo e($usuario['anio_estudio'] ?: '—'); ?></div>
                </div>
            <?php else: ?>
                <div class="field">
                    <div class="field-label">País donde estudió Medicina</div>
                    <div class="field-value"><?php echo e($usuario['pais_estudio'] ?: '—'); ?></div>
                </div>
                <div class="field">
                    <div class="field-label">Universidad</div>
                    <div class="field-value"><?php echo e(getNombreUniversidad($usuario)); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- DATOS EDITABLES -->
    <div class="card">
        <div class="card-title">📬 Datos de Contacto</div>
        <form method="POST" id="perfilForm">
            <?php echo campoCSRF(); ?>
            <input type="hidden" name="action" value="actualizar_perfil">

            <div class="form-group">
                <label for="email">Correo Electrónico</label>
                <input type="email" id="email" name="email" required
                       value="<?php echo e($usuario['email']); ?>">
            </div>

            <div class="form-group">
                <label for="telefono">Número de Teléfono</label>
                <input type="tel" id="telefono" name="telefono" required
                       placeholder="+56912345678"
                       value="<?php echo e($usuario['telefono'] ?: ''); ?>">
                <div class="hint">Incluya código de país. Ej: +56912345678</div>
            </div>

            <button type="submit" class="btn-guardar">💾 Guardar Cambios</button>
        </form>
    </div>

    <!-- CAMBIO DE CONTRASEÑA -->
    <div class="card">
        <div class="card-title">🔒 Seguridad</div>
        <button class="btn-cambiar-pass" onclick="document.getElementById('modalPassword').classList.add('active')">
            🔑 Cambiar Contraseña
        </button>
    </div>

</div>

<!-- MODAL CAMBIO DE CONTRASEÑA -->
<div class="modal-overlay" id="modalPassword">
    <div class="modal">
        <h3>🔑 Cambiar Contraseña</h3>
        <div class="modal-error" id="modalError"></div>
        <input type="password" id="pass_actual" placeholder="Contraseña actual">
        <input type="password" id="pass_nueva" placeholder="Nueva contraseña (mín. 6 caracteres)">
        <input type="password" id="pass_confirmar" placeholder="Confirmar nueva contraseña">
        <div class="modal-btns">
            <button class="btn-modal-cancelar" onclick="cerrarModal()">Cancelar</button>
            <button class="btn-modal-guardar" onclick="cambiarPassword()">Guardar</button>
        </div>
    </div>
</div>

<script>
function cerrarModal() {
    document.getElementById('modalPassword').classList.remove('active');
    document.getElementById('pass_actual').value = '';
    document.getElementById('pass_nueva').value = '';
    document.getElementById('pass_confirmar').value = '';
    document.getElementById('modalError').style.display = 'none';
}

function cambiarPassword() {
    var actual    = document.getElementById('pass_actual').value;
    var nueva     = document.getElementById('pass_nueva').value;
    var confirmar = document.getElementById('pass_confirmar').value;
    var errorDiv  = document.getElementById('modalError');

    errorDiv.style.display = 'none';

    if (!actual || !nueva || !confirmar) {
        errorDiv.textContent = 'Complete todos los campos';
        errorDiv.style.display = 'block';
        return;
    }

    if (nueva.length < 6) {
        errorDiv.textContent = 'La nueva contraseña debe tener al menos 6 caracteres';
        errorDiv.style.display = 'block';
        return;
    }

    if (nueva !== confirmar) {
        errorDiv.textContent = 'Las contraseñas no coinciden';
        errorDiv.style.display = 'block';
        return;
    }

    var btn = document.querySelector('.btn-modal-guardar');
    btn.textContent = 'Guardando...';
    btn.disabled = true;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?php echo buildUrl("perfil_ajax.php"); ?>', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            btn.textContent = 'Guardar';
            btn.disabled = false;
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success) {
                    cerrarModal();
                    // Mostrar éxito
                    var alerta = document.createElement('div');
                    alerta.className = 'alert alert-success';
                    alerta.innerHTML = '✓ Contraseña actualizada correctamente';
                    document.querySelector('.container').insertBefore(alerta, document.querySelector('.card'));
                    setTimeout(function() { alerta.remove(); }, 4000);
                } else {
                    errorDiv.textContent = resp.mensaje || 'Error al cambiar contraseña';
                    errorDiv.style.display = 'block';
                }
            } catch(e) {
                errorDiv.textContent = 'Error de comunicación';
                errorDiv.style.display = 'block';
            }
        }
    };
    xhr.send('action=cambiar_password&password_actual=' + encodeURIComponent(actual) +
             '&password_nueva=' + encodeURIComponent(nueva) +
             '&csrf_token=' + encodeURIComponent('<?php echo e(obtenerTokenCSRF()); ?>'));
}

// Cerrar modal al hacer click fuera
document.getElementById('modalPassword').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
</script>
</body>
</html>
