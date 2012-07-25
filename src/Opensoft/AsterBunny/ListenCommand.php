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
use PAMI\Message\Response\ResponseMessage;
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

        $configFile  = __DIR__ . '/Resources/Config/settings.ini';

        if(!file_exists($configFile)) {
            throw new \Exception('Configuration file ( ' . $configFile . ') is missing!');
        }

        $optionDefaults = parse_ini_file($configFile, true);

        $this
            ->setName('listen')
            ->setDescription('Begin listening for AMI events and sending them to RabbitMQ')

            ->addOption('log4php-configuration', null, InputOption::VALUE_OPTIONAL, 'Asterisk host', $log4phpPath)

            ->addOption('ami-host', null, InputOption::VALUE_OPTIONAL, 'Asterisk host', $optionDefaults['asterisk']['ami-host'])
            ->addOption('ami-port', null, InputOption::VALUE_OPTIONAL, 'Asterisk port', (int) $optionDefaults['asterisk']['ami-port'])
            ->addOption('ami-username', null, InputOption::VALUE_OPTIONAL, 'Asterisk username', $optionDefaults['asterisk']['ami-username'])
            ->addOption('ami-password', null, InputOption::VALUE_OPTIONAL, 'Asterisk secret', $optionDefaults['asterisk']['ami-password'])
            ->addOption('ami-connect-timeout', null, InputOption::VALUE_OPTIONAL, 'Asterisk connect timeout', (int) $optionDefaults['asterisk']['ami-connect-timeout'])
            ->addOption('ami-read-timeout', null, InputOption::VALUE_OPTIONAL, 'Asterisk read timeout', (int) $optionDefaults['asterisk']['ami-read-timeout'])

            ->addOption('rabbit-host', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ host', $optionDefaults['rabbitmq']['rabbit-host'])
            ->addOption('rabbit-port', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ port', (int) $optionDefaults['rabbitmq']['rabbit-port'])
            ->addOption('rabbit-username', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ username', $optionDefaults['rabbitmq']['rabbit-username'])
            ->addOption('rabbit-password', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ password', $optionDefaults['rabbitmq']['rabbit-password'])
            ->addOption('rabbit-vhost', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ vhost', $optionDefaults['rabbitmq']['rabbit-vhost'])

            ->addOption('rabbit-exchange-name', null, InputOption::VALUE_OPTIONAL, 'RabbitMQ exchange name', $optionDefaults['rabbitmq']['rabbit-exchange-name'])

            ->addOption('notify', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'List of people to notify on errors', $optionDefaults['reporting'])

        ->setHelp("The <info>listen</info> command listens to a configured Asterisk server.");
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
        try {
            $pamiClient->open();
        } catch(\Exception $e) {
            $this->sendNotification($input->getOption('notify'), $e, 'Failed opening asterisk connection');

            throw new \Exception($e->getMessage());
        }
        $output->writeln('<info>done</info>');


        $output->write("<comment>Opening rabbitmq connection... </comment>");

        try {
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
        } catch (\Exception $e) {
            $this->sendNotification($input->getOption('notify'), $e, 'Failed opening rabbitmq connection');
            
            throw new \Exception($e->getMessage());
        }

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
                try {
                    usleep(1000); // 1ms delay

                    $i++;

                    if ($i == 10000) { // show some feedback every 10000 iterations or so, roughly every 10 seconds if nothing is processed
                        $counter++;
                        $output->writeln(sprintf(" >> <comment>Waiting for events... [%d seconds]</comment> <info>Ping...</info>", $counter * 10));
                        $i = 0;

                        // for every 10 seconds that go by, send a ping/pong event to the asterisk server
                        // if send times out, it'll throw an exception, which will end this script... supervisor should restart
                        /** @var ResponseMessage $pong  */
                        $pong = $pamiClient->send(new \PAMI\Message\Action\PingAction());

                        if ('Success' == $pong->getKey('response')) {
                            // Send the pong event onto RabbitMQ.  This has a faux keep-alive effect on the RabbitMQ connection
                            $msg = new AMQPMessage(
                                json_encode($pong->getKeys()),
                                array(
                                    'content_type' => 'application/json',
                                    'timestamp' => time(),
                                    'delivery_mode' => 2
                                )
                            );
                            $ch->basic_publish($msg, $exchange);
                            $output->writeln(sprintf(" >> <comment>[%s] [%s bytes]</comment> <info>%s</info>", date('Y-m-d G:i:s') . substr((string)microtime(), 1, 8), memory_get_usage(), 'Pong'));
                        }
                    }

                    $pamiClient->process();
                } catch (\Exception $e) {

                    // try to close any connections
                    $closer(false);
                    
                    // send notification...
                    $this->sendNotification($input->getOption('notify'), $e, 'Failed reading from asterisk server');

                    // rethrow the exception
                    throw $e;
                }
            }
        }

        $closer(false);

        return 0;
    }
    
    protected function sendNotification(array $notifyList, \Exception $exception, $message = null)
    {
        $noReply = array_shift($notifyList);
        foreach ($notifyList as $person => $email)
        {
            $message = \Swift_Message::newInstance()
                ->setSubject('Errors from Asterbunny')
                ->setFrom($noReply)
                ->setTo($email)
                ->setBody('Asterbunny has failed. Message: ' . PHP_EOL . $message . PHP_EOL . 'Exception: ' . $exception->getMessage());
        }
    }

}
