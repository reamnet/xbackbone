<?php


namespace App\Web;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class Mail
{
    /**
     * @var bool
     */
    private static $testing = false;

    protected $fromMail = 'no-reply@example.com';
    protected $fromName;

    protected $to;

    protected $subject;
    protected $message;

    protected $additionalHeaders = '';
    protected $headers = '';

    /**
     * @return Mail
     */
    public static function make()
    {
        return new self();
    }

    /**
     * This will skip the email send
     */
    public static function fake()
    {
        self::$testing = true;
    }

    /**
     * @param $mail
     * @param $name
     * @return $this
     */
    public function from(string $mail, string $name)
    {
        $this->fromMail = $mail;
        $this->fromName = $name;
        return $this;
    }

    /**
     * @param $mail
     * @return $this
     */
    public function to(string $mail)
    {
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Mail not valid.');
        }
        $this->to = "<$mail>";
        return $this;
    }


    /**
     * @param $text
     * @return $this
     */
    public function subject(string $text)
    {
        $this->subject = $text;
        return $this;
    }

    /**
     * @param $text
     * @return $this
     */
    public function message(string $text)
    {
        $this->message = $text;
        return $this;
    }

    /**
     * @param $header
     * @return $this
     */
    public function addHeader(string $header)
    {
        $this->additionalHeaders .= "$header\r\n";
        return $this;
    }

    /**
     * @param $header
     * @return $this
     */
    protected function addRequiredHeader(string $header)
    {
        $this->headers .= "$header\r\n";
        return $this;
    }

    /**
     * Set headers before send
     */
    protected function setHeaders()
    {
        if ($this->fromName === null) {
            $this->addRequiredHeader("From: $this->fromMail");
        } else {
            $this->addRequiredHeader("From: $this->fromName <$this->fromMail>");
        }

        $this->addRequiredHeader('X-Mailer: PHP/'.phpversion())
            ->addRequiredHeader('MIME-Version: 1.0')
            ->addRequiredHeader('Content-Type: text/html; charset=utf-8');
    }

    /** Build the configured SMTP transport without connecting to the server. */
    public static function createSmtpTransport(): EsmtpTransport
    {
        $host = trim((string) getenv('SMTP_HOST'));
        if ($host === '') {
            throw new RuntimeException('SMTP_HOST is not configured inside the container. Add the SMTP environment entries to the Compose service and recreate it; --env-file alone does not pass variables into the container.');
        }
        if (preg_match('/[\s\/@?#]/', $host) || (str_contains($host, ':') && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
            throw new InvalidArgumentException('SMTP_HOST must contain only the server hostname or IP address, without a URL scheme, port, or credentials.');
        }
        $security = strtolower(trim((string) getenv('SMTP_SECURITY'))) ?: 'tls';
        if ($security === 'starttls') {
            $security = 'tls';
        }
        if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
            throw new InvalidArgumentException('SMTP_SECURITY must be tls (STARTTLS), ssl (implicit TLS), or none (trusted local relay only).');
        }
        $port = trim((string) getenv('SMTP_PORT'));
        if ($port === '') {
            $port = $security === 'ssl' ? '465' : '587';
        }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('SMTP_PORT must be a number between 1 and 65535.');
        }
        $username = (string) getenv('SMTP_USERNAME');
        $password = (string) getenv('SMTP_PASSWORD');
        if (($username === '') !== ($password === '')) {
            throw new InvalidArgumentException('Set both SMTP_USERNAME and SMTP_PASSWORD for authentication, or leave both empty for a relay that does not require authentication.');
        }
        $stream = new SocketStream();
        $stream->setTimeout(30);
        $transport = new EsmtpTransport($host, (int) $port, $security === 'ssl', stream: $stream);
        $transport->setUsername($username);
        $transport->setPassword($password);
        $transport->setAutoTls($security !== 'none');
        $transport->setRequireTls($security !== 'none');

        return $transport;
    }

    /**
     * @return int
     */
    public function send()
    {
        if ($this->to === null) {
            throw new InvalidArgumentException('Target email cannot be null.');
        }

        if ($this->subject === null) {
            throw new InvalidArgumentException('Subject cannot be null.');
        }

        if ($this->message === null) {
            throw new InvalidArgumentException('Message cannot be null.');
        }

        $this->setHeaders();

        $this->headers .= $this->additionalHeaders;
        $message = html_entity_decode($this->message);

        if (self::$testing) {
            return 1;
        }

        if (getenv('SMTP_HOST')) {
            $transport = self::createSmtpTransport();
            $email = (new \Symfony\Component\Mime\Email())
                ->from(new \Symfony\Component\Mime\Address(getenv('SMTP_FROM_ADDRESS') ?: $this->fromMail, getenv('SMTP_FROM_NAME') ?: ($this->fromName ?? 'XBackBone')))
                ->to(trim($this->to, '<>'))->subject($this->subject)->html("<html><body>$message</body></html>");
            (new \Symfony\Component\Mailer\Mailer($transport))->send($email);
            return 1;
        }

        if (getenv('XBACKBONE_CONTAINER') === '1') {
            self::createSmtpTransport(); // Explain missing Compose configuration instead of attempting absent sendmail.
        }
        if (!mail($this->to, $this->subject, "<html><body>$message</body></html>", $this->headers)) {
            throw new RuntimeException('The local mail transport failed. Configure SMTP or check the sendmail service.');
        }

        return 1;
    }
}
