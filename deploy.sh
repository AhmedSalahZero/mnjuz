            git status
            git stash
            git pull origin master
            /usr/bin/php8.3 artisan optimize:clear
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
	    $(which php) artisan migrate --force
	   sudo chmod 777 ./deploy
	  $(which php) artisan optimize:clear 
            sudo supervisorctl restart all
