<?php
/*
 * This file is part of AsterBunny
 *
 * Copyright (c) 2012 Opensoft (http://opensoftdev.com)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Opensoft\AsterBunny;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use PAMI\Client\Impl\ClientImpl;
use PAMI\Message\Event\EventMessage;
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author Richard Fullmer <richard.fullmer@opensoftdev.com>
 */
class ListenCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $log4phpPath = is_file(__DIR__.'/../../../log4php.xml')
            ? realpath(__DIR__.'/../../../log4php.xml')
            : realpath(__DIR__.'/../../../log4php.dist.xml');

        $this
            ->setName('listen')
            ->setDescription('Begin listening for AMI events and sending them to RabbitMQ')

            ->addOption('log4php-configuration', null, InputOption::VALUE_OPTIONAL, 'Asterisk host', $log4phpPath)

            ->addOption('ami-host', null, InputOption::VALUE_OPTIONAL, 'Asterisk host', 'localhost')
            ->addOption('ami-port', null, InputOption::VALUE_OPTIONAL, 'Asterisk port', 5038)
            ->addOption('ami-username', null, InputOption::VALUE_OPTIONAL, 'Asterisk username', 'admin')
            ->addOption('ami-password', null, InputOption::VALUE_OPTIONAL, 'Asterisk secret', 'mysecret')
            ->addOption('ami-connect-timeout', null, InputOption::VALUE_OPTIONAL, 'Asterisk connect timeout', 10000)
            ->addOption('ami-read-timeout', null, InputOption::VALUE_OPTIONAL, 'Asterisk read timeout', 10000)


            ->addOption('rabbit-host', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ host', 'localhost')
            ->addOption('rabbit-port', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ port', 5672)
            ->addOption('rabbit-username', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ username', 'guest')
            ->addOption('rabbit-password', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ password', 'guest')
            ->addOption('rabbit-vhost', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ vhost', '/')


            ->addOption('rabbit-exchange-name', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ exchange name', 'asterbunny.events')

            ->setHelp(<<<EOF

The <info>listen</info> command listens to a configured Asterisk server.

EOF
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pamiClientOptions = array(
            'log4php.properties' => $input->getOption('log4php-configuration'),
            'host' => $input->getOption('ami-host'),
            'scheme' => 'tcp://',
            'port' => $input->getOption('ami-port'),
            'username' => $input->getOption('ami-username'),
            'secret' => $input->getOption('ami-password'),
            'connect_timeout' => $input->getOption('ami-connect-timeout'),
            'read_timeout' => $input->getOption('ami-read-timeout')
        );

        $pamiClient = new ClientImpl($pamiClientOptions);

        $output->write("<comment>Opening asterisk connection... </comment>");
        $pamiClient->open();
        $output->writeln('<info>done</info>');


        $output->write("<comment>Opening rabbitmq connection... </comment>");
        $amqpConn = new AMQPConnection(
            $input->getOption('rabbit-host'),
            $input->getOption('rabbit-port'),
            $input->getOption('rabbit-username'),
            $input->getOption('rabbit-password'),
            $input->getOption('rabbit-vhost')
        );
        $ch = $amqpConn->channel();
        $exchange = $input->getOption('rabbit-exchange-name');
        $ch->exchange_declare($exchange, 'fanout', false, true, false);

        $output->writeln('<info>done</info>');

        $i = 0;
        $counter = 0;

        $pamiClient->registerEventListener(function(EventMessage $event) use ($output, $ch, $exchange, &$counter, &$i) {
            // Send to RabbitMQ

            $msg = new AMQPMessage(
                json_encode($event->getKeys()),
                array(
                    'content_type' => 'application/json',
                    'timestamp' => time(),
                    'delivery_mode' => 2
                )
            );
            $ch->basic_publish($msg, $exchange);

            gc_collect_cycles();

            $output->writeln(sprintf(" >> <comment>[%s] [%s bytes]</comment> <info>%s</info>", date('Y-m-d G:i:s') . substr((string)microtime(), 1, 8), memory_get_usage(), $event->getName()));
            $counter = 0;
            $i = 0;
        });


        $closer = function($autoExit = true) use ($pamiClient, $amqpConn, $ch, $output) {
            $output->writeln('');
            $output->write('<comment>Closing ami connection... </comment>');
            $pamiClient->close(); // send logoff and close the connection.
            $output->writeln("<info>done</info>");

            $output->write('<comment>Closing rabbitmq connection... </comment>');
            $ch->close();
            $amqpConn->close();
            $output->writeln("<info>done</info>");

            if ($autoExit) {
                exit(0);
            }
        };

        declare(ticks = 1) {
            pcntl_signal(\SIGINT, $closer);
            pcntl_signal(\SIGTERM, $closer);

            while (true) {
                usleep(1000); // 1ms delay

                $i++;

                if ($i == 10000) { // show some feedback every 10000 iterations or so, roughly every 10 seconds if nothing is processed
                    $counter++;
                    $output->write(sprintf(" >> <comment>Waiting for events... [%d seconds]</comment> <info>Ping...</info>", $counter * 10));
                    $i = 0;

                    // for every 10 seconds that go by, send a ping/pong event to the asterisk server
                    // if send times out, it'll throw an exception, which will end this script... supervisor should restart
                    $pong = $pamiClient->send(new \PAMI\Message\Action\PingAction());

                    if ('Success' == $pong->getKey('response')) {
                        $output->writeln('<info>Pong</info>');
                    } else {
                        $output->writeln('<error>No pong...</error>');
                        print_r($pong);
                    }
                }

                $pamiClient->process();
            }
        }

        $closer(false);

        return 0;
    }
}
