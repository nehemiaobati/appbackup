<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Services\Database;

/**
 * PaystackService.php
 *
 * This Service class provides a robust interface for interacting with the Paystack API.
 * It handles various payment-related operations including initializing charges,
 * verifying transactions, creating transfer recipients, and initiating single transfers.
 * The service ensures secure communication with Paystack and manages corresponding
 * transaction and recipient data in the local database.
 */

namespace App\Services;

use PDO;
use Exception;
use App\Services\Database;

/**
 * Custom exception for Paystack API errors.
 * This exception is thrown when an operation with the Paystack API fails.
 */
class PaystackApiException extends Exception
{
    /**
     * Constructor for PaystackApiException.
     *
     * @param string $message The exception message.
     * @param int $code The exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Custom exception for database errors specific to PaystackService operations.
 * This exception is thrown when a database interaction within this service fails.
 */
class DatabaseException extends Exception
{
    /**
     * Constructor for DatabaseException.
     *
     * @param string $message The exception message.
     * @param int $code The exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

/**
 * Service class for interacting with the Paystack API.
 * Handles payment initialization, verification, transfer recipient creation, and single transfers.
 */
class PaystackService
{
    private PDO $pdo;
    private string $secretKey;

    /**
     * @var string The base URL for the Paystack API.
     */
    private const PAYSTACK_API_BASE_URL = 'https://api.paystack.co';

    /**
     * PaystackService constructor.
     * Initializes PDO connection and loads Paystack secret key from environment variables.
     *
     * @throws Exception If the PAYSTACK_SECRET_KEY environment variable is not set, which is critical for API access.
     */
    public function __construct()
    {
        $this->pdo = Database::getConnection();

        if (!isset($_ENV['PAYSTACK_SECRET_KEY'])) {
            error_log("PaystackService Error: PAYSTACK_SECRET_KEY is not set in the environment.");
            throw new Exception('PAYSTACK_SECRET_KEY is not set in the environment.');
        }
        $this->secretKey = $_ENV['PAYSTACK_SECRET_KEY'];
    }

    /**
     * Initializes a payment charge with Paystack.
     * A pending transaction record is inserted into the local database before making the API call.
     *
     * @param int $userId The ID of the user initiating the transaction.
     * @param float $amount The amount to charge in KES (e.g., 100.50).
     * @param string $email The customer's email address.
     * @param string $callbackUrl The URL Paystack should redirect to after payment completion.
     * @param array $metadata Optional metadata to include with the transaction, merged with a unique reference.
     * @return array The full response array from the Paystack API, including authorization URL.
     * @throws PaystackApiException If the Paystack API call fails or returns an error.
     * @throws DatabaseException If there's an issue inserting the pending transaction into the database.
     */
    public function initializeCharge(int $userId, float $amount, string $email, string $callbackUrl, array $metadata = []): array
    {
        $amountInKobo = (int)($amount * 100); // Convert KES to kobo (Paystack's smallest currency unit).

        // Generate a unique reference for the transaction to track it locally and with Paystack.
        $reference = 'PS_' . uniqid() . '_' . time();

        // Record the pending transaction in the local database.
        $this->insertPendingTransaction($userId, $reference, $amount);

        $payload = [
            "email" => $email,
            "amount" => $amountInKobo,
            "currency" => "KES",
            "callback_url" => $callbackUrl,
            "channels" => ["mobile_money", "bank", "card"], // Specify preferred payment channels.
            "metadata" => array_merge($metadata, ['reference' => $reference]), // Ensure reference is in metadata.
            "reference" => $reference // Also include reference directly in payload for Paystack.
        ];

        // Make the API request to initialize the transaction.
        return $this->makeRequest('POST', '/transaction/initialize', $payload);
    }

