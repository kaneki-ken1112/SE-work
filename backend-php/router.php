<?php
/**
 * 图书馆预约系统 - PHP 后端
 * 启动方式: php -S localhost:8080 backend-php/router.php
 */

// ========== 数据库配置 ==========
$DB_HOST = 'localhost';
$DB_PORT = '3306';
$DB_NAME = 'library_booking';
$DB_USER = 'root';
$DB_PASS = getenv('MYSQL_ROOT_PASSWORD') ?: '';

// ========== PDO 连接 ==========
function getDB(): PDO {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS;
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4";
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// ========== CORS 与响应 ==========
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json;charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonBody(): array {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

// ========== 路由 ==========
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// API 路由
if ($uri === '/api/register' && $method === 'POST')       { handleRegister(); }
elseif ($uri === '/api/login' && $method === 'POST')      { handleLogin(); }
elseif ($uri === '/api/seats' && $method === 'GET')       { handleSeats(); }
elseif ($uri === '/api/booking' && $method === 'POST')    { handleBooking(); }
elseif ($uri === '/api/cancel' && $method === 'POST')     { handleCancel(); }
elseif ($uri === '/api/updateBooking' && $method === 'POST') { handleUpdate(); }
elseif ($uri === '/api/bookings' && $method === 'GET')    { handleBookings(); }
else                                                       { serveStatic(); }

// ========== 1. 注册 ==========
function handleRegister() {
    $data = getJsonBody();
    $studentId = $data['studentId'] ?? '';
    $password  = $data['password'] ?? '';

    if ($studentId === '' || $password === '') {
        jsonResponse(['success' => false, 'message' => '学号和密码不能为空'], 400);
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO user (user_id, password) VALUES (?, ?)");
        $stmt->execute([$studentId, $password]);
        jsonResponse(['success' => true, 'message' => '注册成功']);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            jsonResponse(['success' => false, 'message' => '该学号已被注册']);
        }
        jsonResponse(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
    }
}

// ========== 2. 登录 ==========
function handleLogin() {
    $data = getJsonBody();
    $studentId = $data['studentId'] ?? '';
    $password  = $data['password'] ?? '';

    if ($studentId === '' || $password === '') {
        jsonResponse(['success' => false, 'message' => '学号和密码不能为空'], 400);
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT password FROM user WHERE user_id = ?");
        $stmt->execute([$studentId]);
        $user = $stmt->fetch();

        if (!$user) {
            jsonResponse(['success' => false, 'message' => '当前用户不存在，请注册！']);
        }
        if ($user['password'] === $password) {
            jsonResponse(['success' => true, 'message' => '登录成功，即将进入预约系统']);
        } else {
            jsonResponse(['success' => false, 'message' => '密码错误']);
        }
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
    }
}

// ========== 3. 查询座位 ==========
function handleSeats() {
    $floor  = $_GET['floor'] ?? '';
    $date   = $_GET['date'] ?? '';
    $slots  = $_GET['timeSlots'] ?? $_GET['timeSlot'] ?? '';

    if ($floor === '' || $date === '' || $slots === '') {
        jsonResponse([]);
    }

    $parts = array_filter(array_map('trim', explode(',', $slots)));
    if (empty($parts)) {
        jsonResponse([]);
    }

    $floorInt = intval($floor);
    $placeholders = implode(',', array_fill(0, count($parts), '?'));

    $sql = "SELECT s.id, s.floor, s.seat_type, s.seat_no,
            CASE WHEN EXISTS(
                SELECT 1 FROM booking b
                WHERE b.seat_id = s.id AND b.booking_date = ? AND b.time_slot IN ($placeholders)
            ) THEN 0 ELSE 1 END AS available
            FROM seat s
            WHERE s.floor = ? AND s.is_active = 1
            ORDER BY s.seat_type, s.seat_no";

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare($sql);
        $params = array_merge([$date], array_map('intval', $parts), [$floorInt]);
        $stmt->execute($params);

        $seats = [];
        while ($row = $stmt->fetch()) {
            $typeNames = [1 => '研习位', 2 => '大厅座位', 3 => '单人研习间', 4 => '四人研习舱', 5 => '群组讨论室'];
            $type = intval($row['seat_type']);
            $typeName = $typeNames[$type] ?? '座位';
            $seatNo = intval($row['seat_no']);

            $label = "{$row['floor']}楼{$typeName}{$seatNo}";
            if ($seatNo === 0) $label = "{$row['floor']}楼座{$row['id']}";

            $seats[] = [
                'seatId'    => intval($row['id']),
                'floor'     => intval($row['floor']),
                'seatType'  => $type,
                'seatNo'    => $seatNo,
                'label'     => $label,
                'available' => intval($row['available']),
            ];
        }
        jsonResponse($seats);
    } catch (PDOException $e) {
        jsonResponse([]);
    }
}

// ========== 4. 提交预约 ==========
function handleBooking() {
    $data = getJsonBody();
    $userName = $data['userName'] ?? '';
    $userId   = $data['userId'] ?? '';
    $phone    = $data['phone'] ?? '';
    $seatId   = $data['seatId'] ?? '';
    $date     = $data['date'] ?? '';
    $slots    = $data['timeSlots'] ?? $data['timeSlot'] ?? '';

    if ($userName === '' || $userId === '' || $phone === '' || $seatId === '' || $date === '' || $slots === '') {
        jsonResponse(['success' => false, 'message' => '请填写完整预约信息']);
    }

    if (!preg_match('/^\d{11}$/', $phone)) {
        jsonResponse(['success' => false, 'message' => '电话号码格式不正确，需为11位数字']);
    }

    $seat = intval($seatId);
    $parts = array_filter(array_map('trim', explode(',', $slots)));
    $slotInts = array_map('intval', $parts);
    if (empty($slotInts)) {
        jsonResponse(['success' => false, 'message' => '请选择时间段']);
    }

    try {
        $pdo = getDB();

        // 检查用户是否存在
        $stmt = $pdo->prepare("SELECT 1 FROM user WHERE user_id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => '用户不存在，请先登录或注册']);
        }

        // 检查用户冲突
        $ph = implode(',', array_fill(0, count($slotInts), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking WHERE user_id = ? AND booking_date = ? AND time_slot IN ($ph)");
        $stmt->execute(array_merge([$userId, $date], $slotInts));
        if ($stmt->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => '同一用户同一时间段只可预约一个座位']);
        }

        // 检查座位冲突
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking WHERE seat_id = ? AND booking_date = ? AND time_slot IN ($ph)");
        $stmt->execute(array_merge([$seat, $date], $slotInts));
        if ($stmt->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => '该座位在所选时间段内已有预约']);
        }

        // 插入预约
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO booking (user_name, user_id, phone, seat_id, booking_date, time_slot) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($slotInts as $slot) {
            $stmt->execute([$userName, $userId, $phone, $seat, $date, $slot]);
        }
        $pdo->commit();

        jsonResponse(['success' => true, 'message' => '预约成功']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
    }
}

