AsterBunny
==========

Simple PHP based CLI tool to publish Asterisk AMI events to a Rabbit Message Queue

Install
=======

Clone the repo:

```bash
git clone https://github.com/opensoft/AsterBunny.git
```

Now install dependencies with composer

```bash
curl http://getcomposer.org/installer | php
php composer.phar install
```

Usage
=====

The CLI tool is located at `bin/asterbunny` and has a lot of configuration options relating to specifying hostnames, ports,
usernames, and passwords for Asterisk connections and RabbitMQ servers.

It's recommended to run the following to learn the configuration set:

```bash
./bin/asterbunny listen --help
```

The file src/Opensoft/AsterBunny/Resources/Config/settings.ini.dist holds all the default values for configuration.

Create a copy of the file

```cp src/Opensoft/AsterBunny/Resources/Config/settings.ini.dist src/Opensoft/AsterBunny/Resources/Config/settings.ini```

And edit the newly created settings.ini file accordingly.

It is also recommended that you create a username and password in Gmail for the application instance. If you would prefer to host your own smtp server, then change the host, username, password, and port parameters in the configuration file.

Message Sending
===============

All asterisk events emitted by the Asterisk AMI interface are encoded as JSON and then sent to a configured RabbitMQ server.

Specifically, http://www.voip-info.org/wiki/view/asterisk+manager+events are:

  1. Keys are converted to lowercase
  2. The message is converted to JSON

And then submitted to the configured exchange with the `fanout` exchange type

#### Example

```json
{
    "event": "Agentlogoff",
    "agent": "<agent>",
    "logintime": "<logintime>",
    "uniqueid": "<uniqueid>"
}
```

Message headers are as follows:

 * `timestamp` => The unix timestamp of when the event occured as seen by AsterBunny
 * `content_type` => `application\json`
 * `delivery_mode` => `2` - Indicates that the message should be persisted by RabbitMQ

Logging
=======

A default log4php configuration file is included with this tool.

    $ cp log4php.dist.xml log4php.xml

Configure logging by editing the file according to instructions found [here](http://logging.apache.org/log4php/docs/configuration.html)

Requirements
============

 * PHP 5.3
 * RabbitMQ
 * Asterisk AMI

License
=======

AsterBunny is licensed under the MIT License - see the LICENSE file for details

Acknowledgments
===============

 * http://github.com/symfony/console
 * http://github.com/videlalvaro/php-amqplib
 * http://github.com/marcelog/pami