    /**
     * Verifies a Paystack transaction using its unique reference.
     * Updates the transaction status in the local database upon successful verification.
     *
     * @param string $reference The transaction reference to verify.
     * @return array The 'data' object from the Paystack API response, containing transaction details.
     * @throws PaystackApiException If the Paystack API call fails or the transaction status is not 'success'.
     * @throws DatabaseException If there's an issue updating the transaction status in the database.
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->makeRequest('GET', '/transaction/verify/' . $reference);

        // Check if the API response contains data and if the transaction status is 'success'.
        if (!isset($response['data']) || $response['data']['status'] !== 'success') {
            error_log("PaystackService Error: Transaction verification failed for reference {$reference}. Response: " . json_encode($response));
            throw new PaystackApiException("Transaction verification failed or not successful for reference: " . $reference);
        }

        $data = $response['data'];

        // Update the local transaction record with the verified status and Paystack's response.
        $this->updateTransactionStatus($reference, $data['status'], $data['channel'] ?? null, $response);

        return $data;
    }

    /**
     * Creates a transfer recipient for M-Pesa payments on Paystack.
     * Inserts the recipient details into the local database upon successful creation.
     *
     * @param int $userId The ID of the user associated with this recipient.
     * @param string $name The name of the recipient.
     * @param string $accountNumber The M-Pesa account number.
     * @param string $bankCode The bank code (e.g., 'mobi' for M-Pesa).
     * @return string The recipient code generated by Paystack, required for initiating transfers.
     * @throws PaystackApiException If the Paystack API call fails or the recipient code is missing from the response.
     * @throws DatabaseException If there's an issue inserting the recipient into the database.
     */
    public function createTransferRecipient(int $userId, string $name, string $accountNumber, string $bankCode): string
    {
        $payload = [
            "type" => "momo", // Mobile Money type.
            "name" => $name,
            "account_number" => $accountNumber,
            "bank_code" => $bankCode, // Should be 'mobi' for M-Pesa in Kenya.
            "currency" => "KES"
        ];

        $response = $this->makeRequest('POST', '/transferrecipient', $payload);

        // Ensure the recipient code is present in the Paystack response.
        if (!isset($response['data']['recipient_code'])) {
            error_log("PaystackService Error: Failed to create transfer recipient. Response: " . json_encode($response));
            throw new PaystackApiException("Failed to create transfer recipient: Missing recipient_code in response.");
        }

        $recipientCode = $response['data']['recipient_code'];

        // Insert the new transfer recipient into the local database.
        $this->insertTransferRecipient($userId, $recipientCode, $name, $accountNumber, $bankCode);

        return $recipientCode;
    }

    /**
     * Initiates a single transfer to a previously created recipient.
     *
     * @param int $dbRecipientId The internal database ID of the transfer recipient.
     * @param float $amount The amount to transfer in KES.
     * @param string $reason The reason for the transfer, visible to the recipient.
     * @return array The full response from the Paystack API for the transfer.
     * @throws PaystackApiException If the Paystack API call fails.
     * @throws DatabaseException If the recipient is not found in the local database.
     */
    public function initiateSingleTransfer(int $dbRecipientId, float $amount, string $reason): array
    {
        // Retrieve the Paystack recipient code from the local database using the internal ID.
        $recipientCode = $this->getRecipientCode($dbRecipientId);

        $amountInKobo = (int)($amount * 100); // Convert KES to kobo.

        $payload = [
            "source" => "balance", // Source of funds (e.g., Paystack balance).
            "reason" => $reason,
            "amount" => $amountInKobo,
            "recipient" => $recipientCode // The Paystack recipient code.
        ];

        // Make the API request to initiate the transfer.
        return $this->makeRequest('POST', '/transfer', $payload);
    }

    /**
     * Makes an HTTP request to the Paystack API.
     * This is a private helper method to centralize API communication logic.
     *
     * @param string $method The HTTP method (e.g., 'GET', 'POST').
     * @param string $endpoint The API endpoint path (e.g., '/transaction/initialize').
     * @param array|null $payload The request body for POST requests, encoded as JSON.
     * @return array The decoded JSON response from the API.
     * @throws PaystackApiException If the cURL request fails, the HTTP status code is not 2xx,
     *                              the JSON response cannot be decoded, or Paystack indicates an error.
     */
    private function makeRequest(string $method, string $endpoint, ?array $payload = []): array
    {
        $url = self::PAYSTACK_API_BASE_URL . $endpoint;
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->secretKey, // Authorization header with secret key.
            'Content-Type: application/json',            // Specify content type for JSON payloads.
            'Cache-Control: no-cache',                   // Prevent caching of API responses.
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string.
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); // Encode payload as JSON.
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP status code.
        $error = curl_error($ch); // Get cURL error message.

        curl_close($ch);

        if ($response === false) {
            error_log("PaystackService cURL Error: {$error} for URL: {$url}");
            throw new PaystackApiException("cURL Error: " . $error);
        }

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("PaystackService JSON Decode Error: " . json_last_error_msg() . " Response: " . $response);
            throw new PaystackApiException("Failed to decode JSON response from Paystack: " . json_last_error_msg() . " Response: " . $response);
        }

        // Check for non-2xx HTTP status codes.
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decodedResponse['message'] ?? 'Unknown API error';
            error_log("PaystackService API HTTP Error: {$httpCode} - {$errorMessage} for URL: {$url}");
            throw new PaystackApiException("Paystack API request failed with HTTP status {$httpCode}: {$errorMessage}", $httpCode);
        }

