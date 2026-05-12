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
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #0f4c75 0%, #1b7db8 50%, #3ab4f2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 750px;
            margin: 0 auto;
        }

        /* HEADER */
        .header {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left { display: flex; align-items: center; gap: 15px; }

        .avatar {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: 700;
            flex-shrink: 0;
        }

        .header-info h2 {
            color: #2c3e50;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .header-info p {
            color: #7f8c8d;
            font-size: 0.85rem;
            margin-top: 3px;
        }

        .btn-volver {
            background: #f8f9fa;
            color: #2c3e50;
            border: 2px solid #e9ecef;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .btn-volver:hover {
            background: #e9ecef;
            border-color: #ced4da;
        }

        /* TARJETAS */
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .card-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #667eea;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        /* CAMPOS DE SOLO LECTURA */
        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .field-grid.full { grid-template-columns: 1fr; }

        .field {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 15px;
        }

        .field-label {
            font-size: 0.75rem;
            color: #95a5a6;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .field-value {
            color: #2c3e50;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-estudiante { background: #e8f4fd; color: #2980b9; }
        .badge-profesional { background: #eafaf1; color: #27ae60; }
        .badge-rut { background: #fef9e7; color: #f39c12; }
        .badge-pasaporte { background: #f4f6f7; color: #7f8c8d; }

        /* FORMULARIO EDITABLE */
        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            margin-bottom: 6px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.9rem;
        }

        input[type="email"],
        input[type="tel"] {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
            color: #2c3e50;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .hint { font-size: 0.78rem; color: #95a5a6; margin-top: 4px; }

        .btn-guardar {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 5px;
        }

        .btn-guardar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }

        /* CAMBIO DE CONTRASEÑA */
        .btn-cambiar-pass {
            display: block;
            width: 100%;
            padding: 11px;
            background: #f8f9fa;
            color: #2c3e50;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
        }

        .btn-cambiar-pass:hover {
            background: #e9ecef;
            border-color: #ced4da;
        }

        /* ALERTAS */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
        }

        .alert-error   { background: #fee; color: #c33; border: 1px solid #fcc; }
        .alert-success { background: #efe; color: #2ecc71; border: 1px solid #cfc; }

        /* MODAL CAMBIO CONTRASEÑA */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active { display: flex; }

        .modal {
            background: white;
            border-radius: 15px;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            margin: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.1rem;
        }

        .modal input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95rem;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .modal input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .modal-btns { display: flex; gap: 10px; margin-top: 5px; }

        .btn-modal-guardar {
            flex: 1;
            padding: 11px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-modal-cancelar {
            flex: 1;
            padding: 11px;
            background: #f8f9fa;
            color: #2c3e50;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .modal-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 15px;
            display: none;
        }

        @media (max-width: 500px) {
            .field-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 15px; align-items: flex-start; }
        }
    </style>
</head>
<body>
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
