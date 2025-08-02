<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PaystackService;
use App\Services\PaystackApiException;
use App\Services\DatabaseException;

class PaystackController
{
    private PaystackService $paystackService;

    public function __construct()
    {
        $this->paystackService = new PaystackService();
    }

    public function showPaymentPage(): void
    {
        // This method will render the payment form.
        // We'll pass any messages (success/error) to the view.
        $message = $_GET['message'] ?? '';
        $status = $_GET['status'] ?? '';
        require_once __DIR__ . '/../../templates/paystack_payment.php';
    }

    public function initializePayment(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $userId = 1; // TODO: Replace with actual user ID from session/auth

            if (!$email || !$amount || $amount <= 0) {
                $this->redirectWithStatus('error', 'Invalid email or amount provided.');
                return;
            }

            try {
                // Construct the callback URL dynamically
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $callbackUrl = "{$protocol}://{$host}/paystack/verify";

                $response = $this->paystackService->initializeCharge($userId, $amount, $email, $callbackUrl);

                if (isset($response['data']['authorization_url'])) {
                    header('Location: ' . $response['data']['authorization_url']);
                    exit();
                } else {
                    $this->redirectWithStatus('error', 'Failed to get authorization URL from Paystack.');
                }
            } catch (PaystackApiException $e) {
                $this->redirectWithStatus('error', 'Paystack API Error: ' . $e->getMessage());
            } catch (DatabaseException $e) {
                $this->redirectWithStatus('error', 'Database Error: ' . $e->getMessage());
            } catch (\Exception $e) {
                $this->redirectWithStatus('error', 'An unexpected error occurred: ' . $e->getMessage());
            }
        } else {
            $this->redirectWithStatus('error', 'Invalid request method.');
        }
    }

    public function verifyPayment(): void
    {
        $reference = filter_input(INPUT_GET, 'reference', FILTER_SANITIZE_STRING);

        if (!$reference) {
            $this->redirectWithStatus('error', 'No transaction reference provided for verification.');
            return;
        }

        try {
            $data = $this->paystackService->verifyTransaction($reference);

            if ($data['status'] === 'success') {
                $this->redirectWithStatus('success', 'Payment verified successfully! Reference: ' . $reference);
            } else {
                $this->redirectWithStatus('error', 'Payment verification failed. Status: ' . ($data['status'] ?? 'unknown'));
            }
        } catch (PaystackApiException $e) {
            $this->redirectWithStatus('error', 'Paystack API Error during verification: ' . $e->getMessage());
        } catch (DatabaseException $e) {
            $this->redirectWithStatus('error', 'Database Error during verification: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->redirectWithStatus('error', 'An unexpected error occurred during verification: ' . $e->getMessage());
        }
    }

    private function redirectWithStatus(string $status, string $message): void
    {
        header('Location: /paystack-payment?status=' . urlencode($status) . '&message=' . urlencode($message));
        exit();
    }
}
