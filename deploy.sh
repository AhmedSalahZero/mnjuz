            git status
            git stash
            git pull origin master
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
	        php8.3 artisan migrate --force
	        php8.3 artisan optimize:clear 
            sudo supervisorctl restart all
