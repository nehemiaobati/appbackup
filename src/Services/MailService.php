<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    public function sendEmail(string $recipientEmail, string $recipientName, string $subject, string $htmlBody, ?string $attachmentPath = null): bool
    {
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $_ENV['MAIL_USERNAME'];
            $mail->Password   = $_ENV['MAIL_PASSWORD'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$_ENV['MAIL_PORT'];

            //Recipients
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($recipientEmail, $recipientName);

            //Attachments
            if ($attachmentPath) {
                if (file_exists($attachmentPath)) {
                    $mail->addAttachment($attachmentPath);
                } else {
                    error_log("MailService Error: Attachment file not found at {$attachmentPath}");
                    return false;
                }
            }

            //Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("MailService Error: {$e->getMessage()}");
            return false;
        }
    }
}
