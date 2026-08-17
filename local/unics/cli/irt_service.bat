@echo off
REM Python-сервис IRT для УНИКС (FastAPI/uvicorn) - запуск и присмотр.
REM
REM Вызывать задачей Windows ?Moodle UNICS IRT?: триггер ?При входе в систему?,
REM повтор раз в 5 минут, ?Не запускать новый экземпляр? (IgnoreNew).
REM Повтор нужен не для частоты, а для самовосстановления: пока сервис жив, этот
REM скрипт висит вместе с ним и повторные тики планировщик пропускает; как только
REM сервис упал, скрипт завершается и следующий тик поднимает его заново.
REM
REM Лог: server\moodledata\irt_service.log (из web не доступен - нормально для логов).
REM В каталог ai-service не пишем: это ОТДЕЛЬНЫЙ git-репозиторий, мусорить в нем нельзя.
REM Если лог разрастется - удалить, создастся заново.

setlocal

set "SERVICE_DIR=c:\Moodle\ai-service"
set "PY_EXE=%SERVICE_DIR%\.venv\Scripts\python.exe"
set "SERVICE_LOG=c:\Moodle\server\moodledata\irt_service.log"
set "PORT=8000"

REM Порт уже слушают - значит сервис поднят кем-то другим (руками или прошлым
REM экземпляром задачи). Выходим молча: второй uvicorn упал бы с ?address already
REM in use? и писал бы эту ошибку в лог на каждом тике.
"%SystemRoot%\System32\netstat.exe" -ano | "%SystemRoot%\System32\findstr.exe" ":%PORT%" | "%SystemRoot%\System32\findstr.exe" LISTENING >nul 2>&1
if %ERRORLEVEL% EQU 0 exit /b 0

if not exist "%PY_EXE%" (
    echo. >> "%SERVICE_LOG%"
    echo === %DATE% %TIME% === >> "%SERVICE_LOG%"
    echo [error] нет интерпретатора: %PY_EXE% >> "%SERVICE_LOG%"
    echo [error] создать окружение: python -m venv .venv ^&^& .venv\Scripts\python.exe -m pip install -r requirements.txt >> "%SERVICE_LOG%"
    exit /b 1
)

echo. >> "%SERVICE_LOG%"
echo === %DATE% %TIME% запуск сервиса === >> "%SERVICE_LOG%"

cd /d "%SERVICE_DIR%"
REM Без start: скрипт намеренно живет столько же, сколько сервис (см. шапку).
"%PY_EXE%" -m uvicorn app.main:app --host 127.0.0.1 --port %PORT% >> "%SERVICE_LOG%" 2>&1

echo === %DATE% %TIME% сервис завершился (код %ERRORLEVEL%) === >> "%SERVICE_LOG%"

endlocal
