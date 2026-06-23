<?php
/**
 * config_status.messages.php — Mapeo y normalización de estados de mensajes.
 * 
 * Define los estados canónicos y su mapeo desde diferentes proveedores de WhatsApp.
 * 
 * @package   OpenEMR\Modules\WspEmail
 * @author    Luis A. Uriarte <luis.uriarte@gmail.com>
 * @copyright Copyright (c) 2024-2025 Luis A. Uriarte
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

return [
    /**
     * Estados canónicos del sistema (orden jerárquico)
     * 
     * Estos son los estados normalizados que se usan internamente.
     * Cada proveedor mapea sus estados nativos a estos estados canónicos.
     */
    'canonical' => [
        'QUEUED',      // En cola / pendiente de envío
        'SENT',        // Enviado al proveedor WhatsApp
        'DELIVERED',   // Entregado al dispositivo del destinatario
        'READ',        // Leído por el destinatario (o played en audio)
        'FAILED',      // Falló el envío (reintentos agotados)
        'INVALID',     // Número inválido o no existe
        'ERROR',       // Error técnico del proveedor
        'UNSENT'       // Cancelado / no enviado
    ],

    /**
     * Orden de prioridad de estados (para determinar el estado más reciente)
     * 
     * Valores más altos = estado más avanzado en el ciclo de vida
     */
    'priority' => [
        'QUEUED'    => 1,
        'SENT'      => 2,
        'DELIVERED' => 3,
        'READ'      => 4,
        'FAILED'    => 5,
        'INVALID'   => 5,
        'ERROR'     => 5,
        'UNSENT'    => 0
    ],

    /**
     * Mapeo de estados por proveedor
     * 
     * Las claves son los estados nativos del proveedor (en minúsculas).
     * Los valores son los estados canónicos correspondientes.
     */
    'providers' => [
        /**
         * Meta (WhatsApp Business API)
         * https://developers.facebook.com/docs/whatsapp/on-premises/webhooks/components#statuses-object
         */
        'meta' => [
            'sent'      => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'failed'    => 'FAILED',
            'deleted'   => 'UNSENT',
            'warning'   => 'ERROR',
        ],

        /**
         * UltraMsg
         * https://ultramsg.com/docs/
         */
        'ultramsg' => [
            'queued'    => 'QUEUED',
            'sent'      => 'SENT',
            'ack'       => 'SENT',         // ACK = enviado desde servidor
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'played'    => 'READ',         // Audio/video reproducido
            'error'     => 'ERROR',
            'failed'    => 'FAILED',
            'invalid'   => 'INVALID',
        ],

        /**
         * WaSenderAPI
         * https://wasenderapi.com/docs
         */
        'wasenderapi' => [
            'queued'    => 'QUEUED',
            'sending'   => 'SENT',         // Enviando al dispositivo
            'sent'      => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'played'    => 'READ',         // Audio reproducido
            'device_offline' => 'DELIVERED', // Dispositivo offline pero entregado
            'fail'      => 'FAILED',
            'failed'    => 'FAILED',
            'error'     => 'ERROR',
            'invalid'   => 'INVALID',
            'unsent'    => 'UNSENT',
        ],

        /**
         * OpenWA
         * https://github.com/rmyndharis/OpenWA
         */
        'openwa' => [
            // Campo canónico recomendado (data.status) — a prueba de futuro
            'pending'   => 'QUEUED',
            'sent'      => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'failed'    => 'FAILED',

            // Campo legacy (data.ack, deprecado) — se sigue usando hoy en tu webhook
            '0'  => 'QUEUED',
            '1'  => 'SENT',
            '2'  => 'DELIVERED',
            '3'  => 'READ',
            '-1' => 'FAILED',
        ],

        /**
         * Twilio WhatsApp API
         * https://www.twilio.com/docs/whatsapp/api
         */
        'twilio' => [
            'queued'        => 'QUEUED',
            'accepted'      => 'QUEUED',   // Aceptado por Twilio
            'sending'       => 'SENT',
            'sent'          => 'SENT',
            'delivered'     => 'DELIVERED',
            'read'          => 'READ',
            'undelivered'   => 'FAILED',   // No entregado
            'failed'        => 'FAILED',
            'canceled'      => 'UNSENT',
        ],

        /**
         * WhatsAppApi (Brazil)
         * https://www.whatsappapi.com/
         */
        'whatsappapi' => [
            'waiting'   => 'QUEUED',
            'sending'   => 'SENT',
            'sent'      => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'played'    => 'READ',
            'error'     => 'ERROR',
            'failed'    => 'FAILED',
        ],

        /**
         * Evolution-Go
         * https://github.com/Evolution-Go/evolution-api
         * Statuses: PENDING, SENT, DELIVERED, READ, FAILED, ERROR
         */
        'evolution-go' => [
            'pending'   => 'QUEUED',
            'queued'    => 'QUEUED',     // Some Evolution builds use 'queued'
            'sending'   => 'SENT',       // Occasionally used during transitions
            'sent'      => 'SENT',
            'success'   => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'played'    => 'READ',       // Audio/video playback
            'failed'    => 'FAILED',
            'error'     => 'ERROR',
            'invalid'   => 'INVALID',    // Invalid number response
        ],

        /**
         * Estado por defecto si no se reconoce
         */
        'default' => [
            'pending'   => 'QUEUED',
            'queued'    => 'QUEUED',
            'sending'   => 'SENT',
            'sent'      => 'SENT',
            'success'   => 'SENT',
            'delivered' => 'DELIVERED',
            'read'      => 'READ',
            'failed'    => 'FAILED',
            'error'     => 'ERROR',
        ]
    ],

    /**
     * Colores para cada estado canónico (para UI)
     */
    'colors' => [
        'QUEUED'    => ['#FFC107', '#FFA000'],    // Ámbar
        'SENT'      => ['#25D366', '#128C7E'],    // Verde WhatsApp
        'DELIVERED' => ['#2196F3', '#1565C0'],    // Azul
        'READ'      => ['#9C27B0', '#7B1FA2'],    // Violeta
        'FAILED'    => ['#F44336', '#C62828'],    // Rojo
        'INVALID'   => ['#9E9E9E', '#616161'],    // Gris
        'ERROR'     => ['#FF5722', '#E64A19'],    // Naranja Rojizo
        'UNSENT'    => ['#BDBDBD', '#757575'],    // Gris Claro
    ],

    /**
     * Iconos FontAwesome para cada estado
     */
    'icons' => [
        'QUEUED'    => 'fa-clock',
        'SENT'      => 'fa-check',
        'DELIVERED' => 'fa-box',
        'READ'      => 'fa-eye',
        'FAILED'    => 'fa-times-circle',
        'INVALID'   => 'fa-question-circle',
        'ERROR'     => 'fa-exclamation-triangle',
        'UNSENT'    => 'fa-envelope',
    ],

    /**
     * Etiquetas amigables para UI (traducibles)
     */
    'labels' => [
        'QUEUED'    => 'En Cola',
        'SENT'      => 'Enviado',
        'DELIVERED' => 'Entregado',
        'READ'      => 'Leído',
        'FAILED'    => 'Fallido',
        'INVALID'   => 'Inválido',
        'ERROR'     => 'Error',
        'UNSENT'    => 'No Enviado',
    ],
];
