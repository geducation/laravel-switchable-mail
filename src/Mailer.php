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
     * Send a new message using a view through specified driver.
     *
     * @param  string $driver
     * @param  string|array  $view
     * @param  array  $data
     * @param  \Closure|string  $callback
     * @return void
     */
    public function sendThrough($driver, $view, array $data, $callback)
    {
        // First we need to parse the view, which could either be a string or an array
        // containing both an HTML and plain text versions of the view which should
        // be used when sending an e-mail. We will extract both of them out here.
        list($view, $plain, $raw) = $this->parseView($view);

        $data['message'] = $message = $this->createMessage();

        // Once we have retrieved the view content for the e-mail we will set the body
        // of this message using the HTML type, which will provide a simple wrapper
        // to creating view based emails that are able to receive arrays of data.
        $this->addContent($message, $view, $plain, $raw, $data);

        $this->callMessageBuilder($callback, $message);

        if (isset($this->to['address'])) {
            $message->to($this->to['address'], $this->to['name'], true);
        }

        $driver = $this->copyDriverConfig($driver);

        return $this->sendSwiftMessage($message->getSwiftMessage(), $driver);
    }

    /**
     * Send a Swift Message instance.
     *
     * @param  \Swift_Message  $message
     * @param string $driver Driver name for sending
     * @return void
     */
    protected function sendSwiftMessage($message, $driver = null)
    {
        if ($this->events) {
            $this->events->fire(new MessageSending($message));
        }

        if (!$driver)
            $driver = MailDriver::forMessage($message);

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
     * Copy configuration from config/switchable-mail.php to driver-specific place
     *
     * @param string $name Driver name
     *
     * @return string The REAL driver name
     */
    private function copyDriverConfig($name)
    {
        $config = config("switchable-mail.{$name}");

        $real_driver = null;

        if (empty($config)) {
            Log::error("[SwitchableMail] Cannot send through $name. Configuration for it not found in config/switchable-mail.php. Using default mailer");
        }
        elseif (array_key_exists('driver', $config))
        {
            $real_driver = strtolower($config['driver']);

            // copy configuration block to specified driver config
            // available Laravel mailers can be found at src/Illuminate/Mail/TransportManager.php
            switch ($real_driver)
            {
                case 'smtp':
                    config(['mail' => array_merge(config('mail'), $config)]);
                    break;

                case 'mailgun':
                    config(['services.mailgun' => array_merge(config('services.mailgun'), $config)]);
                    break;

                default:
                    Log::error("Driver {$this->driver} is not implemented in laravel-switchable-mail!");
                    $real_driver = null;
            }

            if ($real_driver) {
                // resetting driver instance, if we change driver
                $this->swiftManager->resetTransportManager();
            }
        }

        return $real_driver;
    }
}
