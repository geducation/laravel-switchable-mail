<?php

namespace KVZ\Laravel\SwitchableMail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer as BaseMailer;
use Log;

class Mailer extends BaseMailer
{
    /**
     * The Swift Mailer Manager instance.
     *
     * @var \KVZ\Laravel\SwitchableMail\SwiftMailerManager
     */
    protected $swiftManager;

    /**
     * Driver name for sending
     *
     * @var string
     */
    protected $usingDriver;


    /**
     * Get the Swift Mailer Manager instance.
     *
     * @return \KVZ\Laravel\SwitchableMail\SwiftMailerManager
     */
    public function getSwiftMailerManager()
    {
        return $this->swiftManager;
    }

    /**
     * Set the Swift Mailer Manager instance.
     *
     * @param  \KVZ\Laravel\SwitchableMail\SwiftMailerManager  $manager
     * @return void
     */
    public function setSwiftMailerManager($manager)
    {
        $this->swiftManager = $manager;
    }

    /**
     * Send a Swift Message instance.
     *
     * @param  \Swift_Message  $message
     * @return void
     */
    protected function sendSwiftMessage($message)
    {
        if ($this->events) {
            $this->events->fire(new MessageSending($message));
        }

        $driver = MailDriver::forMessage($message);

        // override driver only if the driver name is provided through 'using'
        if (!empty($this->usingDriver))
            $driver = $this->usingDriver;

        $swift = $this->swiftManager->mailer($driver);

        try {
            return $swift->send($message, $this->failedRecipients);
        } finally {
            $this->forceReconnection($swift);
        }
    }

    /**
     * Force the transport to re-connect.
     *
     * This will prevent errors in daemon queue situations.
     *
     * @param  \Swift_Mailer  $swiftMailer
     * @return void
     */
    protected function forceReconnection($swiftMailer = null)
    {
        if (is_null($swiftMailer)) {
            $swiftMailer = $this->getSwiftMailer();
        }

        $swiftMailer->getTransport()->stop();
    }

    /**
     * Get the Swift Mailer instance.
     *
     * @return \Swift_Mailer
     */
    public function getSwiftMailer()
    {
        return $this->swiftManager->mailer();
    }

    /**
     * Set the Swift Mailer instance.
     *
     * @param  \Swift_Mailer  $swift
     * @return void
     */
    public function setSwiftMailer($swift)
    {
        $this->swiftManager->setDefaultMailer($swift);

        // Our $swift is managed by the SwiftMailerManager singleton,
        // so just let $this->swift go.
    }

    /**
     * Set the driver name for sending
     *
     * @param string $name the mail driver name (from config/switchable-mail.php)
     *
     */
    public function useDriver($name)
    {
        $config = config("switchable-mail.$name");

        if (!empty($config) && array_key_exists('driver', $config))
        {
            // set driver to use (one of Laravel implemented drivers: smtp, mandrill, mailgun, etc...)
            $this->usingDriver = $config['driver'];

            // copy configuration block to specified driver config
            // available Laravel mailers can be found at src/Illuminate/Mail/TransportManager.php
            switch ($this->usingDriver)
            {
                case 'smtp':
                    config(['mail' => $config]);
                    break;

                case 'mailgun':
                    config(['services.mailgun' => $config]);
                    break;

                default:
                    Log::error("Driver {$this->usingDriver} is not implemented in laravel-switchable-mail!");
            }
        }
    }
}
