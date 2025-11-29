require('dotenv').config();
const TelegramBot = require('node-telegram-bot-api');
const axios = require('axios');
const cheerio = require('cheerio');
const http = require('http');

// Bot tokenini .env faylidan olish
const token = process.env.TELEGRAM_BOT_TOKEN;

if (!token) {
  console.error('❌ TELEGRAM_BOT_TOKEN topilmadi! .env faylini tekshiring.');
  process.exit(1);
}

const bot = new TelegramBot(token, { polling: true });

// Instagram URL ni tekshirish
function isInstagramUrl(url) {
  return /instagram\.com\/(p|reel|reels|tv)\/[\w-]+/i.test(url);
}

// Instagram post ID ni olish
function extractPostId(url) {
  const match = url.match(/instagram\.com\/(p|reel|reels|tv)\/([\w-]+)/i);
  return match ? match[2] : null;
}

// Instagram ma'lumotlarini olish
async function getInstagramInfo(url) {
  const postId = extractPostId(url);
  if (!postId) {
    throw new Error('Post ID topilmadi');
  }

  const headers = {
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language': 'en-US,en;q=0.9',
    'Accept-Encoding': 'gzip, deflate, br',
    'Cache-Control': 'no-cache',
    'Sec-Fetch-Dest': 'document',
    'Sec-Fetch-Mode': 'navigate',
    'Sec-Fetch-Site': 'none',
    'Sec-Fetch-User': '?1',
    'Upgrade-Insecure-Requests': '1',
  };

  let caption = '';
  let author = 'Noma\'lum';

  // 1-usul: oEmbed API
  try {
    const oembedUrl = `https://api.instagram.com/oembed/?url=${encodeURIComponent(url)}`;
    const response = await axios.get(oembedUrl, { timeout: 10000 });
    
    if (response.data && response.data.title) {
      caption = response.data.title;
      author = response.data.author_name || author;
      console.log('oEmbed muvaffaqiyatli');
    }
  } catch (e) {
    console.error('oEmbed xatosi:', e.message);
  }

  // 2-usul: Embed sahifa (captioned)
  if (!caption) {
    try {
      const embedUrl = `https://www.instagram.com/p/${postId}/embed/captioned/`;
      const response = await axios.get(embedUrl, { headers, timeout: 15000 });
      const $ = cheerio.load(response.data);
      
      // Caption ni olish
      const captionEl = $('.Caption, .CaptionUsername').parent().text() ||
                       $('meta[property="og:description"]').attr('content') || '';
      
      // Script ichidan JSON olish
      const scripts = $('script').toArray();
      for (const script of scripts) {
        const content = $(script).html() || '';
        const jsonMatch = content.match(/window\.__additionalDataLoaded\s*\(\s*['"][^'"]+['"]\s*,\s*(\{.+\})\s*\)/);
        if (jsonMatch) {
          try {
            const data = JSON.parse(jsonMatch[1]);
            const media = data.shortcode_media || data.graphql?.shortcode_media;
            if (media) {
              caption = media.edge_media_to_caption?.edges?.[0]?.node?.text || caption;
              author = media.owner?.username || author;
              console.log('Embed JSON muvaffaqiyatli');
              break;
            }
          } catch (e) {}
        }
      }
      
      if (!caption && captionEl) {
        caption = captionEl.trim();
      }
      
      // Author ni olish
      if (author === 'Noma\'lum') {
        const authorEl = $('.UsernameText').text() || 
                        $('a[href*="instagram.com"]').first().text();
        if (authorEl) author = authorEl.replace('@', '').trim();
      }
    } catch (e) {
      console.error('Embed xatosi:', e.message);
    }
  }

  // 3-usul: Asosiy sahifa
  if (!caption) {
    try {
      const mainUrl = `https://www.instagram.com/p/${postId}/`;
      const response = await axios.get(mainUrl, { headers, timeout: 15000 });
      const $ = cheerio.load(response.data);
      
      // Meta teglardan olish
      const ogTitle = $('meta[property="og:title"]').attr('content') || '';
      const ogDesc = $('meta[property="og:description"]').attr('content') || '';
      const title = $('title').text() || '';
      
      caption = ogDesc || ogTitle || title;
      
      // Author ni title dan olish (format: "Author on Instagram: ...")
      const authorFromTitle = ogTitle.match(/^(.+?)\s+on\s+Instagram/i) ||
                             title.match(/^(.+?)\s+on\s+Instagram/i);
      if (authorFromTitle) {
        author = authorFromTitle[1].trim();
      }
      
      // Script ichidan JSON
      const scripts = $('script[type="application/ld+json"]').toArray();
      for (const script of scripts) {
        try {
          const data = JSON.parse($(script).html());
          if (data.author?.name) author = data.author.name;
          if (data.articleBody) caption = data.articleBody;
        } catch (e) {}
      }
      
      console.log('Main page muvaffaqiyatli');
    } catch (e) {
      console.error('Main page xatosi:', e.message);
    }
  }

  // Agar hech narsa topilmasa
  if (!caption && author === 'Noma\'lum') {
    throw new Error('Ma\'lumotlar topilmadi');
  }

  // HTML entity decode
  caption = caption
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/\\n/g, '\n')
    .trim();

  const hashtags = caption.match(/#[\w\u0400-\u04FFа-яА-Я]+/g) || [];

  return { title: caption, author, hashtags };
}

