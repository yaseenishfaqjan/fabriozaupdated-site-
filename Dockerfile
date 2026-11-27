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

# PHP sessions live on the SAME volume, not in the container's /tmp.
#
# Default save_path is /tmp, which is part of the container layer, so every
# `docker compose up -d --build` destroyed every session. In practice that
# logged the admin out mid-task and, if a login form was already open, the
# submit came back "Invalid CSRF token" - the token was fine, the session
# behind it had been thrown away by a deploy. Reported from the live site.
#
# gc_maxlifetime 1440 (24 minutes) was the second half of the same complaint:
# leave the login page open over a coffee and the token expires. 2 hours is
# the right order of magnitude for a CRM someone works in.
RUN {       echo 'session.save_path = "/var/data/sessions"';       echo 'session.gc_maxlifetime = 7200';     } > /usr/local/etc/php/conf.d/fabrioza-sessions.ini

# The directory itself is created by the ENTRYPOINT, not here. Docker seeds a
# volume from the image only when the volume is EMPTY, and production's
# /var/data already holds the database - so a RUN mkdir would never appear
# there and session_start() would fail for everyone. See docker-entrypoint.sh.
COPY docker-entrypoint.sh /usr/local/bin/fabrioza-entrypoint.sh
RUN chmod +x /usr/local/bin/fabrioza-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/fabrioza-entrypoint.sh"]
CMD ["apache2-foreground"]

# TWO upload paths, and they bind on different limits.
#
# The contact form posts attachments base64-encoded inside a JSON body, so its
# binding limit is post_max_size, not upload_max_filesize. Base64 is ~4/3 of
# the raw bytes, and PHP's default post_max_size of 8M rejected those with an
# empty $_POST and no useful error.
#
# The custom-fabric form posts multipart/form-data instead, because it accepts
# up to 5 tech packs at 20 MB each. As base64-in-JSON that is ~133 MB held in
# memory per request; as multipart PHP streams each file straight to a temp
# file on disk and memory stays flat. post_max_size has to clear the whole
# multipart body, upload_max_filesize caps one file.
#
# memory_limit is 256M as headroom, not because an upload needs it. Measured
# on the host before raising it: 11,956 MB total with 5,606 MB available
# across 56 containers.
RUN {       echo 'post_max_size = 110M';       echo 'upload_max_filesize = 20M';       echo 'memory_limit = 256M';       echo 'max_input_time = 300';       echo 'max_file_uploads = 20';     } > /usr/local/etc/php/conf.d/fabrioza-uploads.ini

COPY dist/ /var/www/html/

EXPOSE 80

# NOTE: PHP mail() has no MTA inside this image. The contact form
# (api/send-email.php) will not deliver email until it is switched to SMTP
# (e.g. PHPMailer with the domain mailbox credentials) or an msmtp relay is
# added. See CLAUDE.md "Known gaps".
