<?php

namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    private array $config;
    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        Config::load();
        $this->config = [
            'host' => Config::get('SMTP_HOST', 'smtp.gmail.com'),
            'port' => (int) Config::get('SMTP_PORT', '587'),
            'user' => Config::get('SMTP_USER', ''),
            'pass' => Config::get('SMTP_PASS', ''),
            'from_email' => Config::get('SMTP_FROM_EMAIL', Config::get('SMTP_USER', '')),
            'from_name' => Config::get('SMTP_FROM_NAME', 'Monitor zákona'),
        ];
        $this->logger = $logger;
    }

    private function isConfigured(): bool
    {
        return !empty($this->config['user']) && !empty($this->config['pass']);
    }

    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        if ($this->isConfigured()) {
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['user'];
            $mail->Password = $this->config['pass'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->config['port'];
        }

        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        return $mail;
    }

    public function sendWelcomeEmail(string $to): bool
    {
        if (!$this->isConfigured()) {
            $this->logger?->warning('MailService: SMTP nie je nakonfigurovaný, welcome email nie je odoslaný.');
            return false;
        }

        try {
            $baseUrl = Config::get('APP_BASE_URL', '');
            if (empty($baseUrl)) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'monitorzakona.sk';
                $baseUrl = $protocol . '://' . $host . (dirname($_SERVER['SCRIPT_NAME'] ?? '') !== '/' ? dirname($_SERVER['SCRIPT_NAME']) : '');
            }
            $baseUrl = rtrim($baseUrl, '/');
            $loginUrl = $baseUrl . '/login.php';

            $mail = $this->createMailer();
            $mail->addAddress($to);
            $mail->Subject = 'Vitajte v Monitori zákona';
            $mail->isHTML(true);
            $mail->Body = "
                <h2>Vitajte!</h2>
                <p>Vaše konto v Monitori zákona bolo úspešne vytvorené.</p>
                <p>Prihlásiť sa môžete na: <a href=\"{$loginUrl}\">{$loginUrl}</a></p>
                <p>Monitor zákona vám umožňuje prehľadávať slovenské zákony, pýtať sa na ne pomocou AI a sťahovať súbory.</p>
                <p>Ak ste tento email nedostali na základe vašej registrácie, môžete ho ignorovať.</p>
                <p>S pozdravom,<br>Monitor zákona</p>
            ";
            $mail->AltBody = "Vitajte! Vaše konto bolo vytvorené. Prihlásiť sa môžete na: {$loginUrl}";

            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->logger?->error('MailService sendWelcomeEmail: ' . $e->getMessage());
            return false;
        }
    }

    public function sendPasswordResetEmail(string $to, string $resetLink): bool
    {
        if (!$this->isConfigured()) {
            $this->logger?->error('MailService: SMTP nie je nakonfigurovaný, password reset email nie je odoslaný.');
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($to);
            $mail->Subject = 'Obnovenie hesla – Monitor zákona';
            $mail->isHTML(true);
            $mail->Body = "
                <h2>Obnovenie hesla</h2>
                <p>Dostali sme žiadosť o obnovenie hesla pre váš účet v Monitori zákona.</p>
                <p>Kliknite na odkaz pre nastavenie nového hesla (platí 1 hodinu):</p>
                <p><a href=\"{$resetLink}\">{$resetLink}</a></p>
                <p>Ak ste tento email nežiadali, môžete ho ignorovať. Vaše heslo zostane nezmenené.</p>
                <p>S pozdravom,<br>Monitor zákona</p>
            ";
            $mail->AltBody = "Obnovenie hesla: {$resetLink} (platí 1 hodinu). Ak ste nežiadali, ignorujte tento email.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->logger?->error('MailService sendPasswordResetEmail: ' . $e->getMessage());
            return false;
        }
    }
}
