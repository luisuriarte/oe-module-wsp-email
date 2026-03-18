<?php
/**
 * Prueba directa de envío con UltraMsg
 * 
 * Uso:
 *   php test_ultramsg_direct.php
 */

require_once __DIR__ . '/../../../globals.php';
require_once __DIR__ . '/../vendor/ultramsg/whatsapp-php-sdk/ultramsg.class.php';

use Ultramsg\WhatsAppApi;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  PRUEBA DE ENVÍO ULTRAMSG                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Obtener configuración de la BD
$sql = "SELECT wfc.*, f.name AS facility_name, f.phone AS facility_phone
        FROM wsp_email_facility_config wfc
        LEFT JOIN facility f ON f.id = wfc.facility_id
        WHERE wfc.facility_id = 3";
$config = sqlQuery($sql);

if (empty($config)) {
    echo "❌ Error: No hay configuración para facility_id=3\n\n";
    exit(1);
}

echo "Configuración:\n";
echo "  Facility: {$config['facility_name']}\n";
echo "  Vendor: {$config['vendor']}\n";
echo "  Instance: {$config['vendor_instance']}\n";
echo "  API Key: " . substr($config['vendor_api_key'], 0, 10) . "...\n";
echo "  Enabled WSP: " . ($config['enabled_wsp'] ? 'YES' : 'NO') . "\n\n";

if ($config['vendor'] !== 'ultramsg') {
    echo "⚠️  El vendor no es 'ultramsg'. ¿Quieres cambiarlo?\n";
    echo "   Ejecuta: UPDATE wsp_email_facility_config SET vendor='ultramsg' WHERE facility_id=3;\n\n";
    exit(1);
}

// Datos de prueba
$phone = '5493404540440';  // Número de prueba (sin +)
$message = "Hola, esta es una prueba de UltraMsg desde OpenEMR.\n" .
           "Facility: {$config['facility_name']}\n" .
           "Fecha: " . date('Y-m-d H:i:s');

echo "Enviando mensaje...\n";
echo "  Phone: $phone\n";
echo "  Message: " . str_replace("\n", " | ", $message) . "\n\n";

// Inicializar UltraMsg
$ultramsg = new WhatsAppApi($config['vendor_api_key'], $config['vendor_instance']);

// Enviar mensaje de texto
echo "1. Enviando mensaje de texto...\n";
$result = $ultramsg->sendChatMessage($phone, $message);
echo "   Resultado: " . json_encode($result) . "\n\n";

if (isset($result['sent']) && $result['sent'] === true) {
    echo "   ✅ ¡Mensaje enviado correctamente!\n";
    echo "   Message ID: " . ($result['id'] ?? 'N/A') . "\n";
    echo "   Status: " . ($result['status'] ?? 'N/A') . "\n";
} else {
    echo "   ❌ Error al enviar\n";
    echo "   Error: " . ($result['error'] ?? 'Desconocido') . "\n";
    if (isset($result['desc'])) {
        echo "   Descripción: {$result['desc']}\n";
    }
}

echo "\n";

// Enviar imagen (si hay logo configurado)
if (!empty($config['logo_wsp'])) {
    $logoUrl = 'https://tudominio.com/interface/modules/custom_modules/oe-module-wsp-email/public/images/logo_wsp/' . $config['logo_wsp'];
    echo "2. Enviando imagen con logo...\n";
    echo "   URL: $logoUrl\n";
    
    $result2 = $ultramsg->sendImage($phone, $logoUrl, $message);
    echo "   Resultado: " . json_encode($result2) . "\n\n";
    
    if (isset($result2['sent']) && $result2['sent'] === true) {
        echo "   ✅ ¡Imagen enviada correctamente!\n";
    } else {
        echo "   ❌ Error al enviar imagen\n";
    }
} else {
    echo "2. ⚠️  No hay logo configurado, saltando envío de imagen.\n";
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  Prueba finalizada                                           ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";
