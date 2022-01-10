# smysqlin - simple mysql install

Easily install and manage updates to a MySQL database

## requirements

- PHP >= 5.3
- MySQL >= 5.2
- Git (for installation)
- GNU Make utility (for installation)
- bash 3.2.48 (OS X 10.8.2)
- an empty/existing MySQL database
- shell access
 
## installation

1. Download the archive and extract (downloaded archive name maybe different)

	```
	- $> wget https://bitbucket.org/cyriltata/smysqlin/get/v1.0-1.tar.gz
	- $> tar -xzvf cyriltata-smysqlin-{commit-hash}.tar.gz
	```

2. Change into the directory you just extracted (directory name maybe different)

	```
	- $> cd cyriltata-smysqlin-{commit-hash}
	```

3. Install using 

	```
	- $> make install
	```

## Adding projects to `config`

In order to be able to use `smysqlin` in a project, you need to create a config file (which is an ini file) 
in the config directory located at `/etc/smysqlin`, **or** alternatively specify the configuration file when running the program. 

See `/etc/smysqlin/example.ini` for an example configuration file


## Usage

** Example Usage **

Assume you have a **well defined** configuration file in the `/etc/smysqlin/formr.ini`. Then you can run `smysqlin formr`.

Alternatively you can pass a configuration file with -c option **and** a schema (with -s) to read with from the configuration file as
`smysqlin -c /path/to/config.ini -s project`

Use	`smysqlin -h` to see all avaliable options
