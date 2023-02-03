; origin-src: deb/php-fpm/multiphp.tpl

[%domain%]
listen = /run/php/php%backend_version%xdbg-fpm-%domain%.sock
listen.owner = %user%
listen.group = www-data
listen.mode = 0660

user = %user%
group = %user%

pm = ondemand
pm.max_children = 8
pm.max_requests = 4000
pm.process_idle_timeout = 10s
pm.status_path = /status

php_admin_value[upload_tmp_dir] = /home/%user%/tmp
php_admin_value[session.save_path] = /home/%user%/tmp
;php_admin_value[open_basedir] = /home/%user%/.composer:/home/%user%/web/%domain%/public_html:/home/%user%/web/%domain%/private:/home/%user%/web/%domain%/public_shtml:/home/%user%/tmp:/tmp:/var/www/html:/bin:/usr/bin:/usr/local/bin:/usr/share:/opt
;php_admin_value[sendmail_path] = /usr/sbin/sendmail -t -i -f admin@%domain%

env[PATH] = /usr/local/bin:/usr/bin:/bin
env[TMP] = /home/%user%/tmp
env[TMPDIR] = /home/%user%/tmp
env[TEMP] = /home/%user%/tmp

; cg values
php_admin_value[open_basedir] = /home/%user%/.composer:/home/%user%/web/%domain%/public_html:/home/%user%/web/%domain%/private:/home/%user%/web/%domain%/public_shtml:/home/%user%/tmp:/tmp:/var/www/html:/bin:/usr/bin:/usr/local/bin:/usr/share:/opt:/usr/local/hestia/web/plugins
php_admin_value[post_max_size]          = 512M
php_admin_value[upload_max_filesize]    = 512M
php_admin_value[memory_limit]           = 512M
php_admin_value[max_execution_time]     = 300
php_admin_value[smtp_port]              = 1025
php_admin_value[sendmail_path]          = /usr/bin/catchmail --domain %domain%
php_admin_value[SMTP]                   = 127.0.0.1
php_admin_value[auto_prepend_file]      = /usr/local/hestia/web/plugins/code-gdn/src/prepend.php
