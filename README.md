# Instagram Telegram Bot

Instagram video havolasidan video nomi va teglarini oluvchi Telegram bot.

## O'rnatish

1. Dependencylarni o'rnating:
```bash
cd instagram-telegram-bot
npm install
```

2. `.env.example` faylini `.env` ga nusxalang va bot tokeningizni kiriting:
```bash
copy .env.example .env
```

3. Telegram @BotFather dan bot yarating va tokenni `.env` faylga qo'shing.

## Ishga tushirish

```bash
set TELEGRAM_BOT_TOKEN=your_token_here
npm start
```

## Foydalanish

Botga Instagram video/reel havolasini yuboring, bot sizga caption va teglarni qaytaradi.
