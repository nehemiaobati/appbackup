<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * MailService.php
 *
 * This Service class provides functionality for sending emails using PHPMailer.
 * It configures SMTP settings from environment variables and supports sending
 * HTML emails with optional attachments and reply-to addresses.
 */

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception; // PHPMailer's own Exception class

class MailService
{
    /**
     * Sends an email using configured SMTP settings.
     *
     * @param string $recipientEmail The email address of the recipient.
     * @param string $recipientName The name of the recipient.
     * @param string $subject The subject line of the email.
     * @param string $htmlBody The HTML content of the email body.
     * @param string|null $attachmentPath Optional path to a file to attach.
     * @param string|null $replyToEmail Optional email address for replies.
     * @param string|null $replyToName Optional name for the reply-to address.
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function sendEmail(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlBody,
        ?string $attachmentPath = null,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): bool {
        $mail = new PHPMailer(true); // Pass `true` to enable exceptions.
        try {
            // Server settings for SMTP.
            $mail->isSMTP();
            $mail->Host       = $_ENV['MAIL_HOST'];       // SMTP server to send through.
            $mail->SMTPAuth   = true;                      // Enable SMTP authentication.
            $mail->Username   = $_ENV['MAIL_USERNAME'];    // SMTP username.
            $mail->Password   = $_ENV['MAIL_PASSWORD'];    // SMTP password.
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Enable TLS encryption.
            $mail->Port       = (int)$_ENV['MAIL_PORT'];  // TCP port to connect to.

            // Sender and Recipient settings.
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
            $mail->addAddress($recipientEmail, $recipientName);

            // Optional Reply-To address.
            if ($replyToEmail) {
                $mail->addReplyTo($replyToEmail, $replyToName ?? '');
            }

            // Optional Attachments.
            if ($attachmentPath) {
                if (file_exists($attachmentPath)) {
                    $mail->addAttachment($attachmentPath);
                } else {
                    // Log if attachment file is not found.
                    error_log("MailService Error: Attachment file not found at {$attachmentPath}");
                    return false;
                }
            }

            // Content settings.
            $mail->isHTML(true); // Set email format to HTML.
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody); // Plain text alternative for non-HTML mail clients.

            $mail->send(); // Send the email.
            return true;
        } catch (Exception $e) {
            // Catch PHPMailer exceptions and log the error.
            error_log("MailService Error: Failed to send email. Mailer Error: {$e->getMessage()}");
            return false;
        }
    }
}
