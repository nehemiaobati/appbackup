<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PaystackService;
use App\Services\PaystackApiException;
use App\Services\DatabaseException;
use App\Services\Database; // Added for static getConnection()

class PaystackController
{
    private PaystackService $paystackService;

    public function __construct()
    {
        $this->paystackService = new PaystackService();
    }

    /**
     * Displays the Paystack payment page.
     * Retrieves and clears payment-related messages and user email from session.
     *
     * @return void
     */
    public function showPaymentPage(): void
    {
        // Retrieve messages and user email from session
        $message = $_SESSION['paystack_message'] ?? '';
        $status = $_SESSION['paystack_status'] ?? '';
        $userEmail = $_SESSION['user_email'] ?? '';

        // Clear the session variables after retrieving them to ensure they are shown only once
        unset($_SESSION['paystack_message']);
        unset($_SESSION['paystack_status']);

        require_once __DIR__ . '/../../templates/paystack_payment.php';
    }

    /**
     * Initializes a Paystack payment.
     * Retrieves user ID and email from session, validates amount, and redirects to Paystack.
     * Handles various exceptions during the process.
     *
     * @return void
     */
    public function initializePayment(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $userId = $_SESSION['user_id'] ?? null;
            $email = $_SESSION['user_email'] ?? null; // Use email from session
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

            // Validate user session and email first
            if (!$userId || !$email) {
                // Set error message in session and redirect to login page
                $_SESSION['error_message'] = 'User not logged in or email not found in session. Please log in to make a payment.';
                header('Location: /login');
                exit();
            }

            // Validate payment amount
            if (!$amount || $amount <= 0) {
                $this->redirectWithStatus('error', 'Invalid amount provided.');
                return;
            }

            try {
                // Construct the callback URL dynamically for Paystack
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $callbackUrl = "{$protocol}://{$host}/paystack/verify";

                // Initialize charge with Paystack service
                $response = $this->paystackService->initializeCharge($userId, $amount, $email, $callbackUrl);

                // Redirect to Paystack authorization URL if successful
                if (isset($response['data']['authorization_url'])) {
                    header('Location: ' . $response['data']['authorization_url']);
                    exit();
                } else {
                    // Handle case where authorization URL is not returned
                    $this->redirectWithStatus('error', 'Failed to get authorization URL from Paystack.');
                }
            } catch (PaystackApiException $e) {
                // Catch and handle Paystack API specific errors
                $this->redirectWithStatus('error', 'Paystack API Error: ' . $e->getMessage());
            } catch (DatabaseException $e) {
                // Catch and handle database errors
                $this->redirectWithStatus('error', 'Database Error: ' . $e->getMessage());
            } catch (\Exception $e) {
                // Catch any other unexpected errors
                $this->redirectWithStatus('error', 'An unexpected error occurred: ' . $e->getMessage());
            }
        } else {
            // Handle invalid request method (e.g., direct GET access to POST endpoint)
            $this->redirectWithStatus('error', 'Invalid request method.');
        }
    }

    /**
     * Verifies a Paystack payment using the transaction reference.
     * Updates the user's balance upon successful verification.
     *
     * @return void
     */
    public function verifyPayment(): void
    {
        $reference = filter_input(INPUT_GET, 'reference', FILTER_SANITIZE_STRING);

        // Validate if a transaction reference is provided
        if (!$reference) {
            $this->redirectWithStatus('error', 'No transaction reference provided for verification.');
            return;
        }

        try {
            // Verify the transaction with Paystack service
            $data = $this->paystackService->verifyTransaction($reference);

            // Check if the payment verification was successful
            if ($data['status'] === 'success') {
                // Update user's balance
                $userId = $_SESSION['user_id'] ?? null; // Reverted to using session user ID

                if ($userId && isset($data['amount']) && isset($data['fees'])) {
                    $amount = $data['amount']; // Amount in cents
                    $fees = $data['fees'];     // Fees in cents
                    $netAmountInCents = $amount - $fees;

                    // Update user's balance in the database
                    $db = Database::getConnection();
                    $stmt = $db->prepare("UPDATE users SET balance_cents = balance_cents + ? WHERE id = ?");
                    $stmt->execute([$netAmountInCents, $userId]);
                }

                $this->redirectWithStatus('success', 'Payment verified successfully! Reference: ' . $reference);
            } else {
                // Handle failed payment verification
                $this->redirectWithStatus('error', 'Payment verification failed. Status: ' . ($data['status'] ?? 'unknown'));
            }
        } catch (PaystackApiException $e) {
            // Catch and handle Paystack API specific errors during verification
            $this->redirectWithStatus('error', 'Paystack API Error during verification: ' . $e->getMessage());
        } catch (DatabaseException $e) {
            // Catch and handle database errors during verification
            $this->redirectWithStatus('error', 'Database Error during verification: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Catch any other unexpected errors during verification
            $this->redirectWithStatus('error', 'An unexpected error occurred during verification: ' . $e->getMessage());
        }
    }

    // The getTotalSuccessfulBalance method has been removed as per user request.

    /**
     * Redirects to a specified path with a status message stored in session.
     *
     * @param string $status The status of the message (e.g., 'success', 'error').
     * @param string $message The message to display.
     * @param string $redirectPath The path to redirect to. Defaults to '/paystack-payment'.
     * @return void
     */
    private function redirectWithStatus(string $status, string $message, string $redirectPath = '/paystack-payment'): void
    {
        // Store the status and message in session variables
        $_SESSION['paystack_status'] = $status;
        $_SESSION['paystack_message'] = $message;
        // Redirect to the specified path
        header('Location: ' . $redirectPath);
        exit();
    }
}
