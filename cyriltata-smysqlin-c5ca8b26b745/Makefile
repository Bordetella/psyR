# Make file for installing Simple Mysql install
# [smysqlin]

# Define Vars

LINK=$(SMYSQLIN_ROOT)/usr/local/bin/smysqlin
CONFIG_DIR=$(SMYSQLIN_ROOT)/etc/smysqlin
LOG_DIR=$(SMYSQLIN_ROOT)/var/log/smysqlin
INSTALL_DIR=$(SMYSQLIN_ROOT)/usr/share/smysqlin
GIT_REPO=https://bitbucket.org/cyriltata/smysqlin.git
GIT=$(shell echo "git --git-dir=$(INSTALL_DIR)/.git --work-tree=$(INSTALL_DIR)")

define DONE

smysqlin has been installed successfully. See $(CONFIG_DIR)/example.ini for an example configuration file;
User command 'smysqlin -h' to see options

endef
export DONE

all: install

install: install_files install_dependencies unlink link clean
	@echo "$$DONE"

unlink:
	@$(shell [ -L "$(LINK)" ] && unlink $(LINK))

link:
	@[ ! -L "$(LINK)" ] && ln -s $(INSTALL_DIR)/smysqlin.php $(LINK)
	chmod 0755 $(LINK) 

install_files:
	@echo "Installing files .....";

	@install -d -m 0744 $(INSTALL_DIR)

	$(GIT) init
	$(GIT) remote add origin $(GIT_REPO)
	$(GIT) pull origin master

	@install -d -m 0774 $(CONFIG_DIR)
	@install -d -m 0744 $(LOG_DIR)

	@echo "Done."

install_dependencies:
	@echo "Installing dependencies ....."
	@chmod 0755 $(INSTALL_DIR)/smysqlin.php
	@install -D -m 0644 $(INSTALL_DIR)/example.ini $(CONFIG_DIR)/example.ini
	@echo "Done"

uninstall: unlink
	@echo "Uninstalling config files, log directory and app files .....";
	@rm -rf $(CONFIG_DIR) $(LOG_DIR) $(INSTALL_DIR)
	@echo "Done."

clear_example:
	@[ -f $(CONFIG_DIR)/example.ini ] && rm $(CONFIG_DIR)/example.ini

update: clear_example update_files install_dependencies clean
	@echo "Updating...."
	@echo "$$DONE"

update_files:
	$(GIT) reset --hard
	$(GIT) pull origin master

clean:
	@echo "Cleanup complete..."
