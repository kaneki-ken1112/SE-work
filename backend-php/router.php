<?php
// 图书馆预约系统 - PHP 后端
// 启动: set MYSQL_ROOT_PASSWORD=xxx && php -S localhost:8080 -t . backend-php/router.php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$DB = new PDO(
    'mysql:host=localhost;port=3306;dbname=library_booking;charset=utf8mb4',
    'root', getenv('MYSQL_ROOT_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function jsonOut($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json;charset=utf-8');
    exit(json_encode($data, JSON_UNESCAPED_UNICODE));
}

function body() {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m   = $_SERVER['REQUEST_METHOD'];

// ── 路由 ──
if     ($uri === '/api/register'     && $m === 'POST') { reg();   }
elseif ($uri === '/api/login'        && $m === 'POST') { login(); }
elseif ($uri === '/api/seats'        && $m === 'GET')  { seats(); }
elseif ($uri === '/api/booking'      && $m === 'POST') { book();  }
elseif ($uri === '/api/cancel'       && $m === 'POST') { cancel();}
elseif ($uri === '/api/updateBooking'&& $m === 'POST') { update();}
elseif ($uri === '/api/bookings'     && $m === 'GET')  { mybook();}
else { serve(); }

// ── 注册 ──
function reg() {
    global $DB;
    $d = body();
    if (!$d['studentId'] || !$d['password']) jsonOut(['success'=>false,'message'=>'学号和密码不能为空'], 400);
    try {
        $DB->prepare("INSERT INTO user (user_id, password) VALUES (?,?)")->execute([$d['studentId'],$d['password']]);
        jsonOut(['success'=>true,'message'=>'注册成功']);
    } catch (PDOException $e) {
        jsonOut(['success'=>false,'message'=>strpos($e->getMessage(),'Duplicate')!==false?'该学号已被注册':'数据库错误']);
    }
}

// ── 登录 ──
function login() {
    global $DB;
    $d = body();
    if (!$d['studentId'] || !$d['password']) jsonOut(['success'=>false,'message'=>'学号和密码不能为空'], 400);
    $st = $DB->prepare("SELECT password FROM user WHERE user_id = ?");
    $st->execute([$d['studentId']]);
    $u = $st->fetch();
    if (!$u)                       jsonOut(['success'=>false,'message'=>'当前用户不存在，请注册！']);
    if ($u['password'] !== $d['password']) jsonOut(['success'=>false,'message'=>'密码错误']);
    jsonOut(['success'=>true,'message'=>'登录成功，即将进入预约系统']);
}

// ── 座位查询 ──
function seats() {
    global $DB;
    $f = $_GET['floor'] ?? ''; $d = $_GET['date'] ?? ''; $ts = $_GET['timeSlots'] ?? $_GET['timeSlot'] ?? '';
    if (!$f || !$d || !$ts) jsonOut([]);
    $parts = array_values(array_filter(array_map('intval', explode(',', $ts))));
    if (!$parts) jsonOut([]);
    $ph = implode(',', array_fill(0, count($parts), '?'));
    $params = array_merge([$d], $parts, [intval($f)]);
    $st = $DB->prepare("SELECT id, floor, seat_type, seat_no,
        EXISTS(SELECT 1 FROM booking b WHERE b.seat_id=s.id AND b.booking_date=? AND b.time_slot IN ($ph)) AS busy
        FROM seat s WHERE floor=? AND is_active=1 ORDER BY seat_type, seat_no");
    $st->execute($params);
    $types = [1=>'研习位',2=>'大厅座位',3=>'单人研习间',4=>'四人研习舱',5=>'群组讨论室'];
    $out = [];
    while ($r = $st->fetch()) {
        $tn = $types[$r['seat_type']] ?? '座位';
        $out[] = ['seatId'=>(int)$r['id'],'floor'=>(int)$r['floor'],'seatType'=>(int)$r['seat_type'],
            'seatNo'=>(int)$r['seat_no'],'label'=>"{$r['floor']}楼{$tn}{$r['seat_no']}",
            'available'=>(int)!$r['busy']];
    }
    jsonOut($out);
}

// ── 提交预约 ──
function book() {
    global $DB;
    $d = body();
    $uid=$d['userId']??''; $uName=$d['userName']??''; $ph=$d['phone']??''; $sid=$d['seatId']??'';
    $date=$d['date']??''; $ts=$d['timeSlots']??$d['timeSlot']??'';
    if (!$uid||!$uName||!$ph||!$sid||!$date||!$ts) jsonOut(['success'=>false,'message'=>'请填写完整预约信息']);
    if (!preg_match('/^\d{11}$/',$ph)) jsonOut(['success'=>false,'message'=>'电话号码格式不正确，需为11位数字']);
    $parts = array_values(array_filter(array_map('intval', explode(',', $ts))));
    if (!$parts) jsonOut(['success'=>false,'message'=>'请选择时间段']);
    $ph2 = implode(',', array_fill(0, count($parts), '?'));

    // 用户存在?
    $st=$DB->prepare("SELECT 1 FROM user WHERE user_id=?"); $st->execute([$uid]);
    if (!$st->fetch()) jsonOut(['success'=>false,'message'=>'用户不存在，请先登录或注册']);

    // 用户冲突
    $st=$DB->prepare("SELECT COUNT(*) FROM booking WHERE user_id=? AND booking_date=? AND time_slot IN ($ph2)");
    $st->execute(array_merge([$uid,$date],$parts));
    if ($st->fetchColumn()>0) jsonOut(['success'=>false,'message'=>'同一用户同一时间段只可预约一个座位']);

    // 座位冲突
    $st=$DB->prepare("SELECT COUNT(*) FROM booking WHERE seat_id=? AND booking_date=? AND time_slot IN ($ph2)");
    $st->execute(array_merge([intval($sid),$date],$parts));
    if ($st->fetchColumn()>0) jsonOut(['success'=>false,'message'=>'该座位在所选时间段内已有预约']);

    $DB->beginTransaction();
    $st=$DB->prepare("INSERT INTO booking (user_name,user_id,phone,seat_id,booking_date,time_slot) VALUES (?,?,?,?,?,?)");
    foreach ($parts as $s) $st->execute([$uName,$uid,$ph,intval($sid),$date,$s]);
    $DB->commit();
    jsonOut(['success'=>true,'message'=>'预约成功']);
}

// ── 取消预约 ──
function cancel() {
    global $DB;
    $d = body(); $bid=$d['bookingId']??''; $uid=$d['userId']??'';
    if (!$bid||!$uid) jsonOut(['success'=>false,'message'=>'参数不完整']);
    $st=$DB->prepare("SELECT user_id FROM booking WHERE id=?"); $st->execute([$bid]);
    if (!($r=$st->fetch())) jsonOut(['success'=>false,'message'=>'预约不存在或已被取消']);
    if ($r['user_id']!==$uid) jsonOut(['success'=>false,'message'=>'无权限取消此预约']);
    $DB->prepare("DELETE FROM booking WHERE id=?")->execute([$bid]);
    jsonOut(['success'=>true,'message'=>'取消成功']);
}

// ── 修改预约 ──
function update() {
    global $DB;
    $d=body(); $bid=intval($d['bookingId']??0); $uid=$d['userId']??''; $un=$d['userName']??'';
    $ph=$d['phone']??''; $sid=intval($d['seatId']??0); $date=$d['date']??''; $slot=intval($d['timeSlot']??0);
    if (!$bid||!$uid||!$un||!$ph||!$sid||!$date||!$slot) jsonOut(['success'=>false,'message'=>'参数不完整']);
    if (!preg_match('/^\d{11}$/',$ph)) jsonOut(['success'=>false,'message'=>'电话号码格式不正确，需为11位数字']);

    $st=$DB->prepare("SELECT user_id FROM booking WHERE id=?"); $st->execute([$bid]);
    if (!($r=$st->fetch())) jsonOut(['success'=>false,'message'=>'预约不存在']);
    if ($r['user_id']!==$uid) jsonOut(['success'=>false,'message'=>'无权限修改此预约']);

    $st=$DB->prepare("SELECT COUNT(*) FROM booking WHERE user_id=? AND booking_date=? AND time_slot=? AND id!=?");
    $st->execute([$uid,$date,$slot,$bid]);
    if ($st->fetchColumn()>0) jsonOut(['success'=>false,'message'=>'同一用户在该时间段已有其他预约']);

    $st=$DB->prepare("SELECT COUNT(*) FROM booking WHERE seat_id=? AND booking_date=? AND time_slot=? AND id!=?");
    $st->execute([$sid,$date,$slot,$bid]);
    if ($st->fetchColumn()>0) jsonOut(['success'=>false,'message'=>'该座位在所选时间段已被占用']);

    $DB->prepare("UPDATE booking SET user_name=?,phone=?,seat_id=?,booking_date=?,time_slot=? WHERE id=?")
       ->execute([$un,$ph,$sid,$date,$slot,$bid]);
    jsonOut(['success'=>true,'message'=>'修改成功']);
}

// ── 我的预约 ──
function mybook() {
    global $DB;
    $uid = $_GET['userId'] ?? '';
    if (!$uid) jsonOut([]);
    $st = $DB->prepare("SELECT b.id,b.user_name,b.phone,b.booking_date,b.time_slot,s.floor,s.id AS seat_id
        FROM booking b JOIN seat s ON b.seat_id=s.id WHERE b.user_id=? ORDER BY b.booking_date DESC, b.time_slot");
    $st->execute([$uid]);
    $out = [];
    while ($r = $st->fetch()) {
        $out[] = ['bookingId'=>(int)$r['id'],'userName'=>$r['user_name'],'phone'=>$r['phone'],
            'bookingDate'=>$r['booking_date'],'timeSlot'=>(int)$r['time_slot'],
            'floor'=>(int)$r['floor'],'seatId'=>(int)$r['seat_id']];
    }
    jsonOut($out);
}

// ── 静态文件 ──
function serve() {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = realpath(__DIR__ . '/../frontend');
    if ($uri === '/') $uri = '/login.html';
    $fp = realpath($base . $uri);
    if (!$fp || strpos($fp, $base) !== 0 || !is_file($fp)) { http_response_code(404); echo '404'; exit; }
    $mimes = ['html'=>'text/html','css'=>'text/css','js'=>'application/javascript',
        'json'=>'application/json','png'=>'image/png','jpg'=>'image/jpeg','ico'=>'image/x-icon'];
    $ext = pathinfo($fp, PATHINFO_EXTENSION);
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream') . ';charset=utf-8');
    readfile($fp);
    exit;
}
