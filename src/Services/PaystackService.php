<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Exception;
use App\Services\Database;

/**
 * Custom exception for Paystack API errors.
 */
class PaystackApiException extends Exception
{
    /**
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
 * Custom exception for database errors.
 */
class DatabaseException extends Exception
{
    /**
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

    private const PAYSTACK_API_BASE_URL = 'https://api.paystack.co';

    /**
     * PaystackService constructor.
     * Initializes PDO connection and loads Paystack secret key from environment.
     *
     * @throws Exception If the PAYSTACK_SECRET_KEY is not set in the environment.
     */
    public function __construct()
    {
        $this->pdo = Database::getConnection();

        if (!isset($_ENV['PAYSTACK_SECRET_KEY'])) {
            throw new Exception('PAYSTACK_SECRET_KEY is not set in the environment.');
        }
        $this->secretKey = $_ENV['PAYSTACK_SECRET_KEY'];
    }

    /**
     * Initializes a payment charge with Paystack.
     * Inserts a pending transaction record before making the API call.
     *
     * @param int $userId The ID of the user initiating the transaction.
     * @param float $amount The amount in KES (e.g., 100.50).
     * @param string $email The customer's email address.
     * @param string $callbackUrl The URL Paystack should redirect to after payment.
     * @param array $metadata Optional metadata to include with the transaction.
     * @return array The full response from the Paystack API.
     * @throws PaystackApiException If the Paystack API call fails.
     * @throws DatabaseException If there's an issue inserting the transaction into the database.
     */
    public function initializeCharge(int $userId, float $amount, string $email, string $callbackUrl, array $metadata = []): array
    {
        $amountInKobo = (int)($amount * 100); // Convert KES to kobo/cents

        // Generate a unique reference for the transaction
        $reference = 'PS_' . uniqid() . '_' . time();

        $this->insertPendingTransaction($userId, $reference, $amount);

        $payload = [
            "email" => $email,
            "amount" => $amountInKobo,
            "currency" => "KES",
            "callback_url" => $callbackUrl,
            "channels" => ["mobile_money", "bank"],
            "metadata" => array_merge($metadata, ['reference' => $reference]), // Ensure reference is in metadata
            "reference" => $reference // Also include reference directly in payload
        ];

        return $this->makeRequest('POST', '/transaction/initialize', $payload);
    }

    /**
     * Verifies a Paystack transaction using its reference.
     * Updates the transaction status in the database upon successful verification.
     *
     * @param string $reference The transaction reference to verify.
     * @return array The 'data' object from the Paystack API response.
     * @throws PaystackApiException If the Paystack API call fails or verification status is not 'success'.
     * @throws DatabaseException If there's an issue updating the transaction in the database.
     */
    public function verifyTransaction(string $reference): array
    {
        $response = $this->makeRequest('GET', '/transaction/verify/' . $reference);

        if (!isset($response['data']) || $response['data']['status'] !== 'success') {
            throw new PaystackApiException("Transaction verification failed or not successful for reference: " . $reference);
        }

        $data = $response['data'];

        $this->updateTransactionStatus($reference, $data['status'], $data['channel'] ?? null, $response);

        return $data;
    }

    /**
     * Creates a transfer recipient for M-Pesa payments.
     * Inserts the recipient details into the database upon success.
     *
     * @param int $userId The ID of the user associated with this recipient.
     * @param string $name The name of the recipient.
     * @param string $accountNumber The M-Pesa account number.
     * @param string $bankCode The bank code (e.g., 'mobi' for M-Pesa).
     * @return string The recipient code generated by Paystack.
     * @throws PaystackApiException If the Paystack API call fails.
     * @throws DatabaseException If there's an issue inserting the recipient into the database.
     */
    public function createTransferRecipient(int $userId, string $name, string $accountNumber, string $bankCode): string
    {
        $payload = [
            "type" => "momo",
            "name" => $name,
            "account_number" => $accountNumber,
            "bank_code" => $bankCode, // Should be 'mobi' for M-Pesa
            "currency" => "KES"
        ];

        $response = $this->makeRequest('POST', '/transferrecipient', $payload);

        if (!isset($response['data']['recipient_code'])) {
            throw new PaystackApiException("Failed to create transfer recipient: Missing recipient_code in response.");
        }

        $recipientCode = $response['data']['recipient_code'];

        $this->insertTransferRecipient($userId, $recipientCode, $name, $accountNumber, $bankCode);

        return $recipientCode;
    }

