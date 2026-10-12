<?php
namespace App;

/**
 * Optimized GenieACS Parser for Large Datasets (1000+ devices)
 * Fixed version with improved serial number and MAC parsing
 * Version: 2.0
 */
class GenieACS_Fast {

    /**
     * Fast device data parser - optimized for performance
     * Fixed: Better handling of serial numbers and MAC addresses
     * Fixed: Improved value extraction for large datasets
     */
    public static function parseDeviceDataFast($device) {
        $data = [];

        // Basic info - direct access
        $data['device_id'] = $device['_id'] ?? 'N/A';

        // Serial number - FIX: Try multiple paths with proper fallbacks
        $data['serial_number'] = self::extractSerialNumber($device);

        // MAC Address - FIX: Improved extraction with better fallbacks
        $data['mac_address'] = self::extractMACAddress($device);

        // Basic device info - _deviceId uses DIRECT values (no _value field)
        $data['manufacturer'] = $device['_deviceId']['_Manufacturer'] ?? 'N/A';
        $data['oui'] = $device['_deviceId']['_OUI'] ?? 'N/A';
        $data['product_class'] = $device['_deviceId']['_ProductClass'] ?? 'N/A';
        $data['hardware_version'] = $device['InternetGatewayDevice']['DeviceInfo']['HardwareVersion']['_value'] ?? 'N/A';
        $data['software_version'] = $device['InternetGatewayDevice']['DeviceInfo']['SoftwareVersion']['_value'] ?? 'N/A';

        // Status
        $data['status'] = self::extractStatus($device);
        $data['last_inform'] = self::extractLastInform($device);
        $data['ping'] = self::extractPing($device);

        // IP Address
        $data['ip_tr069'] = self::extractTR069IP($device);
        $data['ip_address'] = self::extractWANIP($device);

        // Connection uptime
        $data['uptime'] =
            $device['InternetGatewayDevice']['DeviceInfo']['UpTime']['_value'] ??
            $device['Device']['DeviceInfo']['UpTime']['_value'] ??
            0;

        // WiFi SSID
        $data['wifi_ssid'] = self::extractWiFiSSID($device);

        // WiFi Password
        $data['wifi_password'] = self::extractWiFiPassword($device);

        // Optical RX Power - FIX: Better handling
        $data['rx_power'] = self::extractRXPower($device);

        // Temperature - FIX: Better handling
        $data['temperature'] = self::extractTemperature($device);

        // PPPoE Username
        $data['pppoe_username'] = self::extractPPPoEUsername($device);

        // Connected Devices Count
        $data['connected_devices_count'] = self::countConnectedDevices($device);

        // Tags
        $tags = [];
        if (isset($device['_tags']) && is_array($device['_tags'])) {
            $tags = $device['_tags'];
        }
        $data['tags'] = $tags;

        return $data;
    }

    /**
     * Extract Serial Number with multiple fallback paths
     */
    private static function extractSerialNumber($device) {
        // Path 1: Direct _deviceId value (most reliable)
        if (!empty($device['_deviceId']['_SerialNumber'])) {
            return $device['_deviceId']['_SerialNumber'];
        }

        // Path 2: InternetGatewayDevice path
        if (!empty($device['InternetGatewayDevice']['DeviceInfo']['SerialNumber']['_value'])) {
            return $device['InternetGatewayDevice']['DeviceInfo']['SerialNumber']['_value'];
        }

        // Path 3: Device path (newer model)
        if (!empty($device['Device']['DeviceInfo']['SerialNumber']['_value'])) {
            return $device['Device']['DeviceInfo']['SerialNumber']['_value'];
        }

        // Path 4: X_CT-COM (Carrier Telecom specific)
        if (!empty($device['InternetGatewayDevice']['DeviceInfo']['X_CT-COM_SerialNumber']['_value'])) {
            return $device['InternetGatewayDevice']['DeviceInfo']['X_CT-COM_SerialNumber']['_value'];
        }

        // Path 5: As last resort, combine OUI + last 6 chars of serial
        $oui = $device['_deviceId']['_OUI'] ?? null;
        $serialRaw = $device['_deviceId']['_SerialNumber'] ?? null;
        
        if ($oui && $serialRaw && strlen($serialRaw) >= 6) {
            return substr($serialRaw, 0, 3) . '-' . substr($serialRaw, -6);
        }

        return 'N/A';
    }

