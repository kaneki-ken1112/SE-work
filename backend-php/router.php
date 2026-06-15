<?php
/**
 * 图书馆预约系统 - PHP 后端
 *
 * 启动方式:
 *   set MYSQL_ROOT_PASSWORD=你的密码 && php -S localhost:8080 -t . backend-php/router.php
 */

// ==================== CORS 预检 ====================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ==================== 数据库连接 ====================
$DB = new PDO(
    'mysql:host=localhost;port=3306;dbname=library_booking;charset=utf8mb4',
    'root',
    getenv('MYSQL_ROOT_PASSWORD') ?: '',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

// ==================== 工具函数 ====================

/** 输出 JSON 并终止 */
function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json;charset=utf-8');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

/** 读取请求体 JSON */
function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ==================== 路由 ====================
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($uri === '/api/register'      && $method === 'POST') { handleRegister(); }
elseif ($uri === '/api/login'     && $method === 'POST') { handleLogin(); }
elseif ($uri === '/api/seats'     && $method === 'GET')  { handleSeats(); }
elseif ($uri === '/api/booking'   && $method === 'POST') { handleBooking(); }
elseif ($uri === '/api/cancel'    && $method === 'POST') { handleCancel(); }
elseif ($uri === '/api/updateBooking' && $method === 'POST') { handleUpdate(); }
elseif ($uri === '/api/bookings'  && $method === 'GET')  { handleBookings(); }
else { serveStatic(); }

// ==================== 1. 注册 ====================
function handleRegister() {
    global $DB;
    $d = body();

    $studentId = $d['studentId'] ?? '';
    $password  = $d['password']  ?? '';

    if ($studentId === '' || $password === '') {
        jsonOut(['success' => false, 'message' => '学号和密码不能为空'], 400);
    }

    try {
        $stmt = $DB->prepare("INSERT INTO user (user_id, password) VALUES (?, ?)");
        $stmt->execute([$studentId, $password]);
        jsonOut(['success' => true, 'message' => '注册成功']);
    } catch (PDOException $e) {
        $msg = strpos($e->getMessage(), 'Duplicate') !== false
            ? '该学号已被注册'
            : '数据库错误';
        jsonOut(['success' => false, 'message' => $msg]);
    }
}

// ==================== 2. 登录 ====================
function handleLogin() {
    global $DB;
    $d = body();

    $studentId = $d['studentId'] ?? '';
    $password  = $d['password']  ?? '';

    if ($studentId === '' || $password === '') {
        jsonOut(['success' => false, 'message' => '学号和密码不能为空'], 400);
    }

    $stmt = $DB->prepare("SELECT password FROM user WHERE user_id = ?");
    $stmt->execute([$studentId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonOut(['success' => false, 'message' => '当前用户不存在，请注册！']);
    }
    if ($user['password'] !== $password) {
        jsonOut(['success' => false, 'message' => '密码错误']);
    }

    jsonOut(['success' => true, 'message' => '登录成功，即将进入预约系统']);
}

// ==================== 3. 查询座位 ====================
function handleSeats() {
    global $DB;

    $floor     = $_GET['floor']     ?? '';
    $date      = $_GET['date']      ?? '';
    $timeSlots = $_GET['timeSlots'] ?? $_GET['timeSlot'] ?? '';

    if ($floor === '' || $date === '' || $timeSlots === '') {
        jsonOut([]);
    }

    $parts = array_values(array_filter(array_map('intval', explode(',', $timeSlots))));
    if (empty($parts)) {
        jsonOut([]);
    }

    $ph     = implode(',', array_fill(0, count($parts), '?'));
    $params = array_merge([$date], $parts, [intval($floor)]);

    $sql = "SELECT id, floor, seat_type, seat_no,
            EXISTS(
                SELECT 1 FROM booking b
                WHERE b.seat_id = s.id
                  AND b.booking_date = ?
                  AND b.time_slot IN ($ph)
            ) AS busy
            FROM seat s
            WHERE floor = ? AND is_active = 1
            ORDER BY seat_type, seat_no";

    $stmt = $DB->prepare($sql);
    $stmt->execute($params);

    $types = [
        1 => '研习位',
        2 => '大厅座位',
        3 => '单人研习间',
        4 => '四人研习舱',
        5 => '群组讨论室',
    ];

    $out = [];
    while ($row = $stmt->fetch()) {
        $typeName = $types[$row['seat_type']] ?? '座位';
        $out[] = [
            'seatId'    => (int) $row['id'],
            'floor'     => (int) $row['floor'],
            'seatType'  => (int) $row['seat_type'],
            'seatNo'    => (int) $row['seat_no'],
            'label'     => "{$row['floor']}楼{$typeName}{$row['seat_no']}",
            'available' => (int) !$row['busy'],
        ];
    }

    jsonOut($out);
}

// ==================== 4. 提交预约 ====================
function handleBooking() {
    global $DB;
    $d = body();

    $userId   = $d['userId']   ?? '';
    $userName = $d['userName'] ?? '';
    $phone    = $d['phone']    ?? '';
    $seatId   = $d['seatId']   ?? '';
    $date     = $d['date']     ?? '';
    $slots    = $d['timeSlots'] ?? $d['timeSlot'] ?? '';

    // 参数校验
    if ($userId === '' || $userName === '' || $phone === '' ||
        $seatId === '' || $date === '' || $slots === '') {
        jsonOut(['success' => false, 'message' => '请填写完整预约信息']);
    }
    if (!preg_match('/^\d{11}$/', $phone)) {
        jsonOut(['success' => false, 'message' => '电话号码格式不正确，需为11位数字']);
    }

    $parts = array_values(array_filter(array_map('intval', explode(',', $slots))));
    if (empty($parts)) {
        jsonOut(['success' => false, 'message' => '请选择时间段']);
    }

    $ph = implode(',', array_fill(0, count($parts), '?'));

    // 用户是否存在
    $stmt = $DB->prepare("SELECT 1 FROM user WHERE user_id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetch()) {
        jsonOut(['success' => false, 'message' => '用户不存在，请先登录或注册']);
    }

    // 同一用户同一时间段冲突
    $stmt = $DB->prepare("SELECT COUNT(*) FROM booking WHERE user_id = ? AND booking_date = ? AND time_slot IN ($ph)");
    $stmt->execute(array_merge([$userId, $date], $parts));
    if ($stmt->fetchColumn() > 0) {
        jsonOut(['success' => false, 'message' => '同一用户同一时间段只可预约一个座位']);
    }

    // 座位冲突
    $stmt = $DB->prepare("SELECT COUNT(*) FROM booking WHERE seat_id = ? AND booking_date = ? AND time_slot IN ($ph)");
    $stmt->execute(array_merge([intval($seatId), $date], $parts));
    if ($stmt->fetchColumn() > 0) {
        jsonOut(['success' => false, 'message' => '该座位在所选时间段内已有预约']);
    }

    // 批量插入
    $DB->beginTransaction();
    $stmt = $DB->prepare(
        "INSERT INTO booking (user_name, user_id, phone, seat_id, booking_date, time_slot)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($parts as $slot) {
        $stmt->execute([$userName, $userId, $phone, intval($seatId), $date, $slot]);
    }
    $DB->commit();

    jsonOut(['success' => true, 'message' => '预约成功']);
}

// ==================== 5. 取消预约 ====================
function handleCancel() {
    global $DB;
    $d = body();

    $bookingId = $d['bookingId'] ?? '';
    $userId    = $d['userId']    ?? '';

    if ($bookingId === '' || $userId === '') {
        jsonOut(['success' => false, 'message' => '参数不完整']);
    }

    // 检查所有权
    $stmt = $DB->prepare("SELECT user_id FROM booking WHERE id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        jsonOut(['success' => false, 'message' => '预约不存在或已被取消']);
    }
    if ($booking['user_id'] !== $userId) {
        jsonOut(['success' => false, 'message' => '无权限取消此预约']);
    }

    $DB->prepare("DELETE FROM booking WHERE id = ?")->execute([$bookingId]);
    jsonOut(['success' => true, 'message' => '取消成功']);
}

// ==================== 6. 修改预约 ====================
function handleUpdate() {
    global $DB;
    $d = body();

    $bookingId = intval($d['bookingId'] ?? 0);
    $userId    = $d['userId']   ?? '';
    $userName  = $d['userName'] ?? '';
    $phone     = $d['phone']    ?? '';
    $seatId    = intval($d['seatId'] ?? 0);
    $date      = $d['date']     ?? '';
    $timeSlot  = intval($d['timeSlot'] ?? 0);

    if (!$bookingId || $userId === '' || $userName === '' ||
        $phone === '' || !$seatId || $date === '' || !$timeSlot) {
        jsonOut(['success' => false, 'message' => '参数不完整']);
    }
    if (!preg_match('/^\d{11}$/', $phone)) {
        jsonOut(['success' => false, 'message' => '电话号码格式不正确，需为11位数字']);
    }

    // 所有权
    $stmt = $DB->prepare("SELECT user_id FROM booking WHERE id = ?");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonOut(['success' => false, 'message' => '预约不存在']);
    }
    if ($row['user_id'] !== $userId) {
        jsonOut(['success' => false, 'message' => '无权限修改此预约']);
    }

    // 用户冲突
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM booking
         WHERE user_id = ? AND booking_date = ? AND time_slot = ? AND id != ?"
    );
    $stmt->execute([$userId, $date, $timeSlot, $bookingId]);
    if ($stmt->fetchColumn() > 0) {
        jsonOut(['success' => false, 'message' => '同一用户在该时间段已有其他预约']);
    }

    // 座位冲突
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM booking
         WHERE seat_id = ? AND booking_date = ? AND time_slot = ? AND id != ?"
    );
    $stmt->execute([$seatId, $date, $timeSlot, $bookingId]);
    if ($stmt->fetchColumn() > 0) {
        jsonOut(['success' => false, 'message' => '该座位在所选时间段已被占用']);
    }

    // 更新
    $stmt = $DB->prepare(
        "UPDATE booking
         SET user_name = ?, phone = ?, seat_id = ?, booking_date = ?, time_slot = ?
         WHERE id = ?"
    );
    $stmt->execute([$userName, $phone, $seatId, $date, $timeSlot, $bookingId]);
    jsonOut(['success' => true, 'message' => '修改成功']);
}

