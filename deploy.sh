            git status
            git stash
            git pull origin master
            /usr/bin/php8.3 artisan optimize:clear
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
            /usr/bin/php8.3 artisan migrate --force
    
            supervisord -c /etc/supervisord.conf
            supervisorctl restart all