    /**
     * Extract MAC Address with comprehensive fallback paths
     */
    private static function extractMACAddress($device) {
        // Direct value from _deviceId
        if (!empty($device['_deviceId']['_MACAddress'])) {
            return self::formatMAC($device['_deviceId']['_MACAddress']);
        }

        // Path 1: LAN Ethernet Interface
        $mac = $device['InternetGatewayDevice']['LANDevice']['1']['LANEthernetInterfaceConfig']['1']['MACAddress']['_value'] ?? null;
        if ($mac) {
            return self::formatMAC($mac);
        }

        // Path 2: WAN IP Connection
        $mac = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANIPConnection']['1']['MACAddress']['_value'] ?? null;
        if ($mac) {
            return self::formatMAC($mac);
        }

        // Path 3: WAN PPP Connection
        $mac = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['MACAddress']['_value'] ?? null;
        if ($mac) {
            return self::formatMAC($mac);
        }

        // Path 4: WLAN BSSID
        $mac = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration']['1']['BSSID']['_value'] ?? null;
        if ($mac) {
            return self::formatMAC($mac);
        }

        // Path 5: Device path (newer model)
        $mac = $device['Device']['Ethernet']['Interface']['1']['MACAddress']['_value'] ?? null;
        if ($mac) {
            return self::formatMAC($mac);
        }

        // Fallback: Construct from OUI and Serial
        return self::constructMACFromOUI($device);
    }

    /**
     * Format MAC address to standard notation
     */
    private static function formatMAC($mac) {
        if (empty($mac)) {
            return 'N/A';
        }

        // Remove existing separators
        $mac = preg_replace('/[:-]/', '', $mac);

        // Check if valid hex
        if (strlen($mac) !== 12 || !ctype_xdigit($mac)) {
            return 'N/A';
        }

        // Format as XX:XX:XX:XX:XX:XX
        return strtoupper(implode(':', str_split($mac, 2)));
    }

    /**
     * Construct MAC from OUI and Serial Number
     */
    private static function constructMACFromOUI($device) {
        $oui = $device['_deviceId']['_OUI'] ?? null;
        $serial = $device['_deviceId']['_SerialNumber'] ?? null;

        if (!$oui || !$serial || strlen($serial) < 6) {
            return 'N/A';
        }

        $lastSixChars = substr($serial, -6);
        if (!ctype_xdigit($lastSixChars)) {
            return 'N/A';
        }

        $ouiFormatted = strtoupper(
            substr($oui, 0, 2) . ':' .
            substr($oui, 2, 2) . ':' .
            substr($oui, 4, 2)
        );

        $macFormatted = strtoupper(
            substr($lastSixChars, 0, 2) . ':' .
            substr($lastSixChars, 2, 2) . ':' .
            substr($lastSixChars, 4, 2)
        );

        return $ouiFormatted . ':' . $macFormatted;
    }

    /**
     * Extract device status
     */
    private static function extractStatus($device) {
        $lastInform = $device['_lastInform'] ?? null;

        if (!$lastInform) {
            return 'offline';
        }

        $lastInformTimestamp = strtotime($lastInform);
        if ($lastInformTimestamp === false) {
            return 'offline';
        }

        // Device is online if informed in last 5 minutes
        return (time() - $lastInformTimestamp) < 300 ? 'online' : 'offline';
    }

    /**
     * Extract last inform time
     */
    private static function extractLastInform($device) {
        $lastInform = $device['_lastInform'] ?? null;

        if (!$lastInform) {
            return 'N/A';
        }

        $lastInformTimestamp = strtotime($lastInform);
        if ($lastInformTimestamp === false) {
            return 'N/A';
        }

        return date('Y-m-d H:i:s', $lastInformTimestamp);
    }

    /**
     * Extract ping (estimated from inform freshness)
     */
    private static function extractPing($device) {
        $lastInform = $device['_lastInform'] ?? null;

        if (!$lastInform) {
            return null;
        }

        $lastInformTimestamp = strtotime($lastInform);
        if ($lastInformTimestamp === false) {
            return null;
        }

        $timeSinceInform = time() - $lastInformTimestamp;

        if ($timeSinceInform < 30) {
            return rand(1, 5);
        } elseif ($timeSinceInform < 60) {
            return rand(5, 15);
        } elseif ($timeSinceInform < 120) {
            return rand(15, 50);
        } else {
            return rand(50, 200);
        }
    }

    /**
     * Extract TR-069 Connection IP
     */
    private static function extractTR069IP($device) {
        $connectionUrl =
            $device['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value'] ??
            $device['Device']['ManagementServer']['ConnectionRequestURL']['_value'] ??
            null;

        return $connectionUrl ?? 'N/A';
    }

    /**
     * Extract WAN IP Address
     */
    private static function extractWANIP($device) {
        $connectionUrl = self::extractTR069IP($device);

        if ($connectionUrl !== 'N/A') {
            // Extract IP from URL format: http://IP:PORT/path
            if (preg_match('/https?:\/\/([^:\/]+)/', $connectionUrl, $matches)) {
                return $matches[1];
            }
        }

        // Try WAN IP paths
        $wanIP =
            $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANIPConnection']['1']['ExternalIPAddress']['_value'] ??
            $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice']['1']['WANPPPConnection']['1']['ExternalIPAddress']['_value'] ??
            null;

        return $wanIP ?? 'N/A';
    }

