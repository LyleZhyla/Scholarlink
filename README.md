# ScholarLink

Scholarship management portal for Tarlac Agricultural University, built with PHP, MariaDB, and XAMPP.

## Windows setup (no WSL required)

1. Install XAMPP with PHP 8.2 or newer.
2. Clone this repository into `C:\xampp\htdocs\scholarlink`.
3. Open Windows PowerShell in the project folder and install dependencies:

   ```powershell
   C:\xampp\php\php.exe C:\ProgramData\ComposerSetup\bin\composer.phar install
   ```

   If Composer is available in `PATH`, `composer install` is enough.

4. Copy `.env.example` to `.env`, then fill in the Google, Gemini, and SMTP credentials needed by your environment.
5. Configure XAMPP MariaDB to use port `3307`, or update `includes/db_connect.php` to match your local database port.
6. Start Apache and MySQL from the XAMPP Control Panel.
7. Create the database and import the schema from Windows PowerShell:

   ```powershell
   C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE scholarlink_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
   Get-Content .\database\schema.sql -Raw | C:\xampp\mysql\bin\mysql.exe -h 127.0.0.1 -P 3307 -u root scholarlink_db
   ```

8. Open [http://localhost/scholarlink/](http://localhost/scholarlink/) in a browser.

## Local-only files

Credentials, database backups, generated exports, temporary files, dependencies, and uploaded student documents are intentionally excluded from Git. Run `composer install` after cloning and create local accounts through the application or database administration tools.
