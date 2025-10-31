<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// MikroTik Configuration - PALITAN MO ITO!
define('MIKROTIK_IP', '192.168.88.1'); // IP ng MikroTik mo
define('MIKROTIK_USER', 'payment-api');
define('MIKROTIK_PASS', 'YourSecurePassword123');
define('MIKROTIK_PORT', 8728);

class MikroTikAPI {
    private $socket;
    
    public function connect($host, $user, $pass, $port = 8728) {
        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new Exception("Cannot connect to MikroTik: $errstr ($errno)");
        }
        
        // Login to MikroTik
        $this->write('/login');
        $this->read();
        
        $this->write('/login', false);
        $this->write('=name=' . $user, false);
        $this->write('=password=' . $pass);
        
        $response = $this->read();
        return isset($response[0]) && $response[0] == '!done';
    }
    
    public function write($command, $end = true) {
        fwrite($this->socket, $this->encodeLength(strlen($command)) . $command);
        if ($end) fwrite($this->socket, chr(0));
    }
    
    public function read() {
        $response = array();
        while (true) {
            $word = $this->readWord();
            if ($word === '!done') break;
            if (strlen($word) > 0) {
                $response[] = $word;
            }
        }
        return $response;
    }
    
    private function readWord() {
        $length = $this->decodeLength();
        if ($length === 0) return '';
        $word = '';
        $read = 0;
        while ($read < $length) {
            $chunk = fread($this->socket, $length - $read);
            $word .= $chunk;
            $read += strlen($chunk);
        }
        return $word;
    }
    
    private function decodeLength() {
        $byte = ord(fread($this->socket, 1));
        if (($byte & 0x80) == 0) {
            return $byte;
        } elseif (($byte & 0xC0) == 0x80) {
            return (($byte & 0x3F) << 8) + ord(fread($this->socket, 1));
        } else {
            return (($byte & 0x1F) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
        }
    }
    
    private function encodeLength($len) {
        if ($len < 0x80) {
            return chr($len);
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            return chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            return chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            return chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } else {
            return chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
    }
    
    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
}

// Main API Handler
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $_POST['action'] ?? $input['action'] ?? '';
    
    $response = ['success' => false, 'message' => 'Unknown action'];
    
    switch ($action) {
        case 'authenticate':
            $username = $_POST['username'] ?? $input['username'] ?? '';
            $password = $_POST['password'] ?? $input['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                throw new Exception('Username and password required');
            }
            
            $api = new MikroTikAPI();
            if (!$api->connect(MIKROTIK_IP, MIKROTIK_USER, MIKROTIK_PASS)) {
                throw new Exception('Cannot connect to MikroTik router');
            }
            
            // 🔐 CRITICAL: Eto ang authentication sa MikroTik
            // Naghahanap ng user na match ang username AT password
            $api->write('/ppp/secret/print', false);
            $api->write('?name=' . $username, false);
            $api->write('?password=' . $password);
            $users = $api->read();
            
            // Kung may user na match, authenticated
            $authenticated = (count($users) > 0);
            
            if ($authenticated) {
                // Kunin ang user details
                $api->write('/ppp/secret/print', false);
                $api->write('?name=' . $username);
                $userDetails = $api->read();
                
                $userData = [];
                foreach ($userDetails as $detail) {
                    if (strpos($detail, '=') !== false) {
                        list($key, $value) = explode('=', $detail, 2);
                        $userData[$key] = $value;
                    }
                }
                
                // Kunin ang connection status
                $api->write('/interface/pppoe-server/print', false);
                $api->write('?user=' . $username);
                $sessions = $api->read();
                $isOnline = (count($sessions) > 0);
                
                $response = [
                    'success' => true,
                    'message' => 'Authentication successful',
                    'user' => [
                        'username' => $username,
                        'profile' => $userData['profile'] ?? 'default',
                        'service' => $userData['service'] ?? 'pppoe',
                        'limit_bytes' => $userData['limit-bytes-total'] ?? 0,
                        'disabled' => isset($userData['disabled']) ? $userData['disabled'] == 'true' : false,
                        'online' => $isOnline
                    ]
                ];
                
                // Log successful login
                error_log("SUCCESS: User $username logged in");
                
            } else {
                $response = [
                    'success' => false,
                    'message' => 'Invalid PPPoE username or password'
                ];
                
                // Log failed login
                error_log("FAILED: Login attempt for user $username");
            }
            
            $api->disconnect();
            break;
            
        case 'extend_subscription':
            $username = $_POST['username'] ?? $input['username'] ?? '';
            $days = $_POST['days'] ?? $input['days'] ?? 30;
            
            if (empty($username)) {
                throw new Exception('Username required');
            }
            
            $api = new MikroTikAPI();
            if (!$api->connect(MIKROTIK_IP, MIKROTIK_USER, MIKROTIK_PASS)) {
                throw new Exception('Cannot connect to MikroTik');
            }
            
            // Calculate bytes to add (1GB per day)
            $bytesPerDay = 1073741824;
            $bytesToAdd = $days * $bytesPerDay;
            
            // Get user current limit
            $api->write('/ppp/secret/print', false);
            $api->write('?name=' . $username);
            $userDetails = $api->read();
            
            if (count($userDetails) == 0) {
                throw new Exception("User $username not found");
            }
            
            // Extract current limit
            $currentLimit = 0;
            foreach ($userDetails as $detail) {
                if (strpos($detail, 'limit-bytes-total=') === 0) {
                    $currentLimit = str_replace('limit-bytes-total=', '', $detail);
                    break;
                }
            }
            
            $newLimit = $currentLimit + $bytesToAdd;
            
            // Update user in MikroTik
            $api->write('/ppp/secret/set', false);
            $api->write('=.id=' . $userDetails[0], false); // Use the first element as ID
            $api->write('=limit-bytes-total=' . $newLimit);
            $api->read();
            
            $response = [
                'success' => true,
                'message' => "Subscription extended by $days days successfully",
                'transaction_id' => 'TX' . time() . rand(1000, 9999),
                'days_added' => $days,
                'old_limit' => $currentLimit,
                'new_limit' => $newLimit
            ];
            
            // Log the transaction
            error_log("PAYMENT: User $username extended by $days days. New limit: $newLimit bytes");
            
            $api->disconnect();
            break;
            
        case 'get_user_status':
            $username = $_POST['username'] ?? $input['username'] ?? '';
            
            if (empty($username)) {
                throw new Exception('Username required');
            }
            
            $api = new MikroTikAPI();
            if (!$api->connect(MIKROTIK_IP, MIKROTIK_USER, MIKROTIK_PASS)) {
                throw new Exception('Cannot connect to MikroTik');
            }
            
            // Get user details
            $api->write('/ppp/secret/print', false);
            $api->write('?name=' . $username);
            $userDetails = $api->read();
            
            if (count($userDetails) == 0) {
                throw new Exception("User $username not found");
            }
            
            $userData = [];
            foreach ($userDetails as $detail) {
                if (strpos($detail, '=') !== false) {
                    list($key, $value) = explode('=', $detail, 2);
                    $userData[$key] = $value;
                }
            }
            
            // Get active sessions and bytes used
            $api->write('/interface/pppoe-server/print', false);
            $api->write('?user=' . $username);
            $sessions = $api->read();
            
            $isOnline = (count($sessions) > 0);
            $bytesUsed = 0;
            
            if ($isOnline && count($sessions) > 0) {
                foreach ($sessions[0] as $sessionDetail) {
                    if (strpos($sessionDetail, 'bytes-out=') === 0) {
                        $bytesUsed = str_replace('bytes-out=', '', $sessionDetail);
                        break;
                    }
                }
            }
            
            $response = [
                'success' => true,
                'data' => [
                    'username' => $username,
                    'profile' => $userData['profile'] ?? 'default',
                    'service' => $userData['service'] ?? 'pppoe',
                    'limit_bytes' => $userData['limit-bytes-total'] ?? 0,
                    'bytes_used' => $bytesUsed,
                    'online' => $isOnline,
                    'disabled' => isset($userData['disabled']) ? $userData['disabled'] == 'true' : false
                ]
            ];
            
            $api->disconnect();
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Unknown action'];
    }
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
    
    // Log errors
    error_log("API ERROR: " . $e->getMessage());
}

echo json_encode($response);
?>