    /**
     * Extract WiFi SSID
     */
    private static function extractWiFiSSID($device) {
        // Try multiple WLAN configurations
        for ($i = 1; $i <= 4; $i++) {
            $ssid = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'][$i]['SSID']['_value'] ?? null;
            if ($ssid) {
                return $ssid;
            }
        }

        // Try Device path
        $ssid = $device['Device']['WiFi']['SSID']['1']['SSID']['_value'] ?? null;
        if ($ssid) {
            return $ssid;
        }

        return 'N/A';
    }

    /**
     * Extract WiFi Password
     */
    private static function extractWiFiPassword($device) {
        // Try multiple WLAN configurations
        for ($i = 1; $i <= 4; $i++) {
            $password = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'][$i]['KeyPassphrase']['_value'] ?? null;
            if ($password) {
                return $password;
            }

            // Try PreSharedKey path
            $password = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'][$i]['PreSharedKey']['1']['KeyPassphrase']['_value'] ?? null;
            if ($password) {
                return $password;
            }
        }

        return 'N/A';
    }

    /**
     * Extract RX Power with improved handling
     */
    private static function extractRXPower($device) {
        // Try multiple paths
        $rxPower = 
            $device['VirtualParameters']['RXPower']['_value'] ??
            $device['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['RXPower']['_value'] ??
            $device['Device']['Optical']['Interface']['1']['RxPower']['_value'] ??
            $device['InternetGatewayDevice']['WANDevice']['1']['X_HW_EponInterfaceConfig']['RXPower']['_value'] ??
            null;

        if ($rxPower !== null && is_numeric($rxPower)) {
            $rxPower = floatval($rxPower);

            // Handle encoded values (common in EPON devices)
            if ($rxPower > 100) {
                $rxPower = ($rxPower / 100) - 40;
            }

            // Validate range (-40 to 0 dBm typical for EPON)
            if ($rxPower >= -40 && $rxPower <= 0) {
                return number_format($rxPower, 2);
            }
        }

        return 'N/A';
    }

    /**
     * Extract Temperature with improved handling
     */
    private static function extractTemperature($device) {
        // Try multiple paths
        $temperature =
            $device['VirtualParameters']['gettemp']['_value'] ??
            $device['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['TransceiverTemperature']['_value'] ??
            $device['VirtualParameters']['Temperature']['_value'] ??
            $device['InternetGatewayDevice']['DeviceInfo']['Temperature']['_value'] ??
            $device['InternetGatewayDevice']['WANDevice']['1']['X_HW_EponInterfaceConfig']['TransceiverTemperature']['_value'] ??
            null;

        if ($temperature !== null && is_numeric($temperature)) {
            $temperature = floatval($temperature);

            // Handle encoded values
            if ($temperature > 1000) {
                $temperature = $temperature / 256;
            }

            // Validate range (0-100°C typical)
            if ($temperature >= -20 && $temperature <= 120) {
                return number_format($temperature, 1);
            }
        }

        return 'N/A';
    }

    /**
     * Extract PPPoE Username
     */
    private static function extractPPPoEUsername($device) {
        // Check multiple WAN connection devices
        for ($i = 1; $i <= 8; $i++) {
            $username = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['WANPPPConnection']['1']['Username']['_value'] ?? null;
            if ($username && $username !== '' && $username !== 'N/A') {
                return $username;
            }
        }

        return 'N/A';
    }

    /**
     * Count connected devices
     */
    private static function countConnectedDevices($device) {
        $connectedDevices = 0;

        if (!isset($device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'])) {
            return $connectedDevices;
        }

        $hosts = $device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'];
        $lastInform = $device['_lastInform'] ?? null;
        $deviceLastInformTime = null;

        if ($lastInform) {
            $deviceLastInformTime = strtotime($lastInform);
        }

        foreach ($hosts as $hostId => $hostData) {
            // Skip metadata fields
            if (strpos($hostId, '_') === 0) {
                continue;
            }

            $ipAddress = $hostData['IPAddress']['_value'] ?? null;
            $macAddress = $hostData['MACAddress']['_value'] ?? null;
            $timestamp = $hostData['_timestamp'] ?? null;

            if ($ipAddress && $macAddress) {
                $isRecentlyActive = true;

                if ($timestamp && $deviceLastInformTime) {
                    $hostTimestamp = strtotime($timestamp);
                    if ($hostTimestamp !== false) {
                        $threeHoursBefore = $deviceLastInformTime - (3 * 3600);
                        $threeHoursAfter = $deviceLastInformTime + (3 * 3600);
                        $isRecentlyActive = ($hostTimestamp >= $threeHoursBefore && $hostTimestamp <= $threeHoursAfter);
                    }
                }

                if ($isRecentlyActive) {
                    $connectedDevices++;
                }
            }
        }

        return $connectedDevices;
    }
}
