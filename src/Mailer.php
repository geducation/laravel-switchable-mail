<?php

namespace KVZ\Laravel\SwitchableMail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer as BaseMailer;
use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Mail\MailQueue as MailQueueContract;
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
     * Send a new message using a view.
     *
     * @param  string|array  $view
     * @param  array  $data
     * @param  \Closure|string  $callback
     * @return void
     */
    public function send($view, array $data = array(), $callback = null)
    {
        return $this->sendThrough('default', $view, $data, $callback);
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
        //if ($view instanceof MailableContract) {
        //    return $this->sendMailable($view);
        //}

        // First we need to parse the view, which could either be a string or an array
        // containing both an HTML and plain text versions of the view which should
        // be used when sending an e-mail. We will extract both of them out here.
        list($view, $plain, $raw) = $this->parseView($view);

        $data['message'] = $message = $this->createMessage();

        // Once we have retrieved the view content for the e-mail we will set the body
        // of this message using the HTML type, which will provide a simple wrapper
        // to creating view based emails that are able to receive arrays of data.
        $this->addContent($message, $view, $plain, $raw, $data);

        call_user_func($callback, $message);

        // If a global "to" address has been set, we will set that address on the mail
        // message. This is primarily useful during local development in which each
        // message should be delivered into a single mail address for inspection.
        if (isset($this->to['address'])) {
            $this->setGlobalTo($message);
        }

        //if (isset($this->to['address'])) {
        //    $message->to($this->to['address'], $this->to['name'], true);
        //}

        if ($driver === 'default')
        {
            // special value. Restore original driver
            $driver = $this->swiftManager->getDefaultDriver();
            $this->restoreDriverConfig($driver);
        }
        else
            $driver = $this->setDriverConfig($driver);

        $this->sendSwiftMessage($message->getSwiftMessage(), $driver);

        $this->dispatchSentEvent($message);
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
     * Return a configuration section for a driver
     *
     * @param string $driver
     * @return string|null
     */
    private function getConfigKeyForDriver($driver)
    {
        $driver = strtolower($driver);

        // available Laravel mailers can be found at src/Illuminate/Mail/TransportManager.php
        switch ($driver)
        {
            case 'smtp':
                return 'mail';

            case 'mailgun':
                return 'services.mailgun';

            default:
                Log::error("Driver {$driver} is not implemented in laravel-switchable-mail!");
                return null;
        }
    }

    /**
     * Copy configuration from config/switchable-mail.php to driver-specific place
     *
     * @param string $name Driver name
     *
     * @return string The REAL driver name
     */
    private function setDriverConfig($name)
    {
        $config = config("switchable-mail.{$name}");

        $real_driver = null;

        if (empty($config)) {
            Log::error("[SwitchableMail] Cannot send through $name. Configuration for it not found in config/switchable-mail.php. Using default mailer");
        }
        elseif (array_key_exists('driver', $config))
        {
            $real_driver = $config['driver'];

            $config_key = $this->getConfigKeyForDriver($real_driver);

            if ($config_key) {
                // if we set new driver, then save old config, set new config and reset transport object
                $old_config = config($config_key);

                if (array_key_exists('saved', $old_config))
                    unset($old_config['saved']);

                // save old config
                $old_config['saved'] = $old_config;

                config([$config_key => array_merge($old_config, $config)]);

                // resetting driver instance to apply new configuration
                $this->swiftManager->resetMailer($real_driver);
                $this->swiftManager->resetTransportManager();
            }
        }

        return $real_driver;
    }

    /**
     * Restore configuration of specified driver
     *
     * @param string $name Driver name
     *
     */
    private function restoreDriverConfig($name)
    {
        $config_key = $this->getConfigKeyForDriver($name);

        $config = config($config_key);
        if (!$config_key || empty($config))
        {
            Log::error("[SwitchableMail] Cannot restore default mail configuration for driver $name! It's a bug!");
            return;
        }

        // change config only if we saved it previously
        if (array_key_exists('saved', $config))
            config([$config_key => array_merge($config, $config['saved'])]);

        // resetting driver instance to apply new configuration
        $this->swiftManager->resetMailer($name);
        $this->swiftManager->resetTransportManager();
    }
}
