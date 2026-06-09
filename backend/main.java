import com.sun.net.httpserver.HttpServer;
import com.sun.net.httpserver.HttpHandler;
import com.sun.net.httpserver.HttpExchange;
import java.io.*;
import java.net.InetSocketAddress;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.sql.*;
import java.util.Arrays;
import java.util.HashMap;
import java.util.Map;
import java.util.Scanner;
import java.util.stream.Collectors;

@SuppressWarnings("restriction")
public class main {

    // ---- 数据库配置（密码由用户启动时输入） ----
    private static final String DB_URL  = "jdbc:mysql://localhost:3306/library_booking";
    private static final String DB_USER = "root";
    private static String DB_PASS = null; // 启动时设置

    // ---- 服务器端口 ----
    private static final int PORT = 8080;

    // ---- 数据库连接 ----
    private static Connection getConnection() throws SQLException {
        return DriverManager.getConnection(DB_URL, DB_USER, DB_PASS);
    }

    // ---- 简单 JSON 解析（不依赖第三方库） ----
    private static String jsonValue(String json, String key) {
        String search = "\"" + key + "\"";
        int start = json.indexOf(search);
        if (start == -1) return "";
        start = json.indexOf(":", start) + 1;
        while (start < json.length() && (json.charAt(start) == ' ' || json.charAt(start) == '"')) start++;
        int end = json.indexOf("\"", start);
        if (end == -1) end = json.indexOf("}", start);
        if (end == -1) return "";
        return json.substring(start, end);
    }

