# Makefile for Digital Vault v2 local development

.PHONY: shell cr uli status install

# 1. The "Work-Style" Shell (Drops you directly into the web folder)
shell:
	ddev ssh --dir /var/www/html/web

# 2. The "Quick" Cache Rebuild (No need to enter container or cd)
cr:
	ddev drush cr

# 3. The One-Time Login Link
uli:
	ddev drush uli

# 4. Check project status (URLs, Database info, etc.)
status:
	ddev describe

# 5. Open the site in your browser
up:
	ddev launch

# 6. Stop the project to save iMac battery/RAM
stop:
	ddev stop
