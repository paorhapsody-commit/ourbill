# OurBill — PHP + Apache สำหรับ deploy บน Render (Docker web service)
FROM php:8.2-apache

# ติดตั้ง extension ที่จำเป็น: mbstring (ใช้ mb_* ในโค้ด) + ca-certificates (curl https ไป Supabase/Google)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev ca-certificates \
    && docker-php-ext-install mbstring \
    && rm -rf /var/lib/apt/lists/*

# คัดลอกซอร์สโค้ดเข้า document root
COPY . /var/www/html/

# Render ส่งพอร์ตผ่านตัวแปร $PORT (ดีฟอลต์ 10000) — ให้ Apache ฟังพอร์ตนั้น
ENV PORT=10000
RUN sed -ri 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
CMD ["apache2-foreground"]
