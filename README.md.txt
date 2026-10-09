# TikTok Downloader Telegram Bot (PHP)

A high-performance Telegram Bot written in native PHP to download videos, audio, and photo albums from TikTok without watermarks.

## Features
- **No-Watermark Media Fetching:** Supports single videos, multi-photo slide decks (albums), and background music.
- **SQLite Database:** Zero-configuration local database with WAL mode for fast concurrent operations.
- **Admin Management Panel:**
  - Mandatory Channel Join verification (force subscription).
  - Post-download advertisement manager (text & banner).
  - Broadcast messaging system and direct user communication.
  - Maintenance mode toggle.
  - Daily active stats and total download counters.
  - Automatic 24-hour database backups sent directly to admin.
- **Smart Fallback Handling:** Automatic conversion to Document if Telegram rejects photo dimensions.

## Requirements
- PHP 7.4+ or PHP 8.x
- cURL Extension (`php-curl`)
- SQLite3 Extension (`php-sqlite3`)
- SSL Certificate (HTTPS) for Webhook

## Setup & Deployment

1. Clone the repository:
   ```bash
   git clone [https://github.com/YOUR_USERNAME/tiktok-downloader-bot.git](https://github.com/YOUR_USERNAME/tiktok-downloader-bot.git)
   cd tiktok-downloader-bot