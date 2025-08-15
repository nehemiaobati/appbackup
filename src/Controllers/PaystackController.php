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

    /**
     * Calculates the total successful balance, accounting for fees.
     * Currency is in cents.
     *
     * @return int The total successful balance in cents.
     * @throws DatabaseException If there's an error accessing the database.
     * @throws \Exception If there's an error processing transaction data.
     */
    public function getTotalSuccessfulBalance(): int
    {
        // Ensure Database service is available
        if (!class_exists('\App\Services\Database')) {
            throw new \Exception('Database service not found.');
        }
        $db = Database::getConnection(); // Use static getConnection()

        $totalBalanceInCents = 0;

        try {
            // Prepare and execute the query to get successful transactions
            $stmt = $db->prepare("SELECT paystack_response_at_verification FROM paystack_transactions WHERE status = 'success'");
            $stmt->execute();
            $transactions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($transactions as $transaction) {
                $response = json_decode($transaction['paystack_response_at_verification'], true);

                // Check if JSON decoding was successful and if amount and fees exist
                if ($response && isset($response['data']['amount']) && isset($response['data']['fees'])) {
                    $amount = $response['data']['amount']; // Amount in cents
                    $fees = $response['data']['fees'];     // Fees in cents

                    // Calculate net amount for this transaction
                    $netAmountInCents = $amount - $fees;

                    // Add to total balance
                    $totalBalanceInCents += $netAmountInCents;
                }
            }
        } catch (\PDOException $e) {
            // Log or handle database errors appropriately
            throw new DatabaseException("Error fetching transaction data: " . $e->getMessage());
        } catch (\Exception $e) {
            // Catch other potential errors like JSON decoding issues
            throw new \Exception("Error processing transaction data: " . $e->getMessage());
        }

        return $totalBalanceInCents;
    }


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
