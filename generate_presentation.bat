@echo off
echo Rebuilding SAMS Project Presentation with SAMS Academic Theme...
"%USERPROFILE%\.local\bin\uv.exe" run --with python-pptx python "%~dp0build_presentation.py"
echo Done.
pause
