            git status
            git stash
            git pull origin master
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
	    $(which php) artisan migrate --force
	    $(which php) artisan optimize:clear 
            sudo supervisorctl restart all
