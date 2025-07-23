<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\MailService;

class ContactController
{
    private MailService $mailService;
    private string $recipientEmail = 'nehemiaobati@gmail.com'; // Configured recipient email
    private string $recipientName = 'Nehemia'; // Configured recipient name

    public function __construct()
    {
        $this->mailService = new MailService();
    }

    public function handleContactForm(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            if (!$name || !$email || !$message || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['error_message'] = "Please fill in all fields correctly.";
                header('Location: /portfolio#contact');
                exit;
            }

            $subject = "New Contact Form Submission from " . $name;
            $htmlBody = "
                <h3>New Message from Portfolio Contact Form</h3>
                <p><strong>Name:</strong> " . htmlspecialchars($name) . "</p>
                <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                <p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>
            ";

            $mailSent = $this->mailService->sendEmail(
                $this->recipientEmail,
                $this->recipientName,
                $subject,
                $htmlBody,
                null, // No attachment for contact form
                $email, // Reply-To email
                $name // Reply-To name
            );

            if ($mailSent) {
                $_SESSION['success_message'] = "Your message has been sent successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to send your message. Please try again later.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid request method.";
        }

        header('Location: /portfolio#contact');
        exit;
    }
}
