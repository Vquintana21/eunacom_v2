<?php
require_once __DIR__ . '/env/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

requireAuth();

$usuario = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('success' => false, 'mensaje' => 'Método no permitido'));
    exit;
}

verificarCSRF(false);

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'cambiar_password') {
    $password_actual = isset($_POST['password_actual']) ? $_POST['password_actual'] : '';
    $password_nueva  = isset($_POST['password_nueva'])  ? $_POST['password_nueva']  : '';

    if (empty($password_actual) || empty($password_nueva)) {
        echo json_encode(array('success' => false, 'mensaje' => 'Complete todos los campos'));
        exit;
    }

    if (strlen($password_nueva) < 6) {
        echo json_encode(array('success' => false, 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres'));
        exit;
    }

    $resultado = cambiarPassword($usuario['id'], $password_actual, $password_nueva);
    echo json_encode($resultado);
    exit;
}

echo json_encode(array('success' => false, 'mensaje' => 'Acción no reconocida'));
