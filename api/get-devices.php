<?php
require_once __DIR__ . '/../config/config.php';

// Increase timeout for large dataset
set_time_limit(300);
ini_set('max_execution_time', 300);

header('Content-Type: application/json');
requireLogin();

// Check if GenieACS is configured
if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
    exit;
}

// Get GenieACS credentials
$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
    exit;
}

use App\GenieACS;
use App\GenieACS_Fast;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

// Get pagination parameters
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;

// Parser selection: 'fast' (default for performance) or 'full' (for complete data)
$parser = isset($_GET['parser']) ? $_GET['parser'] : 'fast';
$useFastParser = ($parser === 'fast');

try {
    $devicesResult = $genieacs->getDevices([], $limit, $skip);

    if ($devicesResult['success']) {
        $devices = [];

        // Use selected parser - Fast parser optimized for performance
        foreach ($devicesResult['data'] as $device) {
            $parsed = GenieACS_Fast::parseDeviceDataFast($device);
            $devices[] = $parsed;
        }

        $response = [
            'success' => true,
            'devices' => $devices,
            'count' => count($devices),
            'total' => count($devices),
            'hasMore' => count($devices) === $limit,
            'pagination' => [
                'limit' => $limit,
                'skip' => $skip,
                'returned' => count($devices),
                'nextSkip' => $skip + $limit
            ]
        ];

        jsonResponse($response);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Gagal mengambil data devices',
            'error' => $devicesResult['error'] ?? 'Unknown error'
        ]);
    }
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
