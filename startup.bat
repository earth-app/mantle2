@echo off
setlocal EnableDelayedExpansion

set PROJECT_NAME=mantle2
set SITE_DIR=C:\temp\drupal-%PROJECT_NAME%
set SITE_NAME=Drupal %PROJECT_NAME% Test
set SRC_PATH=%~dp0

:: Remove trailing backslash from SRC_PATH
if "%SRC_PATH:~-1%"=="\" set SRC_PATH=%SRC_PATH:~0,-1%

if not exist "%SITE_DIR%" (
    echo ^>^>^> Creating new Drupal site in %SITE_DIR%
    echo ^>^>^> Using Path: %SRC_PATH%

    composer create-project drupal/recommended-project "%SITE_DIR%"
    if errorlevel 1 goto :error

    cd /d "%SITE_DIR%"

    composer require drush/drush
    if errorlevel 1 goto :error

    composer require drupal/json_field
    if errorlevel 1 goto :error

    if not exist "web\modules\custom\%PROJECT_NAME%" mkdir "web\modules\custom\%PROJECT_NAME%"
    xcopy "%SRC_PATH%\*" "web\modules\custom\%PROJECT_NAME%\" /E /Y /I
    if errorlevel 1 goto :error

    ddev config --project-type=drupal11 --docroot=web --project-name="%PROJECT_NAME%" --host-webserver-port=8787
    if errorlevel 1 goto :error

    ddev start
    if errorlevel 1 goto :error

    ddev drush site:install standard --account-name=admin --account-pass=admin --site-name="%SITE_NAME%" -y
    if errorlevel 1 goto :error

    ddev drush en json_field -y
    if errorlevel 1 goto :error

    ddev drush en "%PROJECT_NAME%" -y >nul 2>&1
    if errorlevel 1 goto :error
) else (
    echo ^>^>^> Reusing existing site at %SITE_DIR%
    echo ^>^>^> Using Path: %SRC_PATH%
    cd /d "%SITE_DIR%"

    if not exist "web\modules\custom\%PROJECT_NAME%" mkdir "web\modules\custom\%PROJECT_NAME%"
    xcopy "%SRC_PATH%\*" "web\modules\custom\%PROJECT_NAME%\" /E /Y /I /Q
    if errorlevel 1 goto :error

    ddev restart
    if errorlevel 1 goto :error
)

echo.
echo ^>^>^> Drupal site with %PROJECT_NAME% is ready!
echo Project directory: %SITE_DIR%

goto :end

:error
echo Error occurred during execution!
exit /b 1

:end
endlocal
