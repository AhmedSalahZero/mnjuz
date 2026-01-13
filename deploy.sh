            git status
            git stash
            git pull origin master
            /usr/local/bin/ea-php84 artisan optimize:clear
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
            /usr/local/bin/ea-php84 artisan migrate --force
    
            supervisord -c /etc/supervisord.conf
            supervisorctl restart all