// ==================== 7. 查询我的预约 ====================
function handleBookings() {
    global $DB;

    $userId = $_GET['userId'] ?? '';
    if ($userId === '') {
        jsonOut([]);
    }

    $sql = "SELECT b.id, b.user_name, b.phone, b.booking_date, b.time_slot,
                   s.floor, s.id AS seat_id
            FROM booking b
            JOIN seat s ON b.seat_id = s.id
            WHERE b.user_id = ?
            ORDER BY b.booking_date DESC, b.time_slot";

    $stmt = $DB->prepare($sql);
    $stmt->execute([$userId]);

    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = [
            'bookingId'   => (int) $row['id'],
            'userName'    => $row['user_name'],
            'phone'       => $row['phone'],
            'bookingDate' => $row['booking_date'],
            'timeSlot'    => (int) $row['time_slot'],
            'floor'       => (int) $row['floor'],
            'seatId'      => (int) $row['seat_id'],
        ];
    }

    jsonOut($out);
}

// ==================== 8. 静态文件服务 ====================
function serveStatic() {
    $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = realpath(__DIR__ . '/../frontend');

    if ($uri === '/') {
        $uri = '/login.html';
    }

    $file = realpath($base . $uri);

    // 安全检查
    if ($file === false || strpos($file, $base) !== 0 || !is_file($file)) {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }

    $mimes = [
        'html' => 'text/html',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'ico'  => 'image/x-icon',
    ];

    $ext  = pathinfo($file, PATHINFO_EXTENSION);
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    header("Content-Type: $mime;charset=utf-8");
    readfile($file);
    exit;
}
