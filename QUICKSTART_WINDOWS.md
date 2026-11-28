# NexaPatch AI: quick start on Windows (PowerShell)

Needs: PHP 8.2+ (extensions curl, fileinfo, mbstring, openssl, pdo_mysql, pdo_sqlite, sqlite3, zip), Composer, MySQL (XAMPP is fine).

```powershell
cd D:\nexapatch-ai
php composer.phar install            # or: composer install
copy .env.example .env               # skip if .env already exists
php artisan key:generate
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS nexapatch CHARACTER SET utf8mb4;"
php artisan migrate --seed
php artisan test
php artisan serve
```
Open http://127.0.0.1:8000 and log in with `developer@nexapatch.test` / `Password@123`.

Updating an existing project with a patch ZIP: extract it over the project folder (Replace files), then
```powershell
php composer.phar require dompdf/dompdf      # only once, for the PDF report
php artisan migrate
php artisan test
```

## Using it
1. Projects -> New project.  2. Open it, drag a PHP file or a .zip into the upload box.  3. Click **Scan**.
4. Read the findings (click one for the code, explanation and fix advice).  5. **Download PDF report**.

## Optional AI (free tiers only), for the future fix feature: in `.env`
```
LLM_PROVIDER=gemini
GEMINI_API_KEY=your-free-key-from-aistudio.google.com
```
or `LLM_PROVIDER=openai` with `LLM_BASE_URL` / `LLM_API_KEY` / `LLM_MODEL` (Groq, OpenRouter free models, or local Ollama at http://localhost:11434/v1).
