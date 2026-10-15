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
    && echo "ServerName https://fabrioza.com" >> /etc/apache2/apache2.conf \
    && echo "UseCanonicalName On" >> /etc/apache2/apache2.conf
# ServerName with https scheme + UseCanonicalName On makes Apache build ALL
# self-referential redirect Locations as https://fabrioza.com/... even though
# SSL terminates at the fronting nginx. Without this, every 301 pointed at
# http:// first and cost a second hop through the https redirect.

# CRM data dir (named volume mounts here; ownership seeded from the image)
RUN mkdir -p /var/data && chown www-data:www-data /var/data

# Lead attachments arrive base64-encoded inside the JSON body, so the binding
# limit is post_max_size, not upload_max_filesize. Base64 is ~4/3 of the raw
# bytes: a 10 MB file is ~13.4 MB on the wire, and PHP's default post_max_size
# of 8M would reject it with an empty $_POST and no useful error.
RUN {       echo 'post_max_size = 24M';       echo 'upload_max_filesize = 12M';       echo 'memory_limit = 128M';       echo 'max_input_time = 120';     } > /usr/local/etc/php/conf.d/fabrioza-uploads.ini

COPY dist/ /var/www/html/

EXPOSE 80

# NOTE: PHP mail() has no MTA inside this image. The contact form
# (api/send-email.php) will not deliver email until it is switched to SMTP
# (e.g. PHPMailer with the domain mailbox credentials) or an msmtp relay is
# added. See CLAUDE.md "Known gaps".