// ========== 5. 取消预约 ==========
function handleCancel() {
    $data = getJsonBody();
    $bookingId = $data['bookingId'] ?? '';
    $userId    = $data['userId'] ?? '';

    if ($bookingId === '' || $userId === '') {
        jsonResponse(['success' => false, 'message' => '参数不完整']);
    }

    try {
        $pdo = getDB();

        // 检查所有权
        $stmt = $pdo->prepare("SELECT user_id FROM booking WHERE id = ?");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            jsonResponse(['success' => false, 'message' => '预约不存在或已被取消']);
        }
        if ($booking['user_id'] !== $userId) {
            jsonResponse(['success' => false, 'message' => '无权限取消此预约']);
        }

        // 删除
        $stmt = $pdo->prepare("DELETE FROM booking WHERE id = ?");
        $stmt->execute([$bookingId]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => '取消失败']);
        }

        jsonResponse(['success' => true, 'message' => '取消成功']);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
    }
}

// ========== 6. 修改预约 ==========
function handleUpdate() {
    $data = getJsonBody();
    $bookingId = $data['bookingId'] ?? '';
    $userId    = $data['userId'] ?? '';
    $userName  = $data['userName'] ?? '';
    $phone     = $data['phone'] ?? '';
    $seatId    = $data['seatId'] ?? '';
    $date      = $data['date'] ?? '';
    $timeSlot  = $data['timeSlot'] ?? '';

    if ($bookingId === '' || $userId === '' || $userName === '' || $phone === ''
        || $seatId === '' || $date === '' || $timeSlot === '') {
        jsonResponse(['success' => false, 'message' => '参数不完整']);
    }

    if (!preg_match('/^\d{11}$/', $phone)) {
        jsonResponse(['success' => false, 'message' => '电话号码格式不正确，需为11位数字']);
    }

    $bookingId = intval($bookingId);
    $seat      = intval($seatId);
    $slot      = intval($timeSlot);

    try {
        $pdo = getDB();

        // 检查所有权
        $stmt = $pdo->prepare("SELECT user_id FROM booking WHERE id = ?");
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'message' => '预约不存在']);
        }
        if ($row['user_id'] !== $userId) {
            jsonResponse(['success' => false, 'message' => '无权限修改此预约']);
        }

        // 用户冲突
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking WHERE user_id = ? AND booking_date = ? AND time_slot = ? AND id != ?");
        $stmt->execute([$userId, $date, $slot, $bookingId]);
        if ($stmt->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => '同一用户在该时间段已有其他预约']);
        }

        // 座位冲突
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking WHERE seat_id = ? AND booking_date = ? AND time_slot = ? AND id != ?");
        $stmt->execute([$seat, $date, $slot, $bookingId]);
        if ($stmt->fetchColumn() > 0) {
            jsonResponse(['success' => false, 'message' => '该座位在所选时间段已被占用']);
        }

        // 更新
        $stmt = $pdo->prepare("UPDATE booking SET user_name = ?, phone = ?, seat_id = ?, booking_date = ?, time_slot = ? WHERE id = ?");
        $stmt->execute([$userName, $phone, $seat, $date, $slot, $bookingId]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => '修改失败']);
        }

        jsonResponse(['success' => true, 'message' => '修改成功']);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
    }
}

