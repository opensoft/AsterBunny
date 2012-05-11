AsterBunny
==========

Simple PHP based CLI tool to publish Asterisk AMI events to a Rabbit Message Queue

Install
=======

Clone the repo:

   $ git clone https://github.com/opensoft/AsterBunny.git

Now install dependencies with composer

    $ curl http://getcomposer.org/installer | php
    $ php composer.phar install


Usage
=====

The CLI tool is located at `bin/listen` and has a lot of configuration options relating to specifying hostnames, ports,
usernames, and passwords for Asterisk connections and RabbitMQ servers.

It's recommended to run the following to learn the configuration set, and defaults

    $ ./bin/asterbunny listen --help

Logging
=======

A default log4php configuration file is included with this tool.

    $ cp log4php.xml.dist log4php.xml

Configure logging by editing the file according to instructions found [here](http://logging.apache.org/log4php/docs/configuration.html)

Requirements
============

 * PHP 5.3
 * RabbitMQ
 * Asterisk AMI

License
=======

Composer is licensed under the MIT License - see the LICENSE file for details

Acknowledgments
===============

 * http://github.com/symfony/console
 * http://github.com/videlalvaro/php-amqplib
 * http://github.com/marcelog/pami
