-- ============================================
-- 数据库: library_booking (图书馆预约系统)
-- ============================================

CREATE DATABASE IF NOT EXISTS library_booking
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE library_booking;

-- ============================================
-- 表1: seat — 座位表
-- ============================================
CREATE TABLE IF NOT EXISTS seat (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT  COMMENT '座位ID',
    floor           TINYINT UNSIGNED NOT NULL                 COMMENT '所属楼层',
    is_active       TINYINT UNSIGNED NOT NULL DEFAULT 1       COMMENT '是否可用(1=可用 0=停用)',
    seat_type       TINYINT UNSIGNED NOT NULL DEFAULT 1       COMMENT '座位类型(1=单人研习位,2=大厅座位)',
    seat_no         INT UNSIGNED      NOT NULL DEFAULT 0       COMMENT '类型内编号',
    PRIMARY KEY (id),
    INDEX idx_floor (floor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='座位表';

-- ============================================
-- 表2: user — 用户表
-- ============================================
CREATE TABLE IF NOT EXISTS user (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT  COMMENT '主键ID',
    user_id         VARCHAR(32)      NOT NULL                 COMMENT '学号/工号',
    password        VARCHAR(255)     NOT NULL                 COMMENT '密码(加密存储)',
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '注册时间',
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (id),
    UNIQUE INDEX uk_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ============================================
-- 表3: booking — 预约记录表
-- ============================================
CREATE TABLE IF NOT EXISTS booking (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT  COMMENT '主键ID',
    user_name       VARCHAR(64)      NOT NULL                 COMMENT '预约人姓名',
    user_id         VARCHAR(32)      NOT NULL                 COMMENT '学号/工号',
    phone           VARCHAR(20)      DEFAULT NULL             COMMENT '联系电话',
    seat_id         BIGINT UNSIGNED  NOT NULL                 COMMENT '座位ID',
    booking_date    DATE             NOT NULL                 COMMENT '预约日期',
    time_slot       TINYINT UNSIGNED NOT NULL                 COMMENT '时间段(6=06:00-07:00 ... 21=21:00-22:00)',
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (id),
    INDEX idx_user_id (user_id),
    INDEX idx_booking_date (booking_date),
    INDEX idx_seat_date (seat_id, booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='预约记录表';