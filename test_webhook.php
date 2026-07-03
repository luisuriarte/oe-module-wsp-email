<?php
/**
 * test_webhook.php — Test para verificar el webhook de OpenWA
 *
 * Correr desde terminal: php test_webhook.php
 * O desde el navegador si está en un directorio accesible.
 *
 * IMPORTANTE: Copiar este archivo a la raíz de OpenEMR (junto a webhook/)
 * o correrlo desde la línea de comandos.
 */

// --- Configuración ---
$webhookUrl    = 'https://hcd.origen.ar/webhook/openwa/webhook.php?token=5Wq6hKnwlLiMIgadFb9pmNjzoRt0CPuQ';
$webhookLocal  = __DIR__ . '/webhook/openwa/webhook.php';   // Para test local de ruta
$logFile       = __DIR__ . '/webhook/logs/openwa_webhook.log';

$testResults = [];
$passed = 0;
$failed = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $testResults, $passed, $failed;
    $status = $condition ? '✅ PASS' : '❌ FAIL';
    if ($condition) $passed++; else $failed++;
    echo "  $status: $name";
    if ($detail) echo " — $detail";
    echo "\n";
    $testResults[] = ['name' => $name, 'passed' => $condition, 'detail' => $detail];
}

echo "\n============================================\n";
echo "  TEST WEBHOOK — OpenWA\n";
echo "============================================\n\n";

// --- 1. Verificar que el archivo webhook existe ---
echo "1) Verificación de archivos\n";
test('webhook.php existe', file_exists($webhookLocal), $webhookLocal);

$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
test('directorio de logs existe', is_dir($logDir), $logDir);
test('directorio de logs escribible', is_writable($logDir), $logDir);

// --- 2. Parsear el webhook para verificar sintaxis PHP ---
echo "\n2) Verificación de sintaxis PHP\n";
$output = [];
$returnVar = 0;
exec("php -l " . escapeshellarg($webhookLocal) . " 2>&1", $output, $returnVar);
test('sintaxis PHP válida', $returnVar === 0, implode("\n", $output));

// --- 3. Test de conexión HTTP (requiere curl) ---
echo "\n3) Test de conexión HTTP\n";

// 3a. Test event (ping)
$testPayload = json_encode([
    'event'     => 'test',
    'sessionId' => 'test-session',
]);

$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $testPayload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_SSL_VERIFYPEER => 0,
    CURLOPT_HEADER         => true,
]);
$response     = curl_exec($ch);
$httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error        = curl_error($ch);
$headerSize   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$responseBody = substr($response, $headerSize);
curl_close($ch);

if ($error) {
    test('conexión HTTP', false, "Error: $error");
} else {
    test('conexión HTTP', true, "HTTP $httpCode");
    test('código HTTP 200', $httpCode === 200, "Recibido: $httpCode");
    
    $respData = json_decode($responseBody, true);
    if ($respData) {
        test('respuesta JSON válida', true, json_encode($respData));
        test('status ok en test event', ($respData['status'] ?? '') === 'ok', 
             "status: " . ($respData['status'] ?? 'N/A'));
    } else {
        test('respuesta JSON válida', false, "Respuesta cruda: " . substr($responseBody, 0, 200));
    }
}

// 3b. Simular un message.ack
echo "\n4) Simulación de eventos\n";

$simulatedPayloads = [
    'message.sent' => [
        'event'     => 'message.sent',
        'sessionId' => 'test-session',
        'data' => [
            'messageId' => 'TEST_MSG_' . time(),
            'chatId'    => '5493404540440@c.us',
        ],
    ],
    'message.ack (delivered)' => [
        'event'     => 'message.ack',
        'sessionId' => 'test-session',
        'data' => [
            'messageId' => 'TEST_MSG_' . time(),
            'chatId'    => '5493404540440@c.us',
            'ack'       => 2,
            'status'    => 'delivered',
        ],
    ],
    'message.revoked' => [
        'event'     => 'message.revoked',
        'sessionId' => 'test-session',
        'data' => [
            'messageId' => 'TEST_MSG_' . time(),
            'chatId'    => '5493404540440@c.us',
        ],
    ],
];

foreach ($simulatedPayloads as $eventName => $payload) {
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_HEADER         => true,
    ]);
    $response     = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error        = curl_error($ch);
    $headerSize   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseBody = substr($response, $headerSize);
    curl_close($ch);

    if ($error) {
        test($eventName, false, "Error: $error");
    } else {
        test($eventName, $httpCode === 200, "HTTP $httpCode — " . trim($responseBody));
    }
}

// --- 5. Verificar logs ---
echo "\n5) Verificación de logs\n";
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    $logLines = count(explode("\n", trim($logContent)));
    test('archivo de log existe', true, $logFile);
    test('log tiene entradas nuevas', $logLines > 0, "$logLines líneas");
    echo "\nÚltimas 5 líneas del log:\n";
    $lines = array_slice(array_reverse(explode("\n", trim($logContent))), 0, 5);
    foreach (array_reverse($lines) as $line) {
        echo "  $line\n";
    }
} else {
    test('archivo de log existe', false, "No se encontró: $logFile");
    echo "  (Los logs se crearán cuando el webhook reciba su primer evento real)\n";
}

// --- Resumen ---
echo "\n============================================\n";
echo "  RESULTADOS: $passed passed, $failed failed\n";
echo "============================================\n\n";

if ($failed > 0) {
    echo "⚠️  Algunos tests fallaron. Revisá los detalles arriba.\n";
    echo "   Posibles causas:\n";
    echo "   - El webhook URL no es accesible desde este servidor\n";
    echo "   - El token en la URL no coincide con el configurado\n";
    echo "   - El archivo webhook no está copiado a la raíz de OpenEMR\n";
    echo "   - La ruta de logs no existe o no tiene permisos de escritura\n";
} else {
    echo "✅ Todos los tests pasaron. El webhook está funcionando correctamente.\n";
    echo "   Ahora asegurate de:\n";
    echo "   1. Configurar wa.origen.ar para que envíe webhooks a:\n";
    echo "      $webhookUrl\n";
    echo "   2. Tener la facility configurada en OpenEMR con:\n";
    echo "      - openwa_instance = el sessionId que usa wa.origen.ar\n";
    echo "      - openwa_webhook_secret = 5Wq6hKnwlLiMIgadFb9pmNjzoRt0CPuQ\n";
    echo "   3. Verificar que los msg_id en notification_log coinciden\n\n";
}
