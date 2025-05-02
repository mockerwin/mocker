<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// This is a WebSocket server implementation
// In a production environment, you would use a proper WebSocket server (Ratchet, ReactPHP, etc.)
// Or a third-party service for real-time communication

/**
 * WebSocket Server class - handles WebSocket connections
 */
class WebSocketServer {
    protected $clients = [];
    protected $authenticatedUsers = [];
    protected $db;
    protected $host = '';
    protected $port = 8000;
    protected $socket;
    
    /**
     * Initialize WebSocket server
     * 
     * @param string $host Host to bind to
     * @param int $port Port to listen on
     */
    public function __construct($host = '0.0.0.0', $port = 8000) {
        $this->host = $host;
        $this->port = $port;
        $this->db = DB::getInstance();
        
        $this->log("WebSocket server initializing on {$this->host}:{$this->port}");
    }
    
    /**
     * Start the WebSocket server
     */
    public function run() {
        // Create WebSocket server
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        
        // Set socket options
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        // Bind socket to address and port
        if (!socket_bind($this->socket, $this->host, $this->port)) {
            $this->log("Failed to bind to {$this->host}:{$this->port}");
            exit(1);
        }
        
        // Start listening for connections
        if (!socket_listen($this->socket)) {
            $this->log("Failed to listen on {$this->host}:{$this->port}");
            exit(1);
        }
        
        $this->log("WebSocket server started on {$this->host}:{$this->port}");
        
        // Main server loop
        while (true) {
            // Create a copy of the master socket
            $read = [$this->socket];
            $write = [];
            $except = [];
            
            // Add all client sockets to read array
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }
            
            // Check for socket activity
            if (socket_select($read, $write, $except, 0) < 1) {
                usleep(100000); // Sleep for 100ms to reduce CPU usage
                continue;
            }
            
            // Check if master socket has activity (new connection)
            if (in_array($this->socket, $read)) {
                $clientSocket = socket_accept($this->socket);
                $this->handleNewConnection($clientSocket);
                
                // Remove master socket from read array
                $key = array_search($this->socket, $read);
                unset($read[$key]);
            }
            
            // Check client sockets for activity
            foreach ($read as $socket) {
                $clientId = $this->getClientIdBySocket($socket);
                
                if ($clientId !== false) {
                    $data = $this->receiveData($socket);
                    
                    if ($data === false) {
                        // Connection closed
                        $this->handleDisconnection($clientId);
                    } else {
                        // Process received data
                        $this->handleData($clientId, $data);
                    }
                }
            }
            
            // Periodic tasks
            $this->performPeriodicTasks();
        }
    }
    
    /**
     * Handle a new client connection
     * 
     * @param resource $socket Client socket
     */
    protected function handleNewConnection($socket) {
        // Perform WebSocket handshake
        $this->handshake($socket);
        
        // Generate client ID
        $clientId = $this->generateClientId();
        
        // Add client to list
        $this->clients[$clientId] = [
            'socket' => $socket,
            'handshaked' => true,
            'authenticated' => false,
            'userId' => null,
            'lastActivity' => time()
        ];
        
        $this->log("New client connected: {$clientId}");
    }
    
    /**
     * Handle WebSocket handshake
     * 
     * @param resource $socket Client socket
     * @return bool Handshake success
     */
    protected function handshake($socket) {
        $header = socket_read($socket, 1024);
        if (!$header) {
            return false;
        }
        
        // Extract WebSocket key
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $header, $matches)) {
            $key = $matches[1];
            
            // Calculate response key
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            // Create handshake response
            $response = "HTTP/1.1 101 Switching Protocols\r\n";
            $response .= "Upgrade: websocket\r\n";
            $response .= "Connection: Upgrade\r\n";
            $response .= "Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
            
            // Send handshake response
            socket_write($socket, $response, strlen($response));
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Handle client disconnection
     * 
     * @param string $clientId Client ID
     */
    protected function handleDisconnection($clientId) {
        if (isset($this->clients[$clientId])) {
            $userId = $this->clients[$clientId]['userId'];
            
            // Remove user from authenticated users
            if ($userId && isset($this->authenticatedUsers[$userId])) {
                unset($this->authenticatedUsers[$userId]);
                $this->log("User {$userId} disconnected");
                
                // Broadcast updated online users
                $this->broadcastOnlineUsers();
            }
            
            // Close socket
            @socket_close($this->clients[$clientId]['socket']);
            
            // Remove client
            unset($this->clients[$clientId]);
            
            $this->log("Client disconnected: {$clientId}");
        }
    }
    
    /**
     * Handle received data from client
     * 
     * @param string $clientId Client ID
     * @param string $data Received data
     */
    protected function handleData($clientId, $data) {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        
        // Update last activity time
        $this->clients[$clientId]['lastActivity'] = time();
        
        // Parse JSON data
        try {
            $message = json_decode($data, true);
            
            if (!isset($message['type'])) {
                return;
            }
            
            switch ($message['type']) {
                case 'auth':
                    $this->handleAuthentication($clientId, $message);
                    break;
                    
                case 'chat':
                    $this->handleChatMessage($clientId, $message);
                    break;
                    
                case 'typing':
                    $this->handleTypingNotification($clientId, $message);
                    break;
                    
                case 'heartbeat':
                    $this->sendToClient($clientId, [
                        'type' => 'heartbeat'
                    ]);
                    break;
                    
                default:
                    $this->log("Unknown message type: {$message['type']}");
                    break;
            }
        } catch (Exception $e) {
            $this->log("Error handling data: " . $e->getMessage());
        }
    }
    
    /**
     * Handle authentication request
     * 
     * @param string $clientId Client ID
     * @param array $message Authentication message
     */
    protected function handleAuthentication($clientId, $message) {
        if (!isset($message['userId']) || !isset($message['token'])) {
            $this->sendToClient($clientId, [
                'type' => 'auth',
                'success' => false,
                'message' => 'Missing authentication data'
            ]);
            return;
        }
        
        $userId = (int) $message['userId'];
        $token = $message['token'];
        
        try {
            // Check token in database
            $query = "SELECT * FROM user_tokens 
                     WHERE user_id = ? AND token = ? AND type = 'session' AND expires_at > NOW()";
            $this->db->query($query, [$userId, $token]);
            $result = $this->db->fetch();
            
            if ($result) {
                // Get user data
                $query = "SELECT id, username, avatar, role FROM users 
                         WHERE id = ? AND is_active = TRUE";
                $this->db->query($query, [$userId]);
                $user = $this->db->fetch();
                
                if ($user) {
                    // Mark client as authenticated
                    $this->clients[$clientId]['authenticated'] = true;
                    $this->clients[$clientId]['userId'] = $userId;
                    
                    // Add to authenticated users
                    $this->authenticatedUsers[$userId] = $clientId;
                    
                    // Send success response
                    $this->sendToClient($clientId, [
                        'type' => 'auth',
                        'success' => true,
                        'user' => $user
                    ]);
                    
                    $this->log("User {$userId} authenticated");
                    
                    // Send recent messages
                    $this->sendRecentMessages($clientId);
                    
                    // Broadcast updated online users
                    $this->broadcastOnlineUsers();
                } else {
                    $this->sendToClient($clientId, [
                        'type' => 'auth',
                        'success' => false,
                        'message' => 'Kullanıcı bulunamadı veya hesap aktif değil'
                    ]);
                }
            } else {
                $this->sendToClient($clientId, [
                    'type' => 'auth',
                    'success' => false,
                    'message' => 'Geçersiz token'
                ]);
            }
        } catch (Exception $e) {
            $this->log("Authentication error: " . $e->getMessage());
            $this->sendToClient($clientId, [
                'type' => 'auth',
                'success' => false,
                'message' => 'Kimlik doğrulama hatası'
            ]);
        }
    }
    
    /**
     * Handle chat message
     * 
     * @param string $clientId Client ID
     * @param array $message Chat message
     */
    protected function handleChatMessage($clientId, $message) {
        // Check if client is authenticated
        if (!$this->isClientAuthenticated($clientId)) {
            $this->sendToClient($clientId, [
                'type' => 'error',
                'message' => 'Authenticated connection required'
            ]);
            return;
        }
        
        if (!isset($message['message']) || empty(trim($message['message']))) {
            return;
        }
        
        $userId = $this->clients[$clientId]['userId'];
        $text = trim($message['message']);
        
        // Filter message content (prevent XSS, etc.)
        $text = strip_tags($text);
        
        try {
            // Save message to database
            $query = "INSERT INTO chat_messages (user_id, message, created_at) 
                     VALUES (?, ?, NOW()) RETURNING id, created_at";
            $this->db->query($query, [$userId, $text]);
            $result = $this->db->fetch();
            
            if ($result) {
                // Get user data
                $query = "SELECT username, avatar, role FROM users WHERE id = ?";
                $this->db->query($query, [$userId]);
                $user = $this->db->fetch();
                
                // Create message data
                $messageData = [
                    'type' => 'chat',
                    'id' => $result['id'],
                    'user_id' => $userId,
                    'username' => $user['username'],
                    'avatar' => $user['avatar'] ?: '',
                    'role' => $user['role'],
                    'message' => $text,
                    'created_at' => $result['created_at']
                ];
                
                // Broadcast message to all clients
                $this->broadcast($messageData);
                
                $this->log("User {$userId} sent a message");
            }
        } catch (Exception $e) {
            $this->log("Error saving chat message: " . $e->getMessage());
        }
    }
    
    /**
     * Handle typing notification
     * 
     * @param string $clientId Client ID
     * @param array $message Typing notification message
     */
    protected function handleTypingNotification($clientId, $message) {
        // Check if client is authenticated
        if (!$this->isClientAuthenticated($clientId)) {
            return;
        }
        
        if (!isset($message['typing'])) {
            return;
        }
        
        $userId = $this->clients[$clientId]['userId'];
        $isTyping = (bool) $message['typing'];
        
        try {
            // Get user data
            $query = "SELECT username FROM users WHERE id = ?";
            $this->db->query($query, [$userId]);
            $user = $this->db->fetch();
            
            if ($user) {
                // Create typing notification data
                $typingData = [
                    'type' => 'typing',
                    'user_id' => $userId,
                    'username' => $user['username'],
                    'typing' => $isTyping
                ];
                
                // Broadcast to all clients except sender
                $this->broadcast($typingData, [$clientId]);
                
                $this->log("User {$userId} " . ($isTyping ? 'is typing' : 'stopped typing'));
            }
        } catch (Exception $e) {
            $this->log("Error handling typing notification: " . $e->getMessage());
        }
    }
    
    /**
     * Send recent chat messages to client
     * 
     * @param string $clientId Client ID
     * @param int $limit Maximum number of messages to send
     */
    protected function sendRecentMessages($clientId, $limit = 20) {
        try {
            // Get recent messages
            $query = "SELECT c.id, c.user_id, u.username, u.avatar, u.role, c.message, c.created_at 
                     FROM chat_messages c 
                     JOIN users u ON c.user_id = u.id 
                     ORDER BY c.created_at DESC 
                     LIMIT ?";
            $this->db->query($query, [$limit]);
            $messages = $this->db->fetchAll();
            
            // Reverse to get chronological order
            $messages = array_reverse($messages);
            
            // Send to client
            $this->sendToClient($clientId, [
                'type' => 'recent_messages',
                'messages' => $messages
            ]);
        } catch (Exception $e) {
            $this->log("Error sending recent messages: " . $e->getMessage());
        }
    }
    
    /**
     * Broadcast online users to all clients
     */
    protected function broadcastOnlineUsers() {
        try {
            if (empty($this->authenticatedUsers)) {
                return;
            }
            
            // Get user IDs
            $userIds = array_keys($this->authenticatedUsers);
            
            // Create placeholders for SQL IN clause
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            
            // Get user data
            $query = "SELECT id, username, avatar, role FROM users WHERE id IN ({$placeholders})";
            $this->db->query($query, $userIds);
            $onlineUsers = $this->db->fetchAll();
            
            // Broadcast to all authenticated clients
            $this->broadcast([
                'type' => 'online_users',
                'users' => $onlineUsers,
                'count' => count($onlineUsers)
            ]);
        } catch (Exception $e) {
            $this->log("Error broadcasting online users: " . $e->getMessage());
        }
    }
    
    /**
     * Perform periodic tasks
     */
    protected function performPeriodicTasks() {
        static $lastCleanup = 0;
        static $lastOnlineUpdate = 0;
        
        $now = time();
        
        // Clean up inactive clients every 60 seconds
        if ($now - $lastCleanup > 60) {
            $this->cleanupInactiveClients();
            $lastCleanup = $now;
        }
        
        // Update online users every 30 seconds
        if ($now - $lastOnlineUpdate > 30) {
            $this->broadcastOnlineUsers();
            $lastOnlineUpdate = $now;
        }
    }
    
    /**
     * Clean up inactive clients
     */
    protected function cleanupInactiveClients() {
        $now = time();
        $timeout = 300; // 5 minutes
        
        foreach ($this->clients as $clientId => $client) {
            if ($now - $client['lastActivity'] > $timeout) {
                $this->log("Client {$clientId} timed out");
                $this->handleDisconnection($clientId);
            }
        }
    }
    
    /**
     * Receive data from client socket
     * 
     * @param resource $socket Client socket
     * @return string|false Received data or false on connection close/error
     */
    protected function receiveData($socket) {
        $data = @socket_read($socket, 1024, PHP_BINARY_READ);
        
        if ($data === false || strlen($data) === 0) {
            return false;
        }
        
        return $this->decodeWebSocketFrame($data);
    }
    
    /**
     * Decode WebSocket frame
     * 
     * @param string $data WebSocket frame data
     * @return string Decoded message
     */
    protected function decodeWebSocketFrame($data) {
        $length = ord($data[1]) & 127;
        
        if ($length === 126) {
            $masks = substr($data, 4, 4);
            $data = substr($data, 8);
        } elseif ($length === 127) {
            $masks = substr($data, 10, 4);
            $data = substr($data, 14);
        } else {
            $masks = substr($data, 2, 4);
            $data = substr($data, 6);
        }
        
        $text = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        
        return $text;
    }
    
    /**
     * Encode message for WebSocket frame
     * 
     * @param string $message Message to encode
     * @return string Encoded WebSocket frame
     */
    protected function encodeWebSocketFrame($message) {
        $length = strlen($message);
        $frameHead = [];
        $frameHead[0] = 129; // FIN + text frame (0x81)
        
        if ($length <= 125) {
            $frameHead[1] = $length;
        } elseif ($length <= 65535) {
            $frameHead[1] = 126;
            $frameHead[2] = ($length >> 8) & 255;
            $frameHead[3] = $length & 255;
        } else {
            $frameHead[1] = 127;
            $frameHead[2] = ($length >> 56) & 255;
            $frameHead[3] = ($length >> 48) & 255;
            $frameHead[4] = ($length >> 40) & 255;
            $frameHead[5] = ($length >> 32) & 255;
            $frameHead[6] = ($length >> 24) & 255;
            $frameHead[7] = ($length >> 16) & 255;
            $frameHead[8] = ($length >> 8) & 255;
            $frameHead[9] = $length & 255;
        }
        
        $frame = '';
        foreach ($frameHead as $byte) {
            $frame .= chr($byte);
        }
        
        return $frame . $message;
    }
    
    /**
     * Send message to specific client
     * 
     * @param string $clientId Client ID
     * @param array $data Message data
     * @return bool Success status
     */
    protected function sendToClient($clientId, $data) {
        if (!isset($this->clients[$clientId])) {
            return false;
        }
        
        $socket = $this->clients[$clientId]['socket'];
        $message = json_encode($data);
        $encoded = $this->encodeWebSocketFrame($message);
        
        $sent = @socket_write($socket, $encoded, strlen($encoded));
        
        if ($sent === false) {
            $this->handleDisconnection($clientId);
            return false;
        }
        
        return true;
    }
    
    /**
     * Broadcast message to all clients
     * 
     * @param array $data Message data
     * @param array $exclude Client IDs to exclude
     */
    protected function broadcast($data, $exclude = []) {
        foreach ($this->clients as $clientId => $client) {
            if (in_array($clientId, $exclude)) {
                continue;
            }
            
            if ($client['authenticated']) {
                $this->sendToClient($clientId, $data);
            }
        }
    }
    
    /**
     * Check if client is authenticated
     * 
     * @param string $clientId Client ID
     * @return bool Authentication status
     */
    protected function isClientAuthenticated($clientId) {
        return isset($this->clients[$clientId]) && $this->clients[$clientId]['authenticated'];
    }
    
    /**
     * Generate unique client ID
     * 
     * @return string Client ID
     */
    protected function generateClientId() {
        return uniqid('client_');
    }
    
    /**
     * Get client ID by socket
     * 
     * @param resource $socket Client socket
     * @return string|false Client ID or false if not found
     */
    protected function getClientIdBySocket($socket) {
        foreach ($this->clients as $clientId => $client) {
            if ($client['socket'] === $socket) {
                return $clientId;
            }
        }
        
        return false;
    }
    
    /**
     * Log message to console/file
     * 
     * @param string $message Log message
     */
    protected function log($message) {
        $timestamp = date('[Y-m-d H:i:s]');
        echo "{$timestamp} {$message}" . PHP_EOL;
        
        if (LOG_ENABLED) {
            $logFile = LOG_FILE;
            $logDirectory = dirname($logFile);
            
            if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0755, true)) {
                return;
            }
            
            $logMessage = "{$timestamp} [WEBSOCKET] {$message}" . PHP_EOL;
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
}

// Create and run WebSocket server
$server = new WebSocketServer(WS_HOST, WS_PORT);
$server->run();
