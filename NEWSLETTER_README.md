# The Diagnostic — Weekly Newsletter System

## Overview
Automated weekly email newsletter for Business Intuitive clients.  
Styled as a newspaper × Breakfast at Tiffany's — dark editorial layout with Tiffany blue accents, serif masthead, gold highlights, and elegant typography.

## Architecture
- **Database**: SQLite (persisted via Docker volume `newsletter-data`)
- **Email delivery**: Resend API (`newsletter@businessintuitive.tech`)
- **Scheduling**: Cron inside Docker — runs every Monday at 8:00am Pacific
- **Admin panel**: `/api/newsletter-admin.php`
- **Booking CTA**: https://cal.com/businessintuitive/custom-web-app-creative-session

## Files
```
api/
  newsletter-db.php        # SQLite schema + DB helper
  newsletter-clients.php   # Client CRUD API
  newsletter-manage.php    # Newsletter content CRUD API
  newsletter-send.php      # Sends emails via Resend (cron + API)
  newsletter-admin.php     # Full admin panel UI
```

## Setup After Deploy

### 1. Resend — Add Sender Address
You need to add `newsletter@businessintuitive.tech` as an authorized sender in your Resend dashboard:
- Go to https://resend.com/domains
- Make sure `businessintuitive.tech` is verified
- The `newsletter@` address should work automatically if the domain is verified

### 2. Access the Admin Panel
```
https://businessintuitive.tech/api/newsletter-admin.php
```

### 3. Add Clients
Use the admin panel's "Clients" tab to add each client with:
- **Name** and **Email** (required)
- **Company**, **Website URL**, **Platform URL** (optional — used for personalized insights)

### 4. Compose a Newsletter
In the "Compose" tab, fill in:
- **Subject line** — the email subject
- **Hot News Headline** — the main editorial headline
- **Hot News Body** — the article content
- **Hot News Link** — optional "Read More" link
- **Client Insight** — personalized insight text (same for all clients, but their name/company/URLs are injected)
- **Extra Section** — optional bonus dispatch section
- Set status to **Scheduled** and pick a date/time, or leave as **Draft**

### 5. Sending
- **Manual send**: Click "Send" on any newsletter in the "Newsletters" tab
- **Test send**: Click "Test" to send a preview to any email address
- **Automatic**: Scheduled newsletters are sent by cron every Monday at 8am Pacific
- **Cron sends** all newsletters with `status = 'scheduled'` where `scheduled_at <= now`

### 6. Cron Schedule
Default: every Monday at 8:00am Pacific (`0 8 * * 1`).  
To change, edit the cron line in the Dockerfile:
```dockerfile
RUN echo '0 8 * * 1 cd /var/www/html && php ...' > /etc/crontabs/root
```

## Email Template Sections
1. **Masthead** — double-ruled Tiffany blue border, "The Diagnostic" in serif type, volume/issue/date
2. **Greeting** — "Dear [First Name]," in italic serif
3. **Breaking Insight** — gold label, large serif headline, body text
4. **Your Insight** — Tiffany label, personalized per-client with website/platform links
5. **Dispatch** — optional extra content section with gold label
6. **Booking CTA** — elegant card with "Book a Session" button
7. **Footer** — Tiffany double-rule, brand name, tagline

## API Endpoints

### Clients
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/newsletter-clients.php` | List all clients |
| GET | `/api/newsletter-clients.php?id=5` | Get single client |
| POST | `/api/newsletter-clients.php` | Create client |
| PUT | `/api/newsletter-clients.php` | Update client |
| DELETE | `/api/newsletter-clients.php?id=5` | Delete client |

### Newsletters
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/newsletter-manage.php` | List all newsletters |
| POST | `/api/newsletter-manage.php` | Create newsletter |
| PUT | `/api/newsletter-manage.php` | Update newsletter |
| DELETE | `/api/newsletter-manage.php?id=3` | Delete newsletter |

### Sending
| Method | Endpoint | Body |
|--------|----------|------|
| POST | `/api/newsletter-send.php` | `{ "newsletter_id": 5 }` — send to all active clients |
| POST | `/api/newsletter-send.php` | `{ "newsletter_id": 5, "test_email": "you@test.com" }` — test send |

## Security Notes
- The admin panel has **no authentication** — consider adding HTTP basic auth via nginx or a simple password check in `newsletter-admin.php` for production
- The `/data/` directory (SQLite DB) is blocked from public access via nginx
- API keys are hardcoded in `newsletter-send.php` — move to environment variables for better security
