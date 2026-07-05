@echo off
C:\nginx\nginx.exe -p C:\nginx\ -s stop
taskkill /F /IM php.exe /T
echo Stopped.
