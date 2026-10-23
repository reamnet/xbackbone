<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailConfigurationTest extends TestCase
{
    private function runPhp(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'sendmail_path=/bin/false', ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            BASE_DIR,
            array_replace(getenv(), [
                'SMTP_HOST' => '', 'SMTP_PORT' => '', 'SMTP_SECURITY' => '',
                'SMTP_USERNAME' => '', 'SMTP_PASSWORD' => '', 'SMTP_FROM_ADDRESS' => '',
                'XBACKBONE_CONTAINER' => '1',
            ], $environment)
        );
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testMissingSmtpCannotReportSuccessfulSubmission(): void
    {
        [$status, $output] = $this->runPhp(['bin/mail-test', 'recipient@example.com']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('SMTP_HOST is not configured inside the container', $output);
        $this->assertStringNotContainsString('Mail submitted', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
    }

    public function testWebMailExplainsMissingContainerConfiguration(): void
    {
        [$status, $output] = $this->runPhp(['-r', <<<'PHP'
require 'vendor/autoload.php';
try {
    App\Web\Mail::make()->to('recipient@example.com')->subject('Test')->message('Test')->send();
    echo 'Unexpected success';
} catch (RuntimeException $exception) {
    echo $exception->getMessage();
    exit(1);
}
PHP]);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('Add the SMTP environment entries to the Compose service', $output);
        $this->assertStringNotContainsString('Unexpected success', $output);
    }

    public function testFailedLegacyMailCannotReportSuccess(): void
    {
        [$status, $output] = $this->runPhp(['-r', <<<'PHP'
require 'vendor/autoload.php';
try {
    App\Web\Mail::make()->to('recipient@example.com')->subject('Test')->message('Test')->send();
    echo 'Unexpected success';
} catch (RuntimeException $exception) {
    echo $exception->getMessage();
    exit(1);
}
PHP], ['XBACKBONE_CONTAINER' => '']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('The local mail transport failed', $output);
        $this->assertStringNotContainsString('Unexpected success', $output);
    }

    public function testConfigurationCheckNeedsNoConnectionAndDoesNotExposeCredentials(): void
    {
        [$status, $output] = $this->runPhp(['bin/mail-test', '--check'], [
            'SMTP_HOST' => 'smtp.example.invalid', 'SMTP_USERNAME' => 'private-user',
            'SMTP_PASSWORD' => 'private-password', 'SMTP_SECURITY' => 'STARTTLS',
        ]);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('SMTP_PORT: 587', $output);
        $this->assertStringContainsString('STARTTLS required', $output);
        $this->assertStringContainsString('SMTP_USERNAME: set', $output);
        $this->assertStringContainsString('SMTP_PASSWORD: set', $output);
        $this->assertStringContainsString('No connection or delivery test was performed', $output);
        $this->assertStringNotContainsString('private-user', $output);
        $this->assertStringNotContainsString('private-password', $output);
    }

    public function testImplicitTlsDefaultsTo465WithoutDowngradingSecurity(): void
    {
        [$status, $output] = $this->runPhp(['bin/mail-test', '--check'], [
            'SMTP_HOST' => 'smtp.example.invalid', 'SMTP_SECURITY' => 'ssl',
        ]);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('SMTP_PORT: 465', $output);
        $this->assertStringContainsString('ssl (implicit TLS)', $output);
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationFailsBeforeConnecting(array $environment, string $expected): void
    {
        [$status, $output] = $this->runPhp(['bin/mail-test', '--check'], array_replace([
            'SMTP_HOST' => 'smtp.example.invalid',
        ], $environment));
        $this->assertSame(1, $status);
        $this->assertStringContainsString($expected, $output);
    }

    public static function invalidConfigurations(): array
    {
        return [
            'unknown security' => [['SMTP_SECURITY' => 'typo'], 'SMTP_SECURITY must be'],
            'invalid port' => [['SMTP_PORT' => '587oops'], 'SMTP_PORT must be'],
            'zero port' => [['SMTP_PORT' => '0'], 'SMTP_PORT must be'],
            'out of range port' => [['SMTP_PORT' => '65536'], 'SMTP_PORT must be'],
            'missing password' => [['SMTP_USERNAME' => 'user'], 'Set both SMTP_USERNAME and SMTP_PASSWORD'],
            'credentials in hostname' => [['SMTP_HOST' => 'smtp://user:password@example.invalid'], 'SMTP_HOST must contain only'],
            'port in hostname' => [['SMTP_HOST' => 'smtp.example.invalid:587'], 'SMTP_HOST must contain only'],
        ];
    }
}
