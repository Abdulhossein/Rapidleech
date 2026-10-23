### RapidLeech v2.5.0

Build and Edited from [PBhadoo/Rapidleech](https://github.com/PBhadoo/Rapidleech)

**Live Server:** [https://hashhackers.apranet.eu.org](https://hashhackers.apranet.eu.org)

#### Quick Install (Ubuntu 22.04 / 24.04)

```bash
bash <(curl -s https://raw.githubusercontent.com/Abdulhossein/Rapidleech/main/rapidleech.sh)
```

This installs PHP 8.3, Apache2, yt-dlp, ffmpeg, Deno, and all required extensions. Optionally sets up SSL.

#### Requirements

| Requirement | Details |
|---|---|
| **OS** | Ubuntu 22.04 or 24.04 LTS |
| **PHP** | 8.3+ (8.1+ minimum) |
| **Web Server** | Apache2 with mod_rewrite |
| **PHP Extensions** | curl, openssl, mbstring, bcmath, gd, xml, zip, intl |
| **yt-dlp** | Auto-installed. Video downloads from YouTube, Vimeo, TikTok, 1000+ sites |
| **ffmpeg** | Auto-installed. Required for yt-dlp video+audio merging |
| **Deno** | Auto-installed. Required by yt-dlp for YouTube JS challenge solving |

> **Important:** `php-bcmath` is required for Mega.nz premium account login (RSA key decryption). Without it, Mega downloads fall back to anonymous mode with strict quota limits.

#### Features

- 📹 **yt-dlp Integration** — Download from YouTube, Vimeo, TikTok, Twitter/X, Instagram, Reddit, SoundCloud, and 1000+ sites with format/quality selector, real-time terminal progress, per-user cookie support
- 🎨 **Two Templates** — "Flavor" (modern dark/light) and "PlugMod" (classic)
- 🔌 **145+ Registered Plugins** — Mega.nz, Google Drive, MediaFire, Rapidgator, and many more
- 🔐 **File Ownership** — Cookie-based isolation, users only see their own files
- 📊 **Download Tracking** — Real-time progress, pending downloads tab
- ⚡ **Auto Transload** — Batch download multiple links
- 🧹 **Auto Cleanup** — Configurable auto-delete timer + 99% storage failsafe
- 🛡️ **Mega Queue** — Only 1 Mega download at a time to prevent account abuse
- 🍪 **Cookie Support** — Users can paste browser cookies (saved in localStorage) for YouTube login-required videos
- 📤 **Local Upload** — Drag & drop files from your computer to the server; uploaded files get shareable links just like transloaded ones
- 🔁 **Download Deduplication** — Identical links submitted at the same time download once and are shared between all requesting users
- 📈 **Live Dashboard** — Real-time speed, ETA and per-chunk progress via SSE (with silent polling fallback)
- 🩹 **Self-Healing Downloads** — Disk-fit pre-check before starting, wrong file extensions auto-corrected via magic bytes
- 🛡️ **CSRF Protection** — Every form (transload, auto-download/upload, local upload, file options, admin) requires a security token
- 👥 **Visitor Badge** — Self-hosted visitor counter (today / total), no third-party service
- 🛠️ **Admin Panel** — Server status, clear files, edit config/accounts, update yt-dlp/RAR/Deno, shell runner, activity logs

#### yt-dlp Video Downloader

The built-in yt-dlp plugin supports downloading from **1000+ websites** including:

| Category | Sites |
|---|---|
| **Video** | YouTube, Vimeo, Twitch, TikTok, Instagram, Twitter/X, Reddit, Dailymotion, Streamable, BitChute, Rumble, Odysee, Bilibili, NicoVideo, Crunchyroll |
| **Music** | SoundCloud, Bandcamp, Mixcloud |
| **News** | CNN, BBC, CBS News, Washington Post, NY Times, The Guardian |
| **Education** | TED, Udemy, Coursera |
| **Other** | Archive.org, 9GAG, PeerTube, and hundreds more |

**Features:**
- Format/quality selector with thumbnail, title, duration, file sizes
- Best quality auto-merge (video + audio → MP4)
- Real-time terminal-style download progress
- Per-user cookies (each user uses their own YouTube login, stored in browser localStorage)
- Admin panel: one-click update yt-dlp, install Deno, manage cookies

**Cookie Authentication for YouTube:**

YouTube often requires login to download videos (bot protection). Users need to provide browser cookies:

**How to get cookies:**
1. Install the **[Get cookies.txt LOCALLY](https://chromewebstore.google.com/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc)** browser extension (Chrome/Edge)
2. Log into [YouTube](https://www.youtube.com) in your browser
3. Click the extension icon on youtube.com → Click **Export**
4. A `cookies.txt` file downloads — this contains your YouTube session

**Where to paste cookies (3 options):**
1. **Main form** → Check "Additional Cookie Value" → Paste into the **cookies.txt textarea** → Click Transload (cookies are saved in your browser's localStorage and auto-loaded every visit)
2. **Format selector page** → Expand "🍪 Login Required?" → Paste cookies → Click "Save Cookies & Reload"
3. **Admin panel** → yt-dlp card → Browser Cookies section (sets global fallback for all users)

> **Note:** Cookies are per-user — each user's cookies are written to a temporary file that is deleted after the download. They are never shared between users. YouTube cookies expire periodically, so re-export if downloads start failing.

**Server requirements for YouTube:**
- **Deno** JavaScript runtime — required by yt-dlp to solve YouTube's bot challenges. Install via Admin Panel → yt-dlp card → "⚡ Install Deno" button, or: `curl -fsSL https://deno.land/install.sh | sh && sudo cp ~/.deno/bin/deno /usr/local/bin/deno`
- **ffmpeg** — required to merge separate video+audio streams into MP4. Install via: `sudo apt install ffmpeg`

#### Local Upload

Upload files from your computer directly to the server — they land in the same `files/` folder as transloaded downloads and get the same shareable links, auto-delete timer, and ownership isolation.

- **Drag & drop** or click to choose a file in the Local Upload tab
- **File-type policy** (admin-configurable): by default **all file types are accepted**; optionally restrict to a whitelist of extensions
- **Secure by design** — uploaded files are stored as inert data and can never execute on the server:
  - `files/.htaccess` disables the PHP engine and denies script extensions (php, phtml, phar, cgi, pl, py, sh, asp, aspx, shtml, jsp)
  - Oversized uploads return a clear JSON error naming the PHP limit instead of a cryptic failure
- Admin panel → Local Upload group controls the policy; the PHP Upload Limits card shows the effective `upload_max_filesize` / `post_max_size` with override guidance (`.htaccess` for mod_php, `.user.ini` for FPM/CGI)

#### Download Deduplication

If two users transload the same link at (nearly) the same time, the second request joins the first instead of downloading twice — it waits for the leader's download to finish, then serves the same file under its own ownership entry. Link matching is normalized (scheme, credentials, query string, and trailing slash insensitive). Any failure falls back silently to a normal download.

#### Live Download Dashboard

The Pending Downloads tab streams real-time progress over SSE (with automatic fallback to 3s polling):

- Live **speed** (smoothed, with sparkline) and **ETA** countdown
- **Per-chunk visualization** — an 8-chunk bar fills as parallel chunks complete, with per-chunk tooltips
- Server stays stateless — speed/ETA are computed client-side

#### Security

- **CSRF protection** on every form: transload, auto-download/upload, local upload, all 14 file option forms (delete/rename/zip/rar/…), and all admin actions — POSTs without a valid token are rejected
- **File ownership** via per-browser cookie token; users only see their own files (admin bypasses)
- **Uploaded files are never executable** (PHP engine off in `files/`)
- **Admin raw editors** reject injected PHP tags; the .htaccess editor blocks edits that would disable the `files/` protection
- **Credentials** (premium accounts) stored Blowfish-encrypted

#### PHP Extensions Install (if missing)

```bash
sudo apt install php8.3-bcmath php8.3-curl php8.3-mbstring -y
sudo systemctl restart apache2
```

#### Premium Accounts

Edit `configs/accounts.php` to add premium credentials for file hosting services:

```php
$premium_acc["mega_co_nz"] = array('user' => 'email@example.com', 'pass' => 'password');
$premium_acc["rapidgator_net"] = array('user' => 'username', 'pass' => 'password');
```

Supported premium services: Mega.nz, 1Fichier, 4Shared, Alfafile, FileFactory, Filejoker, Keep2Share, Mediafire, Nitroflare, Rapidgator, Turbobit, and more.

#### Admin Panel

Access at `/admin` (default credentials: admin/admin — change in `configs/config.php`).

**Features:**
- 📊 Server status (files, disk space, CPU with live load bar + sparkline)
- ⚙️ Edit configuration & premium accounts (schema-driven settings form with typed inputs, plus a raw editor fallback)
- 📹 yt-dlp management (update binary, install Deno, manage cookies)
- 📦 RAR binary management (update to latest)
- 🔄 One-click GitHub update (preserves configs) — whole code or hosts/plugins-only mode
- 📤 Local Upload settings (accept-all toggle or file-type whitelist, PHP upload limits viewer)
- 📄 .htaccess editor (with injection + AllowOverride guards and upload-limit snippets)
- ⚡ Shell command runner
- 📋 Activity logs with filtering & search

#### Templates

Switch templates in `configs/config.php`:
```php
'template_used' => 'flavor',   // Modern UI (default)
'template_used' => 'plugmod',  // Classic UI
```

#### Actively Tested & Fixed Plugins (2026)

✅ **yt-dlp Universal** — YouTube, Vimeo, TikTok, Twitter/X, Instagram, Reddit, 1000+ sites  
✅ **Pornhub** — Full HLS stream support with quality selector  
✅ **Mega.nz** — Premium account support, download queue management  
✅ **Transfer.it** — Direct file downloads  
✅ **MediaFire** — Direct downloads without captcha

*Note: Other plugins may work but haven't been actively tested. Report issues via pull requests.*

#### Update Existing Installation

```bash
cd /var/www/html
cp configs/accounts.php configs/accounts.php.bak
cp configs/config.php configs/config.php.bak
git fetch origin main && git reset --hard origin/main
cp configs/accounts.php.bak configs/accounts.php
cp configs/config.php.bak configs/config.php
rm -f files/mega_dl.php
chmod -R 777 files/ configs/
```

Or use the Admin Panel at `/admin` → "Update from GitHub" button.

---

### Credits

- **Maintained by** — [Abdulhossein](https://github.com/Abdulhossein).
- **Special thanks** — [PBhadoo/Rapidleech](https://github.com/PBhadoo/Rapidleech) for the modernized codebase this fork builds on.
- **Original RapidLeech** — Based on the work by [Th3-822](https://github.com/Th3-822/rapidleech).

### Copyright

Copyright © 2024-2026 [Abdulhossein](https://github.com/Abdulhossein). All rights reserved.

This project is provided as-is for personal and educational use. Redistribution and modification are permitted provided that credit is given to the original authors and contributors listed above.

---

### Make pull requests for suchanges or fixes.
