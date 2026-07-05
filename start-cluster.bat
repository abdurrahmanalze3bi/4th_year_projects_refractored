@echo off
cd /d C:\wamp64\www\4th_year_project
if not exist storage\logs mkdir storage\logs

start /B "" cmd /c "php artisan serve --port=8001 > storage\logs\node1.log 2>&1"
start /B "" cmd /c "php artisan serve --port=8002 > storage\logs\node2.log 2>&1"
start /B "" cmd /c "php artisan serve --port=8003 > storage\logs\node3.log 2>&1"

start /B "" C:\nginx\nginx.exe -p C:\nginx\

echo Cluster is up: http://localhost:8080
