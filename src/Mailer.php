<?php
namespace Phwoolcon;

use Phalcon\Di;
use Phwoolcon\Queue;
use Phwoolcon\Queue\Adapter\JobTrait;
use Swift;
use Swift_Mailer;
use Swift_Message;
use Swift_SendmailTransport;
use Swift_SmtpTransport;
use Swift_SwiftException;

class Mailer
{
    const CONTENT_TYPE_HTML = 'text/html';
    const CONTENT_TYPE_TEXT = 'text/plain';

    /**
     * @var Di
     */
    protected static $di;

    /**
     * @var static
     */
    protected static $instance;
    protected static $mailerInitialized = false;
    /**
     * @var Swift_Mailer
     */
    protected $mailerLibrary;

    protected $async = false;
    protected $enabled = false;
    /**
     * @var Queue\Adapter\DbQueue
     */
    protected $queue;
    protected $config;
    protected $sender;

    protected function __construct($config)
    {
        $this->config = $config;
        $this->enabled = fnGet($config, 'enabled');
        $this->async = fnGet($config, 'async') && ($this->queue = Queue::connection('async_email_sending'));
        $this->sender = [fnGet($config, 'sender.address') => fnGet($config, 'sender.name')];
        switch (fnGet($config, 'driver')) {
            case 'smtp':
                $host = fnGet($config, 'smtp_host');
                $port = fnGet($config, 'smtp_port');
                $encryption = fnGet($config, 'smtp_encryption');
                $username = fnGet($config, 'smtp_username');
                $password = fnGet($config, 'smtp_password');

                $transport = Swift_SmtpTransport::newInstance($host, $port, $encryption);
                $transport->setUsername($username)->setPassword($password);
                break;
            // @codeCoverageIgnoreStart
            case 'sendmail':
                $transport = Swift_SendmailTransport::newInstance();
                break;
            default:
                throw new Swift_SwiftException('Please specify available driver in mail config');
            // @codeCoverageIgnoreEnd
        }
        $mailer = Swift_Mailer::newInstance($transport);
        $this->mailerLibrary = $mailer;
    }

    /**
     * @param JobTrait $job
     * @param array    $payload
     */
    public static function handleQueueJob($job, array $payload)
    {
        static::$instance === null and static::$instance = static::$di->getShared('mailer');
        call_user_func_array([static::$instance, 'realSend'], $payload);
    }

    protected function queue($to, $subject = null, $body = null, $contentType = self::CONTENT_TYPE_TEXT, $cc = null)
    {
        $this->queue->push([static::class, 'handleQueueJob'], func_get_args());
    }

    public function realSend($to, $subject = null, $body = null, $contentType = self::CONTENT_TYPE_TEXT, $cc = null)
    {
        $message = Swift_Message::newInstance($subject, $body, $contentType);
        $message->setTo($to);
        $cc and $message->setCc($cc);
        $message->setFrom($this->sender);
        $sent = $this->mailerLibrary->send($message);
        $this->mailerLibrary->getTransport()->stop();
        return $sent;
    }

    public static function register(Di $di)
    {
        static::$di = $di;
        $di->remove('mailer');
        static::$instance = null;

        if (!static::$mailerInitialized) {
            static::$mailerInitialized = true;
            Swift::autoload(Swift_Mailer::class);
        }

        $di->setShared('mailer', function () {
            return new static(Config::get('mail'));
        });
    }

    /**
     * If `async` is disabled, the mail will be sent immediately (default behaviour)
     * If `async` is enabled, the mail will be pushed into queue instead, then send it by a backend process:
     * `bin/cli queue:consume async_email_sending`
     *
     * @param string|array $to Supports 'to@domain.com', ['to@domain.com'] or ['to@domain.com' => 'Display Name']
     * @param string       $subject
     * @param string       $body
     * @param string       $contentType
     * @param string|array $cc @see $to
     * @return int
     */
    public static function send($to, $subject = null, $body = null, $contentType = self::CONTENT_TYPE_TEXT, $cc = null)
    {
        static::$instance === null and static::$instance = static::$di->getShared('mailer');
        $mailer = static::$instance;
        if (!$mailer->enabled) {
            return 0;
        } elseif ($mailer->async && $mailer->queue) {
            $mailer->queue($to, $subject, $body, $contentType, $cc);
            return 1;
        } else {
            return $mailer->realSend($to, $subject, $body, $contentType, $cc);
        }
    }
}