        // Check for Paystack's internal 'status: false' indicating an application-level error.
        if (isset($decodedResponse['status']) && $decodedResponse['status'] === false) {
            $errorMessage = $decodedResponse['message'] ?? 'Paystack API returned an error status.';
            error_log("PaystackService API Error Status: {$errorMessage} for URL: {$url}");
            throw new PaystackApiException("Paystack API error: {$errorMessage}");
        }

        return $decodedResponse;
    }

    // --------------------------------------------------------------------------
    // DATABASE INTERACTION METHODS
    // These private methods handle the persistence of Paystack-related data
    // to the local database, ensuring data integrity and consistency.
    // --------------------------------------------------------------------------

    /**
     * Inserts a new pending transaction record into the `paystack_transactions` table.
     *
     * @param int $userId The ID of the user associated with the transaction.
     * @param string $reference The unique Paystack transaction reference.
     * @param float $amount The amount of the transaction in KES.
     * @return void
     * @throws DatabaseException If the database insert operation fails.
     */
    private function insertPendingTransaction(int $userId, string $reference, float $amount): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO paystack_transactions (user_id, reference, amount_kes, status) VALUES (:user_id, :reference, :amount_kes, 'pending')"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':reference' => $reference,
                ':amount_kes' => $amount,
            ]);
        } catch (\PDOException $e) {
            error_log("PaystackService Database Error (insertPendingTransaction): " . $e->getMessage());
            throw new DatabaseException("Failed to insert pending transaction: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Updates the status of an existing transaction in the `paystack_transactions` table
     * after verification from Paystack.
     *
     * @param string $reference The unique Paystack transaction reference.
     * @param string $status The new status of the transaction (e.g., 'success', 'failed').
     * @param string|null $channel The payment channel used (e.g., 'mobile_money', 'bank').
     * @param array $response The full JSON response from Paystack's verification API.
     * @return void
     * @throws DatabaseException If the database update operation fails.
     */
    private function updateTransactionStatus(string $reference, string $status, ?string $channel, array $response): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE paystack_transactions SET status = :status, channel = :channel, paystack_response_at_verification = :response_json WHERE reference = :reference"
            );
            $stmt->execute([
                ':status' => $status,
                ':channel' => $channel,
                ':response_json' => json_encode($response), // Store the full response for auditing.
                ':reference' => $reference,
            ]);
        } catch (\PDOException $e) {
            error_log("PaystackService Database Error (updateTransactionStatus): " . $e->getMessage());
            throw new DatabaseException("Failed to update transaction status: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Inserts a new transfer recipient record into the `paystack_transfer_recipients` table.
     *
     * @param int $userId The ID of the user who owns this recipient.
     * @param string $recipientCode The unique recipient code provided by Paystack.
     * @param string $name The name of the recipient.
     * @param string $accountNumber The recipient's account number (e.g., M-Pesa number).
     * @param string $bankCode The bank code associated with the recipient (e.g., 'mobi').
     * @return void
     * @throws DatabaseException If the database insert operation fails.
     */
    private function insertTransferRecipient(int $userId, string $recipientCode, string $name, string $accountNumber, string $bankCode): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO paystack_transfer_recipients (user_id, recipient_code, name, account_number, bank_code, currency) VALUES (:user_id, :recipient_code, :name, :account_number, :bank_code, :currency)"
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':recipient_code' => $recipientCode,
                ':name' => $name,
                ':account_number' => $accountNumber,
                ':bank_code' => $bankCode,
                ':currency' => 'KES', // Assuming KES for M-Pesa transfers.
            ]);
        } catch (\PDOException $e) {
            error_log("PaystackService Database Error (insertTransferRecipient): " . $e->getMessage());
            throw new DatabaseException("Failed to insert transfer recipient: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Retrieves the Paystack `recipient_code` for a given internal database recipient ID.
     * Ensures the recipient is active.
     *
     * @param int $dbRecipientId The internal database ID of the transfer recipient.
     * @return string The Paystack recipient code.
     * @throws DatabaseException If the recipient is not found or is inactive in the database.
     */
    private function getRecipientCode(int $dbRecipientId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT recipient_code FROM paystack_transfer_recipients WHERE id = :id AND is_active = 1"
            );
            $stmt->execute([':id' => $dbRecipientId]);
            $recipient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipient) {
                throw new DatabaseException("Transfer recipient with ID {$dbRecipientId} not found or is inactive.");
            }
            return $recipient['recipient_code'];
        } catch (\PDOException $e) {
            error_log("PaystackService Database Error (getRecipientCode): " . $e->getMessage());
            throw new DatabaseException("Failed to retrieve recipient code: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
