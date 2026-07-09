# FABRIOZA - VPS deployment image
# Mirrors the locally tested container exactly (126/126 URL checks passed):
# Apache + PHP so .htaccess routing AND /api/send-email.php + indexnow.php work.
FROM php:8.3-apache

# rewrite  -> all routing/301s in dist/.htaccess
# headers  -> security headers
# expires  -> browser caching
# deflate  -> gzip compression
RUN a2enmod rewrite headers expires deflate \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName fabrioza.com" >> /etc/apache2/apache2.conf

COPY dist/ /var/www/html/

EXPOSE 80

# NOTE: PHP mail() has no MTA inside this image. The contact form
# (api/send-email.php) will not deliver email until it is switched to SMTP
# (e.g. PHPMailer with the domain mailbox credentials) or an msmtp relay is
# added. See CLAUDE.md "Known gaps".