    // ---- 注册用户到数据库 ----
    private static String doRegister(String studentId, String password) {
        String sql = "INSERT INTO user (user_id, password) VALUES (?, ?)";
        try (Connection conn = getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, studentId);
            ps.setString(2, password);
            ps.executeUpdate();
            return "{\"success\":true,\"message\":\"注册成功\"}";
        } catch (SQLException e) {
            if (e.getMessage().contains("Duplicate")) {
                return "{\"success\":false,\"message\":\"该学号已被注册\"}";
            }
            return "{\"success\":false,\"message\":\"数据库错误: " + e.getMessage() + "\"}";
        }
    }

    // ---- 登录验证 ----
    private static String doLogin(String studentId, String password) {
        String sql = "SELECT password FROM user WHERE user_id = ?";
        try (Connection conn = getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, studentId);
            try (ResultSet rs = ps.executeQuery()) {
                if (rs.next()) {
                    // 用户存在，校验密码
                    String dbPass = rs.getString("password");
                    if (dbPass.equals(password)) {
                        return "{\"success\":true,\"message\":\"登录成功，即将进入预约系统\"}";
                    } else {
                        return "{\"success\":false,\"message\":\"密码错误\"}";
                    }
                } else {
                    // 用户不存在
                    return "{\"success\":false,\"message\":\"当前用户不存在，请注册！\"}";
                }
            }
        } catch (SQLException e) {
            return "{\"success\":false,\"message\":\"数据库错误: " + e.getMessage() + "\"}";
        }
    }

    // ---- HTTP 请求处理器 ----
    static class RegisterHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            // ① 处理 OPTIONS 预检请求（CORS）
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }

            // ② 只处理 POST
            if (!"POST".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持POST\"}");
                return;
            }

            // ② 读取请求体 JSON
            String body = new BufferedReader(
                new InputStreamReader(exchange.getRequestBody(), StandardCharsets.UTF_8))
                .lines().collect(Collectors.joining("\n"));

            // ③ 解析学号和密码
            String studentId = jsonValue(body, "studentId");
            String password  = jsonValue(body, "password");

            if (studentId.isEmpty() || password.isEmpty()) {
                sendResponse(exchange, 400, "{\"success\":false,\"message\":\"学号和密码不能为空\"}");
                return;
            }

            // ④ 执行注册
            String result = doRegister(studentId, password);
            sendResponse(exchange, 200, result);
        }
    }

    // ---- 发送 JSON 响应 + CORS ----
    private static void sendResponse(HttpExchange exchange, int code, String body, String contentType) throws IOException {
        exchange.getResponseHeaders().set("Content-Type", contentType);
        exchange.getResponseHeaders().set("Access-Control-Allow-Origin", "*");
        exchange.getResponseHeaders().set("Access-Control-Allow-Methods", "GET, POST, OPTIONS");
        exchange.getResponseHeaders().set("Access-Control-Allow-Headers", "Content-Type");
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        exchange.sendResponseHeaders(code, bytes.length);
        try (OutputStream os = exchange.getResponseBody()) {
            os.write(bytes);
        }
    }

    private static void sendResponse(HttpExchange exchange, int code, String json) throws IOException {
        sendResponse(exchange, code, json, "application/json;charset=utf-8");
    }

    private static Map<String, String> parseQuery(String query) {
        Map<String, String> params = new HashMap<>();
        if (query == null || query.isEmpty()) return params;
        for (String part : query.split("&")) {
            int idx = part.indexOf('=');
            if (idx == -1) continue;
            String key = part.substring(0, idx);
            String value = part.substring(idx + 1);
            try {
                value = URLDecoder.decode(value, StandardCharsets.UTF_8.name());
            } catch (UnsupportedEncodingException e) {
                // UTF-8 should always be supported
            }
            params.put(key, value);
        }
        return params;
    }

    private static void ensureSeatSchema() throws SQLException {
        try (Connection conn = getConnection();
             PreparedStatement ps = conn.prepareStatement(
                 "SELECT COLUMN_NAME FROM information_schema.columns "
                 + "WHERE table_schema = DATABASE() AND table_name = 'seat'")) {
            try (ResultSet rs = ps.executeQuery()) {
                boolean hasType = false;
                boolean hasNo = false;
                while (rs.next()) {
                    String column = rs.getString("COLUMN_NAME");
                    if ("seat_type".equals(column)) hasType = true;
                    if ("seat_no".equals(column)) hasNo = true;
                }
                try (Statement stmt = conn.createStatement()) {
                    if (!hasType) {
                        stmt.execute("ALTER TABLE seat ADD COLUMN seat_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '座位类型(1=单人研习位,2=大厅座位)' AFTER is_active");
                    }
                    if (!hasNo) {
                        stmt.execute("ALTER TABLE seat ADD COLUMN seat_no INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '类型内编号' AFTER seat_type");
                    }
                }
            }
        }
    }

    private static void initializeSeatsIfEmpty() throws SQLException {
        try (Connection conn = getConnection();
             Statement stmt = conn.createStatement()) {
            ResultSet rs = stmt.executeQuery("SELECT COUNT(*) FROM seat");
            int seatCount = 0;
            if (rs.next()) {
                seatCount = rs.getInt(1);
            }
            rs.close();

            if (seatCount == 608) {
                return;
            }

            ResultSet bookingRs = stmt.executeQuery("SELECT COUNT(*) FROM booking");
            int bookingCount = 0;
            if (bookingRs.next()) {
                bookingCount = bookingRs.getInt(1);
            }
            bookingRs.close();

            if (seatCount == 0 || bookingCount == 0) {
                stmt.executeUpdate("DELETE FROM seat");
                String sql = "INSERT INTO seat (floor, is_active, seat_type, seat_no) VALUES (?, 1, ?, ?)";
                try (PreparedStatement ps = conn.prepareStatement(sql)) {
                    for (int floor = 1; floor <= 3; floor++) {
                        for (int number = 1; number <= 100; number++) {
                            ps.setInt(1, floor);
                            ps.setInt(2, 1);
                            ps.setInt(3, number);
                            ps.addBatch();
                        }
                        for (int number = 1; number <= 100; number++) {
                            ps.setInt(1, floor);
                            ps.setInt(2, 2);
                            ps.setInt(3, number);
                            ps.addBatch();
                        }
                    }
                    ps.executeBatch();
                }
                if (seatCount != 0) {
                    System.out.println("旧座位数据已清空，已重新生成 600 个座位记录。");
                }
            } else {
                System.out.println("seat 表记录数量不是 600，但存在预约记录，保留原有座位数据。请手动迁移。\n当前座位数=" + seatCount + "，预约数=" + bookingCount);
            }
        }
    }

    // Ensure special rooms exist on 3rd floor: 4 single rooms (seat_type=3),
    // 2 four-person pods (seat_type=4), 2 group rooms (seat_type=5).
    private static void ensureSpecialRooms() throws SQLException {
        try (Connection conn = getConnection();
             Statement stmt = conn.createStatement()) {
            // check existing counts
            int needSingle = 4, needPod = 2, needGroup = 2;
            int existSingle = 0, existPod = 0, existGroup = 0;
            try (ResultSet rs = stmt.executeQuery("SELECT seat_type, COUNT(*) AS c FROM seat WHERE floor = 3 GROUP BY seat_type")) {
                while (rs.next()) {
                    int t = rs.getInt("seat_type");
                    int c = rs.getInt("c");
                    if (t == 3) existSingle = c;
                    else if (t == 4) existPod = c;
                    else if (t == 5) existGroup = c;
                }
            }

            String insertSql = "INSERT INTO seat (floor, is_active, seat_type, seat_no) VALUES (?, 1, ?, ?)";
            try (PreparedStatement ps = conn.prepareStatement(insertSql)) {
                // insert missing single rooms (type 3)
                for (int i = existSingle + 1; i <= needSingle; i++) {
                    ps.setInt(1, 3);
                    ps.setInt(2, 3);
                    ps.setInt(3, i);
                    ps.addBatch();
                }
                // insert missing pods (type 4)
                for (int i = existPod + 1; i <= needPod; i++) {
                    ps.setInt(1, 3);
                    ps.setInt(2, 4);
                    ps.setInt(3, i);
                    ps.addBatch();
                }
                // insert missing group rooms (type 5)
                for (int i = existGroup + 1; i <= needGroup; i++) {
                    ps.setInt(1, 3);
                    ps.setInt(2, 5);
                    ps.setInt(3, i);
                    ps.addBatch();
                }
                ps.executeBatch();
            }
        }
    }

    private static String doGetSeats(String floor, String bookingDate, String timeSlots) {
        if (floor.isEmpty() || bookingDate.isEmpty() || timeSlots.isEmpty()) {
            return "[]";
        }
        String[] parts = Arrays.stream(timeSlots.split(","))
                               .map(String::trim)
                               .filter(s -> !s.isEmpty())
                               .toArray(String[]::new);
        if (parts.length == 0) {
            return "[]";
        }
        int floorInt;
        try {
            floorInt = Integer.parseInt(floor);
        } catch (NumberFormatException e) {
            return "[]";
        }

        StringBuilder inPlace = new StringBuilder();
        for (int i = 0; i < parts.length; i++) {
            if (i > 0) inPlace.append(',');
            inPlace.append('?');
        }

        String sql = "SELECT s.id, s.floor, s.seat_type, s.seat_no, "
                   + "CASE WHEN EXISTS(SELECT 1 FROM booking b WHERE b.seat_id = s.id AND b.booking_date = ? AND b.time_slot IN (" + inPlace.toString() + ")) "
                   + "THEN 0 ELSE 1 END AS available "
                   + "FROM seat s "
                   + "WHERE s.floor = ? AND s.is_active = 1 "
                   + "ORDER BY s.seat_type, s.seat_no";

        StringBuilder sb = new StringBuilder("[");
        try (Connection conn = getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            int idx = 1;
            ps.setString(idx++, bookingDate);
            for (String p : parts) {
                try {
                    ps.setInt(idx++, Integer.parseInt(p));
                } catch (NumberFormatException ex) {
                    ps.setInt(idx++, -1);
                }
            }
            ps.setInt(idx++, floorInt);

            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    if (sb.length() > 1) sb.append(',');
                    int type = rs.getInt("seat_type");
                    int seatNo = rs.getInt("seat_no");
                    String typeName;
                    switch (type) {
                        case 1: typeName = "研习位"; break;
                        case 2: typeName = "大厅座位"; break;
                        case 3: typeName = "单人研习间"; break;
                        case 4: typeName = "四人研习舱"; break;
                        case 5: typeName = "群组讨论室"; break;
                        default: typeName = "座位"; break;
                    }
                    String label = "楼" + rs.getInt("floor") + typeName + seatNo;
                    if (seatNo == 0) {
                        label = "楼" + rs.getInt("floor") + "座" + rs.getLong("id");
                    }
                    sb.append('{');
                    sb.append("\"seatId\":").append(rs.getLong("id")).append(',');
                    sb.append("\"floor\":").append(rs.getInt("floor")).append(',');
                    sb.append("\"seatType\":").append(type).append(',');
                    sb.append("\"seatNo\":").append(seatNo).append(',');
                    sb.append("\"label\":\"").append(label).append("\",");
                    sb.append("\"available\":").append(rs.getInt("available"));
                    sb.append('}');
                }
            }
        } catch (SQLException e) {
            return "[]";
        }
        sb.append(']');
        return sb.toString();
    }

    private static String doCreateBooking(String userName, String userId, String phone, String seatId,
                                          String bookingDate, String timeSlots) {
        if (userName.isEmpty() || userId.isEmpty() || phone.isEmpty() || seatId.isEmpty()
                || bookingDate.isEmpty() || timeSlots.isEmpty()) {
            return "{\"success\":false,\"message\":\"请填写完整预约信息\"}";
        }
        // 服务器端校验：电话号码必须为 11 位数字
        if (!phone.matches("\\d{11}")) {
            return "{\"success\":false,\"message\":\"电话号码格式不正确，需为11位数字\"}";
        }
        int seat;
        int[] slots;
        try {
            seat = Integer.parseInt(seatId);
            String[] parts = Arrays.stream(timeSlots.split(","))
                                   .map(String::trim)
                                   .filter(s -> !s.isEmpty())
                                   .toArray(String[]::new);
            slots = new int[parts.length];
            for (int i = 0; i < parts.length; i++) {
                slots[i] = Integer.parseInt(parts[i]);
            }
        } catch (NumberFormatException e) {
            return "{\"success\":false,\"message\":\"座位或时间段格式不正确\"}";
        }
        String checkUser = "SELECT 1 FROM user WHERE user_id = ?";
        StringBuilder inPlace = new StringBuilder();
        for (int i = 0; i < slots.length; i++) { if (i>0) inPlace.append(','); inPlace.append('?'); }
        String checkBooking = "SELECT COUNT(*) FROM booking WHERE user_id = ? AND booking_date = ? AND time_slot IN (" + inPlace.toString() + ")";
        String checkSeat = "SELECT COUNT(*) FROM booking WHERE seat_id = ? AND booking_date = ? AND time_slot IN (" + inPlace.toString() + ")";
        String insert = "INSERT INTO booking (user_name, user_id, phone, seat_id, booking_date, time_slot) VALUES (?, ?, ?, ?, ?, ?)";
        try (Connection conn = getConnection()) {
            try (PreparedStatement psUser = conn.prepareStatement(checkUser)) {
                psUser.setString(1, userId);
                try (ResultSet rs = psUser.executeQuery()) {
                    if (!rs.next()) {
                        return "{\"success\":false,\"message\":\"用户不存在，请先登录或注册\"}";
                    }
                }
            }
            try (PreparedStatement ps = conn.prepareStatement(checkBooking)) {
                int idx = 1;
                ps.setString(idx++, userId);
                ps.setString(idx++, bookingDate);
                for (int s : slots) ps.setInt(idx++, s);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next() && rs.getInt(1) > 0) {
                        return "{\"success\":false,\"message\":\"同一用户同一时间段只可预约一个座位\"}";
                    }
                }
            }
            try (PreparedStatement ps = conn.prepareStatement(checkSeat)) {
                int idx = 1;
                ps.setInt(idx++, seat);
                ps.setString(idx++, bookingDate);
                for (int s : slots) ps.setInt(idx++, s);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next() && rs.getInt(1) > 0) {
                        return "{\"success\":false,\"message\":\"该座位在所选时间段内已有预约\"}";
                    }
                }
            }
            // insert batch for each selected slot
            conn.setAutoCommit(false);
            try (PreparedStatement ps = conn.prepareStatement(insert)) {
                for (int s : slots) {
                    ps.setString(1, userName);
                    ps.setString(2, userId);
                    ps.setString(3, phone);
                    ps.setInt(4, seat);
                    ps.setString(5, bookingDate);
                    ps.setInt(6, s);
                    ps.addBatch();
                }
                ps.executeBatch();
            }
            conn.commit();
            conn.setAutoCommit(true);
            return "{\"success\":true,\"message\":\"预约成功\"}";
        } catch (SQLException e) {
            return "{\"success\":false,\"message\":\"数据库错误: " + e.getMessage() + "\"}";
        }
    }

    private static String doGetBookings(String userId) {
        if (userId.isEmpty()) {
            return "[]";
        }
        String sql = "SELECT b.id, b.user_name, b.phone, b.booking_date, b.time_slot, s.floor, s.id AS seat_id "
                   + "FROM booking b "
                   + "JOIN seat s ON b.seat_id = s.id "
                   + "WHERE b.user_id = ? "
                   + "ORDER BY b.booking_date DESC, b.time_slot";
        StringBuilder sb = new StringBuilder("[");
        try (Connection conn = getConnection();
             PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setString(1, userId);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    if (sb.length() > 1) sb.append(',');
                    sb.append('{');
                    sb.append("\"bookingId\":").append(rs.getLong("id")).append(',');
                    sb.append("\"userName\":\"").append(rs.getString("user_name")).append("\",");
                    sb.append("\"phone\":\"").append(rs.getString("phone")).append("\",");
                    sb.append("\"bookingDate\":\"").append(rs.getString("booking_date")).append("\",");
                    sb.append("\"timeSlot\":").append(rs.getInt("time_slot")).append(',');
                    sb.append("\"floor\":").append(rs.getInt("floor")).append(',');
                    sb.append("\"seatId\":").append(rs.getLong("seat_id"));
                    sb.append('}');
                }
            }
        } catch (SQLException e) {
            return "[]";
        }
        sb.append(']');
        return sb.toString();
    }

    private static String doCancelBooking(String bookingIdStr, String userId) {
        if (bookingIdStr.isEmpty() || userId.isEmpty()) {
            return "{\"success\":false,\"message\":\"参数不完整\"}";
        }
        long bookingId;
        try {
            bookingId = Long.parseLong(bookingIdStr);
        } catch (NumberFormatException e) {
            return "{\"success\":false,\"message\":\"预约ID格式不正确\"}";
        }
        String findSql = "SELECT user_id FROM booking WHERE id = ?";
        String deleteSql = "DELETE FROM booking WHERE id = ?";
        try (Connection conn = getConnection()) {
            try (PreparedStatement ps = conn.prepareStatement(findSql)) {
                ps.setLong(1, bookingId);
                try (ResultSet rs = ps.executeQuery()) {
                    if (!rs.next()) {
                        return "{\"success\":false,\"message\":\"预约不存在或已被取消\"}";
                    }
                    String owner = rs.getString(1);
                    if (!userId.equals(owner)) {
                        return "{\"success\":false,\"message\":\"无权限取消此预约\"}";
                    }
                }
            }
            try (PreparedStatement ps = conn.prepareStatement(deleteSql)) {
                ps.setLong(1, bookingId);
                int affected = ps.executeUpdate();
                if (affected == 0) {
                    return "{\"success\":false,\"message\":\"取消失败\"}";
                }
            }
            return "{\"success\":true,\"message\":\"取消成功\"}";
        } catch (SQLException e) {
            return "{\"success\":false,\"message\":\"数据库错误: " + e.getMessage() + "\"}";
        }
    }

    // ---- 取消预约请求处理器 ----
    static class CancelBookingHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }
            if (!"POST".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持POST\"}");
                return;
            }
            String body = new BufferedReader(
                new InputStreamReader(exchange.getRequestBody(), StandardCharsets.UTF_8))
                .lines().collect(Collectors.joining("\n"));
            String bookingId = jsonValue(body, "bookingId");
            String userId = jsonValue(body, "userId");
            String result = doCancelBooking(bookingId, userId);
            sendResponse(exchange, 200, result);
        }
    }

    private static String doUpdateBooking(String bookingIdStr, String userId,
                                          String userName, String phone,
                                          String seatIdStr, String bookingDate,
                                          String timeSlotStr) {
        if (bookingIdStr.isEmpty() || userId.isEmpty() || userName.isEmpty() || phone.isEmpty()
                || seatIdStr.isEmpty() || bookingDate.isEmpty() || timeSlotStr.isEmpty()) {
            return "{\"success\":false,\"message\":\"参数不完整\"}";
        }
        long bookingId;
        int seat, timeSlot;
        try {
            bookingId = Long.parseLong(bookingIdStr);
            seat = Integer.parseInt(seatIdStr);
            timeSlot = Integer.parseInt(timeSlotStr);
        } catch (NumberFormatException e) {
            return "{\"success\":false,\"message\":\"ID/座位/时间段格式不正确\"}";
        }
        if (!phone.matches("\\d{11}")) {
            return "{\"success\":false,\"message\":\"电话号码格式不正确，需为11位数字\"}";
        }

        String findSql = "SELECT user_id FROM booking WHERE id = ?";
        String userConflict = "SELECT COUNT(*) FROM booking WHERE user_id = ? AND booking_date = ? AND time_slot = ? AND id != ?";
        String seatConflict = "SELECT COUNT(*) FROM booking WHERE seat_id = ? AND booking_date = ? AND time_slot = ? AND id != ?";
        String updateSql = "UPDATE booking SET user_name = ?, phone = ?, seat_id = ?, booking_date = ?, time_slot = ? WHERE id = ?";

        try (Connection conn = getConnection()) {
            // ownership
            try (PreparedStatement ps = conn.prepareStatement(findSql)) {
                ps.setLong(1, bookingId);
                try (ResultSet rs = ps.executeQuery()) {
                    if (!rs.next()) {
                        return "{\"success\":false,\"message\":\"预约不存在\"}";
                    }
                    String owner = rs.getString(1);
                    if (!userId.equals(owner)) {
                        return "{\"success\":false,\"message\":\"无权限修改此预约\"}";
                    }
                }
            }

            // user conflict: same user cannot have another booking at same date/slot
            try (PreparedStatement ps = conn.prepareStatement(userConflict)) {
                ps.setString(1, userId);
                ps.setString(2, bookingDate);
                ps.setInt(3, timeSlot);
                ps.setLong(4, bookingId);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next() && rs.getInt(1) > 0) {
                        return "{\"success\":false,\"message\":\"同一用户在该时间段已有其他预约\"}";
                    }
                }
            }

            // seat conflict
            try (PreparedStatement ps = conn.prepareStatement(seatConflict)) {
                ps.setInt(1, seat);
                ps.setString(2, bookingDate);
                ps.setInt(3, timeSlot);
                ps.setLong(4, bookingId);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next() && rs.getInt(1) > 0) {
                        return "{\"success\":false,\"message\":\"该座位在所选时间段已被占用\"}";
                    }
                }
            }

            try (PreparedStatement ps = conn.prepareStatement(updateSql)) {
                ps.setString(1, userName);
                ps.setString(2, phone);
                ps.setInt(3, seat);
                ps.setString(4, bookingDate);
                ps.setInt(5, timeSlot);
                ps.setLong(6, bookingId);
                int updated = ps.executeUpdate();
                if (updated == 0) {
                    return "{\"success\":false,\"message\":\"修改失败\"}";
                }
            }

            return "{\"success\":true,\"message\":\"修改成功\"}";
        } catch (SQLException e) {
            return "{\"success\":false,\"message\":\"数据库错误: " + e.getMessage() + "\"}";
        }
    }

    static class UpdateBookingHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }
            if (!"POST".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持POST\"}");
                return;
            }
            String body = new BufferedReader(
                new InputStreamReader(exchange.getRequestBody(), StandardCharsets.UTF_8))
                .lines().collect(Collectors.joining("\n"));
            String bookingId = jsonValue(body, "bookingId");
            String userId = jsonValue(body, "userId");
            String userName = jsonValue(body, "userName");
            String phone = jsonValue(body, "phone");
            String seatId = jsonValue(body, "seatId");
            String date = jsonValue(body, "date");
            String timeSlot = jsonValue(body, "timeSlot");
            String result = doUpdateBooking(bookingId, userId, userName, phone, seatId, date, timeSlot);
            sendResponse(exchange, 200, result);
        }
    }

    // ---- 登录请求处理器 ----
    static class LoginHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }
            if (!"POST".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持POST\"}");
                return;
            }

            String body = new BufferedReader(
                new InputStreamReader(exchange.getRequestBody(), StandardCharsets.UTF_8))
                .lines().collect(Collectors.joining("\n"));

            String studentId = jsonValue(body, "studentId");
            String password  = jsonValue(body, "password");

            if (studentId.isEmpty() || password.isEmpty()) {
                sendResponse(exchange, 400, "{\"success\":false,\"message\":\"学号和密码不能为空\"}");
                return;
            }

            String result = doLogin(studentId, password);
            sendResponse(exchange, 200, result);
        }
    }

    // ---- 座位查询请求处理器 ----
    static class SeatListHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }
            if (!"GET".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持GET\"}");
                return;
            }
            Map<String, String> params = parseQuery(exchange.getRequestURI().getQuery());
            String result = doGetSeats(params.getOrDefault("floor", ""),
                                       params.getOrDefault("date", ""),
                                       params.getOrDefault("timeSlots", params.getOrDefault("timeSlot", "")));
            sendResponse(exchange, 200, result);
        }
    }

    // ---- 提交预约请求处理器 ----
    static class BookingHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }
            if (!"POST".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持POST\"}");
                return;
            }
            String body = new BufferedReader(
                new InputStreamReader(exchange.getRequestBody(), StandardCharsets.UTF_8))
                .lines().collect(Collectors.joining("\n"));
            String userName = jsonValue(body, "userName");
            String userId   = jsonValue(body, "userId");
            String phone    = jsonValue(body, "phone");
            String seatId   = jsonValue(body, "seatId");
            String bookingDate = jsonValue(body, "date");
            String timeSlots = jsonValue(body, "timeSlots");
            if (timeSlots.isEmpty()) {
                timeSlots = jsonValue(body, "timeSlot");
            }
            String result = doCreateBooking(userName, userId, phone, seatId, bookingDate, timeSlots);
            sendResponse(exchange, 200, result);
        }
    }

    // ---- 用户预约列表请求处理器 ----
    static class BookingListHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange exchange) throws IOException {
            if ("OPTIONS".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 204, "");
                return;
            }
            if (!"GET".equals(exchange.getRequestMethod())) {
                sendResponse(exchange, 405, "{\"success\":false,\"message\":\"仅支持GET\"}");
                return;
            }
            Map<String, String> params = parseQuery(exchange.getRequestURI().getQuery());
            String result = doGetBookings(params.getOrDefault("userId", ""));
            sendResponse(exchange, 200, result);
        }
    }

    // ---- 静态文件服务 ----
    static class StaticFileHandler implements HttpHandler {
        private final Path root;

        StaticFileHandler(String rootDir) {
            this.root = Paths.get(rootDir).toAbsolutePath().normalize();
        }

        @Override
        public void handle(HttpExchange exchange) throws IOException {
            String requestPath = exchange.getRequestURI().getPath();
            if (requestPath.equals("/") || requestPath.equals("/frontend") || requestPath.equals("/frontend/")) {
                requestPath = "/login.html";
            } else if (requestPath.startsWith("/frontend/")) {
                requestPath = requestPath.substring("/frontend".length());
            }

            Path file = root.resolve(requestPath.substring(1)).normalize();
            if (!file.startsWith(root) || !Files.exists(file) || Files.isDirectory(file)) {
                String notFound = "404 Not Found";
                exchange.getResponseHeaders().set("Content-Type", "text/plain;charset=utf-8");
                byte[] bytes = notFound.getBytes(StandardCharsets.UTF_8);
                exchange.sendResponseHeaders(404, bytes.length);
                try (OutputStream os = exchange.getResponseBody()) {
                    os.write(bytes);
                }
                return;
            }

            String contentType = guessContentType(file);
            exchange.getResponseHeaders().set("Content-Type", contentType);
            byte[] bytes = Files.readAllBytes(file);
            exchange.sendResponseHeaders(200, bytes.length);
            try (OutputStream os = exchange.getResponseBody()) {
                os.write(bytes);
            }
        }

        private String guessContentType(Path file) throws IOException {
            String type = Files.probeContentType(file);
            if (type != null) {
                return type + ";charset=utf-8";
            }
            String name = file.getFileName().toString().toLowerCase();
            if (name.endsWith(".html") || name.endsWith(".htm")) return "text/html;charset=utf-8";
            if (name.endsWith(".css")) return "text/css;charset=utf-8";
            if (name.endsWith(".js")) return "application/javascript;charset=utf-8";
            if (name.endsWith(".json")) return "application/json;charset=utf-8";
            if (name.endsWith(".png")) return "image/png";
            if (name.endsWith(".jpg") || name.endsWith(".jpeg")) return "image/jpeg";
            if (name.endsWith(".gif")) return "image/gif";
            if (name.endsWith(".svg")) return "image/svg+xml";
            return "application/octet-stream";
        }
    }

    // ---- 启动服务器 ----
    public static void main(String[] args) throws Exception {
        // 读取数据库密码：优先命令行参数，否则交互输入
        if (args.length > 0) {
            DB_PASS = args[0];
        } else {
            System.out.print("请输入 MySQL root 密码: ");
            Scanner scanner = new Scanner(System.in);
            DB_PASS = scanner.nextLine().trim();
        }

        // 验证密码是否为空
        if (DB_PASS.isEmpty()) {
            System.err.println("密码不能为空，服务器启动失败！");
            return;
        }

        // 测试数据库连接
        System.out.print("正在连接数据库... ");
        Connection testConn = null;
        try {
            testConn = getConnection();
            System.out.println("连接成功！");
        } catch (SQLException e) {
            System.err.println("连接失败: " + e.getMessage());
            System.err.println("请检查密码是否正确，服务器启动失败！");
            return;
        } finally {
            try { if (testConn != null) testConn.close(); } catch (SQLException ignored) {}
        }

        // 确保 seat 表包含 seat_type 和 seat_no 字段，并初始化座位数据（如果表为空）
        try {
            ensureSeatSchema();
            initializeSeatsIfEmpty();
            ensureSpecialRooms();
        } catch (SQLException e) {
            System.err.println("初始化座位数据失败: " + e.getMessage());
            return;
        }

        // 启动 HTTP 服务器
        HttpServer server = HttpServer.create(new InetSocketAddress(PORT), 0);
        server.createContext("/api/register", new RegisterHandler());
        server.createContext("/api/login",    new LoginHandler());
        server.createContext("/api/seats",    new SeatListHandler());
        server.createContext("/api/booking",  new BookingHandler());
        server.createContext("/api/cancel",   new CancelBookingHandler());
        server.createContext("/api/updateBooking", new UpdateBookingHandler());
        server.createContext("/api/bookings", new BookingListHandler());
        server.createContext("/frontend",     new StaticFileHandler("frontend"));
        server.createContext("/",            new StaticFileHandler("frontend"));
        server.setExecutor(null);
        server.start();
        System.out.println("后端服务器已启动 → http://localhost:" + PORT);
        System.out.println("注册接口: POST http://localhost:" + PORT + "/api/register");
        System.out.println("登录接口: POST http://localhost:" + PORT + "/api/login");

        // 保持服务器运行
        synchronized (main.class) {
            try { main.class.wait(); }
            catch (InterruptedException e) { System.out.println("服务已停止"); }
        }
        server.stop(0);
    }
}
