@echo off
cd /d "%~dp0"
echo ============================================
echo   图书馆预约系统 - PHP 后端服务器
echo ============================================
echo.
set /p DB_PASS="请输入 MySQL root 密码: "
set MYSQL_ROOT_PASSWORD=%DB_PASS%
echo.
echo 正在启动 PHP 服务器...
echo 注册接口: http://localhost:8080/api/register
echo 登录接口: http://localhost:8080/api/login
echo 预约页面: http://localhost:8080/
echo.
echo 按 Ctrl+C 停止服务器
echo ============================================
echo.
php -S localhost:8080 -t . backend-php/router.php
pause