    /**
     * Initiates a single transfer to a previously created recipient.
     *
     * @param int $dbRecipientId The internal database ID of the transfer recipient.
     * @param float $amount The amount to transfer in KES.
     * @param string $reason The reason for the transfer.
     * @return array The full response from the Paystack API.
     * @throws PaystackApiException If the Paystack API call fails.
     * @throws DatabaseException If the recipient is not found in the database.
     */
    public function initiateSingleTransfer(int $dbRecipientId, float $amount, string $reason): array
    {
        $recipientCode = $this->getRecipientCode($dbRecipientId);

        $amountInKobo = (int)($amount * 100); // Convert KES to kobo/cents

        $payload = [
            "source" => "balance",
            "reason" => $reason,
            "amount" => $amountInKobo,
            "recipient" => $recipientCode
        ];

        return $this->makeRequest('POST', '/transfer', $payload);
    }

    /**
     * Makes an HTTP request to the Paystack API.
     *
     * @param string $method The HTTP method (GET, POST).
     * @param string $endpoint The API endpoint path (e.g., '/transaction/initialize').
     * @param array|null $payload The request body for POST requests.
     * @return array The decoded JSON response from the API.
     * @throws PaystackApiException If the API request fails, returns a non-2xx status, or Paystack indicates an error.
     */
    private function makeRequest(string $method, string $endpoint, ?array $payload = []): array
    {
        $url = self::PAYSTACK_API_BASE_URL . $endpoint;
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($response === false) {
            throw new PaystackApiException("cURL Error: " . $error);
        }

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PaystackApiException("Failed to decode JSON response from Paystack: " . json_last_error_msg() . " Response: " . $response);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decodedResponse['message'] ?? 'Unknown API error';
            throw new PaystackApiException("Paystack API request failed with HTTP status {$httpCode}: {$errorMessage}", $httpCode);
        }

        if (isset($decodedResponse['status']) && $decodedResponse['status'] === false) {
            $errorMessage = $decodedResponse['message'] ?? 'Paystack API returned an error status.';
            throw new PaystackApiException("Paystack API error: {$errorMessage}");
        }

        return $decodedResponse;
    }

    // --------------------------------------------------------------------------
    // DATABASE INTERACTION METHODS
    // --------------------------------------------------------------------------

    /**
     * Inserts a new pending transaction into the database.
     *
     * @param int $userId
     * @param string $reference
     * @param float $amount
     * @throws DatabaseException
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
            throw new DatabaseException("Failed to insert pending transaction: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Updates the status of a transaction after verification.
     *
     * @param string $reference
     * @param string $status
     * @param string|null $channel
     * @param array $response
     * @throws DatabaseException
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
                ':response_json' => json_encode($response),
                ':reference' => $reference,
            ]);
        } catch (\PDOException $e) {
            throw new DatabaseException("Failed to update transaction status: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Inserts a new transfer recipient into the database.
     *
     * @param int $userId
     * @param string $recipientCode
     * @param string $name
     * @param string $accountNumber
     * @param string $bankCode
     * @throws DatabaseException
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
                ':currency' => 'KES',
            ]);
        } catch (\PDOException $e) {
            throw new DatabaseException("Failed to insert transfer recipient: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Retrieves the recipient code from the database.
     *
     * @param int $dbRecipientId
     * @return string
     * @throws DatabaseException
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
            throw new DatabaseException("Failed to retrieve recipient code: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
