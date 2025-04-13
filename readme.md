# Cronitorex UI

A simple PHP application to display Cronitorex logs in a user-friendly interface.

## Requirements

- PHP 8.3 or higher
- MySQL 5.7 or higher
- Composer

## Installation

1. Clone the repository:
   ```
   git clone <repository-url>
   cd cronitor-clone-ui
   ```

2. Install dependencies:
   ```
   composer install
   ```

3. Create a MySQL database:
   ```sql
   CREATE DATABASE cronitor_clone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. Copy the `.env.example` file to `.env` and update the database credentials:
   ```
   cp .env.example .env
   ```

5. Import the database schema:
   ```
   mysql -u username -p cronitor_clone < database/schema.sql
   ```

6. Set the correct path to your log files in the `.env` file:
   ```
   LOG_DIR=/path/to/logs
   HISTORY_LOG=/path/to/logs/cronitor-history.log
   TAGS_INDEX=/path/to/logs/cronitor-tags-index.json
   API_LOG=/path/to/logs/api.log
   ```

7. Import logs into the database:
   ```
   php bin/import.php
   ```

8. Configure your web server to point to the `public` directory, or use PHP's built-in server for testing:
   ```
   cd public
   php -S localhost:8000
   ```

9. Access the application in your browser at `http://localhost:8000`

## Configuration

You can customize the application by modifying the following settings in the `.env` file:

- `DB_HOST`: Database host
- `DB_NAME`: Database name
- `DB_USER`: Database username
- `DB_PASS`: Database password
- `LOG_DIR`: Directory containing log files
- `HISTORY_LOG`: Path to the cronitor history log file
- `TAGS_INDEX`: Path to the cronitor tags index file
- `API_LOG`: Path to the API log file
- `APP_URL`: Application URL (used for generating links)

## Regular Log Import

To keep the database updated with the latest logs, you can set up a cron job to run the import script:

```
*/5 * * * * /path/to/php /path/to/cronitor-clone-ui/bin/import.php >> /var/log/cronitor-import.log 2>&1
```

This will import new logs every 5 minutes.

## Features

- Dashboard showing all monitored tasks
- Filtering tasks by tags
- Detailed view of each monitor with performance charts
- Latest activity and issues for each monitor
- Log synchronization with the database