// ========== 7. 查询预约记录 ==========
function handleBookings() {
    $userId = $_GET['userId'] ?? '';

    if ($userId === '') {
        jsonResponse([]);
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT b.id, b.user_name, b.phone, b.booking_date, b.time_slot, s.floor, s.id AS seat_id
             FROM booking b
             JOIN seat s ON b.seat_id = s.id
             WHERE b.user_id = ?
             ORDER BY b.booking_date DESC, b.time_slot"
        );
        $stmt->execute([$userId]);

        $bookings = [];
        while ($row = $stmt->fetch()) {
            $bookings[] = [
                'bookingId'   => intval($row['id']),
                'userName'    => $row['user_name'],
                'phone'       => $row['phone'],
                'bookingDate' => $row['booking_date'],
                'timeSlot'    => intval($row['time_slot']),
                'floor'       => intval($row['floor']),
                'seatId'      => intval($row['seat_id']),
            ];
        }
        jsonResponse($bookings);
    } catch (PDOException $e) {
        jsonResponse([]);
    }
}

// ========== 8. 静态文件服务 ==========
function serveStatic() {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = realpath(__DIR__ . '/../frontend');

    // 默认页面
    if ($uri === '/' || $uri === '/index.html') {
        $uri = '/login.html';
    }

    $filePath = realpath($base . $uri);

    // 安全检查：防止目录遍历
    if ($filePath === false || strpos($filePath, $base) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    // MIME 类型
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimes = [
        'html' => 'text/html;charset=utf-8',
        'css'  => 'text/css;charset=utf-8',
        'js'   => 'application/javascript;charset=utf-8',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'ico'  => 'image/x-icon',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    header("Content-Type: $mime");
    readfile($filePath);
    exit;
}

// ===== 启动提示 =====
// 仅在命令行直接运行时显示（非 HTTP 请求）
if (php_sapi_name() === 'cli-server') {
    // PHP built-in server mode - silent
}
