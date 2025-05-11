<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
 
// 调试模式
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(10); // 设置超时时间 
 
try {
    // 验证请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("只支持POST请求", 405);
    }
 
    // 获取输入数据
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON解析失败: ".json_last_error_msg(), 400);
    }
 
    // 验证服务器列表 
    $servers = is_array($input['servers'] ?? null) ? 
               array_filter(array_map('trim', $input['servers'])) : 
               [];
    
    if (empty($servers)) {
        throw new Exception("必须提供至少一个服务器地址", 400);
    }
 
    // 执行检测
    $results = [];
    foreach ($servers as $server) {
        $results[] = pingMinecraftServer($server);
    }
 
    echo json_encode([
        'status' => 'success',
        'data' => $results,
        'count' => count($results),
        'timestamp' => time()
    ]);
 
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'timestamp' => time()
    ]);
}
 
function pingMinecraftServer($address) {
    $start = microtime(true);
    list($host, $port) = parseAddress($address);
    
    try {
        // 建立TCP连接 
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$socket) {
            throw new Exception("连接失败: $errstr ($errno)");
        }
        
        stream_set_timeout($socket, 2);
        
        // 发送握手包
        $handshake = buildHandshake($host, $port);
        if (@fwrite($socket, $handshake) === false) {
            throw new Exception("握手包发送失败");
        }
        
        // 发送状态请求 
        if (@fwrite($socket, "\x01\x00") === false) {
            throw new Exception("状态请求发送失败");
        }
        
        // 读取响应长度 
        $length = readVarIntFromSocket($socket);
        if ($length <= 0) {
            throw new Exception("无效的响应长度");
        }
        
        // 读取完整响应 
        $response = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === false) {
                throw new Exception("读取响应数据失败");
            }
            $response .= $chunk;
            $remaining -= strlen($chunk);
        }
        
        // 解析响应 
        $data = parseResponse($response);
        
        return [
            'server' => "$host:$port",
            'online' => true,
            'ping' => round((microtime(true) - $start) * 1000),
            'version' => $data['version']['name'] ?? null,
            'players' => [
                'online' => $data['players']['online'] ?? 0,
                'max' => $data['players']['max'] ?? 0,
                'list' => array_column($data['players']['sample'] ?? [], 'name') ?? []
            ],
            'motd' => parseMotd($data['description'] ?? '')
        ];
        
    } catch (Exception $e) {
        return [
            'server' => "$host:$port",
            'online' => false,
            'error' => $e->getMessage(),
            'ping' => round((microtime(true) - $start) * 1000)
        ];
    } finally {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
    }
}
 
// 从Socket读取VarInt (修复关键错误)
function readVarIntFromSocket($socket) {
    $value = 0;
    $shift = 0;
    $maxBytes = 5; // VarInt最多5字节 
    
    do {
        $byte = @fread($socket, 1);
        if ($byte === false || strlen($byte) < 1) {
            throw new Exception("读取VarInt失败");
        }
        
        $byte = ord($byte);
        $value |= ($byte & 0x7F) << $shift;
        $shift += 7;
        
        if ($shift >= 32) {
            throw new Exception("VarInt值过大");
        }
        
        if (--$maxBytes <= 0) {
            throw new Exception("VarInt数据过长");
        }
    } while ($byte & 0x80);
    
    return $value;
}
 
// 其他辅助函数保持不变
function parseAddress($address) {
    $parts = explode(':', $address);
    return [$parts[0], $parts[1] ?? 25565];
}
 
function buildHandshake($host, $port) {
    $packet = "\x00"; // 包ID
    $packet .= writeVarInt(4); // 协议版本
    $packet .= writeString($host);
    $packet .= pack('n', $port);
    $packet .= writeVarInt(1); // 下一步状态
    
    return writeVarInt(strlen($packet)) . $packet;
}
 
function parseResponse($response) {
    $offset = 0;
    $packetId = ord($response[$offset++]);
    
    if ($packetId !== 0x00) {
        throw new Exception("无效的响应包ID");
    }
    
    $jsonLength = readVarInt($response, $offset);
    $json = substr($response, $offset, $jsonLength);
    
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON解析失败: " . json_last_error_msg());
    }
    
    return $data;
}
 
function parseMotd($description) {
    if (is_array($description)) {
        return $description['text'] ?? implode('', array_column($description['extra'] ?? [], 'text'));
    }
    return (string)$description;
}
 
function writeVarInt($value) {
    $buf = '';
    do {
        $byte = $value & 0x7F;
        $value >>= 7;
        if ($value !== 0) $byte |= 0x80;
        $buf .= chr($byte);
    } while ($value !== 0);
    return $buf;
}
 
function readVarInt(&$data, &$offset = 0) {
    $value = 0;
    $shift = 0;
    
    do {
        if (!isset($data[$offset])) {
            throw new Exception("VarInt数据不完整");
        }
        $byte = ord($data[$offset++]);
        $value |= ($byte & 0x7F) << $shift;
        $shift += 7;
    } while ($byte & 0x80);
    
    return $value;
}
 
function writeString($str) {
    return writeVarInt(strlen($str)) . $str;
}
