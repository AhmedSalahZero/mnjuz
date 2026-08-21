            git status
            git stash
            git pull origin master
            chmod -R 775 storage
            chmod -R 775 bootstrap/cache
            chmod 777 -R storage/*
            echo ">>> Installing dependencies"
            $(which php) $(which composer) install --no-interaction --prefer-dist --optimize-autoloader

	    $(which php) artisan migrate --force
	    $(which php) artisan optimize:clear 
            sudo supervisorctl restart all
