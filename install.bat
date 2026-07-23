@echo off
rem Doble clic para lanzar el asistente grafico (install.ps1) sin ventana de consola.
start "" powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0install.ps1"