// /start buyrug'i
bot.onText(/\/start/, (msg) => {
  const chatId = msg.chat.id;
  bot.sendMessage(chatId, 
    '👋 Salom! Men Instagram video ma\'lumotlarini oluvchi botman.\n\n' +
    '📹 Menga Instagram video/reel havolasini yuboring va men sizga:\n' +
    '• Video nomi (caption)\n' +
    '• Teglar (hashtags)\n' +
    '• Muallif ismini yuboraman.\n\n' +
    '🔗 Misol: https://www.instagram.com/reel/ABC123/'
  );
});

// Markdown maxsus belgilarni escape qilish
function escapeMarkdown(text) {
  if (!text) return '';
  return text.replace(/([_*`\[])/g, '\\$1');
}

// Xabarlarni qayta ishlash
bot.on('message', async (msg) => {
  const chatId = msg.chat.id;
  const text = msg.text;

  // Buyruqlarni o'tkazib yuborish
  if (!text || text.startsWith('/')) return;

  // Instagram URL ni tekshirish
  if (isInstagramUrl(text)) {
    bot.sendMessage(chatId, '⏳ Ma\'lumotlar yuklanmoqda...');

    try {
      const info = await getInstagramInfo(text);
      
      // Xavfsiz matn tayyorlash
      const safeAuthor = escapeMarkdown(info.author);
      const safeTitle = escapeMarkdown(info.title);
      const safeHashtags = info.hashtags.map(h => escapeMarkdown(h)).join(' ');
      
      let response = '📹 *Instagram Video Ma\'lumotlari*\n\n';
      response += '👤 *Muallif:* ' + safeAuthor + '\n\n';
      
      if (info.title) {
        response += '📝 *Caption:*\n' + safeTitle + '\n\n';
      }
      
      if (info.hashtags.length > 0) {
        response += '🏷 *Teglar (' + info.hashtags.length + ' ta):*\n' + safeHashtags + '\n';
      } else {
        response += '🏷 *Teglar:* Teglar topilmadi\n';
      }

      bot.sendMessage(chatId, response, { parse_mode: 'Markdown' });
      
    } catch (error) {
      console.error('Xatolik:', error.message);
      bot.sendMessage(chatId, 
        '❌ Xatolik yuz berdi.\n\n' +
        'Mumkin sabablar:\n' +
        '• Post yopiq (private) akkauntda\n' +
        '• Havola noto\'g\'ri\n' +
        '• Instagram cheklovlari\n\n' +
        'Iltimos, ochiq akkauntdagi postni yuboring.'
      );
    }
  } else if (text.includes('instagram.com')) {
    bot.sendMessage(chatId, '⚠️ Iltimos, to\'g\'ri Instagram video/reel havolasini yuboring.');
  }
});

console.log('🤖 Bot ishga tushdi...');

// ============ API SERVER ============
const API_PORT = process.env.API_PORT || 3000;

const apiServer = http.createServer(async (req, res) => {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');

  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  const url = new URL(req.url, `http://localhost:${API_PORT}`);
  const instagramUrl = url.searchParams.get('url');

  // Root endpoint
  if (url.pathname === '/' && !instagramUrl) {
    res.writeHead(200);
    res.end(JSON.stringify({
      status: 'ok',
      message: 'Instagram Info API',
      usage: '/?url=https://www.instagram.com/reel/ABC123/',
      endpoints: {
        'GET /?url=<instagram_url>': 'Instagram post ma\'lumotlarini olish'
      }
    }));
    return;
  }

  // API endpoint
  if (url.pathname === '/' && instagramUrl) {
    // URL ni tekshirish
    if (!isInstagramUrl(instagramUrl)) {
      res.writeHead(400);
      res.end(JSON.stringify({
        success: false,
        error: 'Noto\'g\'ri Instagram URL',
        message: 'Iltimos, to\'g\'ri Instagram post/reel havolasini yuboring'
      }));
      return;
    }

    try {
      console.log(`API so'rov: ${instagramUrl}`);
      const info = await getInstagramInfo(instagramUrl);
      
      res.writeHead(200);
      res.end(JSON.stringify({
        success: true,
        data: {
          author: info.author,
          caption: info.title,
          hashtags: info.hashtags,
          hashtags_count: info.hashtags.length
        }
      }));
    } catch (error) {
      console.error('API xatolik:', error.message);
      res.writeHead(500);
      res.end(JSON.stringify({
        success: false,
        error: error.message,
        message: 'Instagram ma\'lumotlarini olishda xatolik'
      }));
    }
    return;
  }

  // 404
  res.writeHead(404);
  res.end(JSON.stringify({
    success: false,
    error: 'Endpoint topilmadi'
  }));
});

apiServer.listen(API_PORT, () => {
  console.log(`🌐 API server ishga tushdi: http://localhost:${API_PORT}`);
  console.log(`📡 Foydalanish: http://localhost:${API_PORT}/?url=<instagram_url>`);
});
