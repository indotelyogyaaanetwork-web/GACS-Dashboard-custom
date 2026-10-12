<?php
/**
 * ONT Parser Debug Script
 * Membantu diagnosa masalah parsing data ONT dari GenieACS
 * 
 * Usage: 
 * GET /api/debug-ont-parser.php?device_id=<id> - Lihat raw data + parsed result
 * GET /api/debug-ont-parser.php?count=10 - Lihat statistik 10 device pertama
 * GET /api/debug-ont-parser.php?validate=1 - Validasi semua device
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
    exit;
}

use App\GenieACS;
use App\GenieACS_Fast;

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
    exit;
}

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

// Mode 1: Debug single device
if (isset($_GET['device_id'])) {
    $deviceId = $_GET['device_id'];
    debugSingleDevice($genieacs, $deviceId);
}
// Mode 2: Sample statistics
elseif (isset($_GET['count'])) {
    $count = max(1, min(100, (int)$_GET['count']));
    debugSampleDevices($genieacs, $count);
}
// Mode 3: Full validation
elseif (isset($_GET['validate'])) {
    validateAllDevices($genieacs);
}
// Default: show usage
else {
    showUsage();
}

/**
 * Debug single device raw data + parsed result
 */
function debugSingleDevice($genieacs, $deviceId) {
    $devicesResult = $genieacs->getDevices();
    
    if (!$devicesResult['success']) {
        jsonResponse(['success' => false, 'message' => 'Gagal mengambil data devices']);
        return;
    }

    $device = null;
    foreach ($devicesResult['data'] as $d) {
        if (($d['_id'] ?? null) === $deviceId) {
            $device = $d;
            break;
        }
    }

    if (!$device) {
        jsonResponse(['success' => false, 'message' => "Device ID $deviceId tidak ditemukan"]);
        return;
    }

    // Parse device
    $parsed = GenieACS_Fast::parseDeviceDataFast($device);

    // Extract important fields dari raw data
    $rawAnalysis = analyzeRawDevice($device);

    jsonResponse([
        'success' => true,
        'device_id' => $deviceId,
        'parsed_result' => $parsed,
        'raw_analysis' => $rawAnalysis,
        'data_structure' => getDataStructure($device),
        'field_locations' => findFieldLocations($device)
    ]);
}

/**
 * Sample debug dari N devices pertama
 */
function debugSampleDevices($genieacs, $count) {
    $devicesResult = $genieacs->getDevices([], $count);
    
    if (!$devicesResult['success']) {
        jsonResponse(['success' => false, 'message' => 'Gagal mengambil data devices']);
        return;
    }

    $devices = $devicesResult['data'];
    $statistics = [
        'total_checked' => count($devices),
        'serial_success' => 0,
        'mac_success' => 0,
        'rx_power_found' => 0,
        'temperature_found' => 0,
        'missing_fields' => [],
        'parse_errors' => [],
        'devices' => []
    ];

    foreach ($devices as $device) {
        $parsed = GenieACS_Fast::parseDeviceDataFast($device);
        $deviceData = [
            'device_id' => $parsed['device_id'],
            'serial' => $parsed['serial_number'],
            'mac' => $parsed['mac_address'],
            'rx_power' => $parsed['rx_power'],
            'temperature' => $parsed['temperature'],
            'status' => $parsed['status']
        ];

        if ($parsed['serial_number'] !== 'N/A') $statistics['serial_success']++;
        if ($parsed['mac_address'] !== 'N/A') $statistics['mac_success']++;
        if ($parsed['rx_power'] !== 'N/A') $statistics['rx_power_found']++;
        if ($parsed['temperature'] !== 'N/A') $statistics['temperature_found']++;

        // Check for missing critical fields
        $missing = [];
        if ($parsed['serial_number'] === 'N/A') $missing[] = 'serial_number';
        if ($parsed['mac_address'] === 'N/A') $missing[] = 'mac_address';
        if ($parsed['rx_power'] === 'N/A') $missing[] = 'rx_power';
        
        if (!empty($missing)) {
            $statistics['missing_fields'][] = [
                'device_id' => $parsed['device_id'],
                'missing' => $missing
            ];
        }

        $statistics['devices'][] = $deviceData;
    }

    $statistics['serial_success_rate'] = round(($statistics['serial_success'] / $statistics['total_checked']) * 100, 2) . '%';
    $statistics['mac_success_rate'] = round(($statistics['mac_success'] / $statistics['total_checked']) * 100, 2) . '%';
    $statistics['rx_power_found_rate'] = round(($statistics['rx_power_found'] / $statistics['total_checked']) * 100, 2) . '%';

    jsonResponse([
        'success' => true,
        'statistics' => $statistics
    ]);
}

/**
 * Validate all devices (with limit)
 */
