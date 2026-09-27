@echo off
title OMPS Server - DynamX
echo ========================================================
echo        Starting OMPS Server (DynamX)
echo ========================================================
echo.
echo Server local URL  : http://localhost:8081/
echo Web Dashboard     : http://localhost:8081/index.php
echo MPS Auth Endpoint : http://localhost:8081/1.3.0/home.php
echo.
echo Press Ctrl+C to stop the server.
echo.
php -d upload_max_filesize=512M -d post_max_size=512M -d memory_limit=512M -S 0.0.0.0:8081 index.php
pause