function validateAllDevices($genieacs) {
    $devicesResult = $genieacs->getDevices([], 50); // Limit to 50 to avoid timeout
    
    if (!$devicesResult['success']) {
        jsonResponse(['success' => false, 'message' => 'Gagal mengambil data devices']);
        return;
    }

    $devices = $devicesResult['data'];
    $issues = [
        'total_devices' => count($devices),
        'critical_issues' => 0,
        'warnings' => 0,
        'device_issues' => [],
        'summary' => []
    ];

    foreach ($devices as $device) {
        $parsed = GenieACS_Fast::parseDeviceDataFast($device);
        $deviceIssues = [];

        // Check critical fields
        if ($parsed['serial_number'] === 'N/A') {
            $deviceIssues[] = [
                'severity' => 'CRITICAL',
                'field' => 'serial_number',
                'message' => 'Serial number tidak dapat diparsing'
            ];
            $issues['critical_issues']++;
        }

        if ($parsed['mac_address'] === 'N/A') {
            $deviceIssues[] = [
                'severity' => 'CRITICAL',
                'field' => 'mac_address',
                'message' => 'MAC address tidak dapat diparsing'
            ];
            $issues['critical_issues']++;
        }

        if ($parsed['status'] === 'offline' && $parsed['last_inform'] === 'N/A') {
            $deviceIssues[] = [
                'severity' => 'WARNING',
                'field' => 'status',
                'message' => 'Device offline dan tidak ada last inform info'
            ];
            $issues['warnings']++;
        }

        if ($parsed['rx_power'] === 'N/A') {
            $deviceIssues[] = [
                'severity' => 'INFO',
                'field' => 'rx_power',
                'message' => 'RX Power tidak ditemukan (mungkin bukan EPON device)'
            ];
        }

        if (!empty($deviceIssues)) {
            $issues['device_issues'][] = [
                'device_id' => $parsed['device_id'],
                'serial' => $parsed['serial_number'],
                'issues' => $deviceIssues
            ];
        }
    }

    $issues['summary'] = [
        'total_devices_checked' => $issues['total_devices'],
        'devices_with_critical_issues' => count(array_filter($issues['device_issues'], function($d) {
            return !empty(array_filter($d['issues'], function($i) { return $i['severity'] === 'CRITICAL'; }));
        })),
        'critical_issue_count' => $issues['critical_issues'],
        'warning_count' => $issues['warnings']
    ];

    jsonResponse([
        'success' => true,
        'validation' => $issues
    ]);
}

/**
 * Analyze raw device data structure
 */
function analyzeRawDevice($device) {
    $analysis = [
        'has_deviceId' => isset($device['_deviceId']),
        'deviceId_fields' => isset($device['_deviceId']) ? array_keys($device['_deviceId']) : [],
        'has_InternetGatewayDevice' => isset($device['InternetGatewayDevice']),
        'has_Device' => isset($device['Device']),
        'has_VirtualParameters' => isset($device['VirtualParameters']),
        'has_lastInform' => isset($device['_lastInform']),
        'lastInform_value' => $device['_lastInform'] ?? null,
        'top_level_keys' => array_filter(array_keys($device), function($k) { return strpos($k, '_') === 0; })
    ];

    return $analysis;
}

/**
 * Get data structure summary
 */
function getDataStructure($device) {
    $structure = [
        '_deviceId' => isset($device['_deviceId']) ? implode(', ', array_keys($device['_deviceId'])) : 'NOT_PRESENT',
        'InternetGatewayDevice.DeviceInfo' => isset($device['InternetGatewayDevice']['DeviceInfo']) 
            ? implode(', ', array_keys($device['InternetGatewayDevice']['DeviceInfo'])) 
            : 'NOT_PRESENT',
        'Device' => isset($device['Device']) 
            ? implode(', ', array_keys($device['Device'])) 
            : 'NOT_PRESENT',
        'VirtualParameters' => isset($device['VirtualParameters']) 
            ? implode(', ', array_keys($device['VirtualParameters'])) 
            : 'NOT_PRESENT'
    ];

    return $structure;
}

/**
 * Find all locations where key fields are stored
 */
function findFieldLocations($device) {
    $locations = [
        'serial_number' => [],
        'mac_address' => [],
        'rx_power' => [],
        'temperature' => []
    ];

    // Search for serial number
    searchInDevice($device, function($path, $value) {
        if (stripos($path, 'serial') !== false) {
            return true;
        }
        return false;
    }, $locations['serial_number']);

    // Search for MAC
    searchInDevice($device, function($path, $value) {
        if (stripos($path, 'mac') !== false || stripos($path, 'bssid') !== false) {
            return true;
        }
        return false;
    }, $locations['mac_address']);

    // Search for RX Power
    searchInDevice($device, function($path, $value) {
        if (stripos($path, 'rx') !== false || stripos($path, 'power') !== false) {
            return true;
        }
        return false;
    }, $locations['rx_power']);

    // Search for Temperature
    searchInDevice($device, function($path, $value) {
        if (stripos($path, 'temp') !== false || stripos($path, 'temperature') !== false) {
            return true;
        }
        return false;
    }, $locations['temperature']);

    return $locations;
}

/**
 * Recursive search in device data
 */
function searchInDevice($device, $callback, &$results, $path = '') {
    if (!is_array($device)) {
        return;
    }

    foreach ($device as $key => $value) {
        $currentPath = $path ? "$path.$key" : $key;

        if ($callback($currentPath, $value)) {
            $results[] = [
                'path' => $currentPath,
                'value' => is_scalar($value) ? $value : (is_array($value) ? json_encode($value) : gettype($value))
            ];
        }

        if (is_array($value) && !is_numeric($key)) {
            searchInDevice($value, $callback, $results, $currentPath);
        }
    }
}

/**
 * Show usage information
 */
function showUsage() {
    jsonResponse([
        'success' => true,
        'message' => 'ONT Parser Debug Script',
        'usage' => [
            'debug_single_device' => '/api/debug-ont-parser.php?device_id=<device_id>',
            'sample_devices' => '/api/debug-ont-parser.php?count=<count>',
            'validate_all' => '/api/debug-ont-parser.php?validate=1',
            'examples' => [
                'Debug first device' => 'curl "/api/debug-ont-parser.php?count=1"',
                'Sample 10 devices' => 'curl "/api/debug-ont-parser.php?count=10"',
                'Validate issues' => 'curl "/api/debug-ont-parser.php?validate=1"'
            ]
        ]
    ]);
}
