<?php
declare(strict_types=1);

namespace App\Controllers;

use PDOException;
use RuntimeException;
use App\Services\EncryptionService;

class ApiKeyController extends BaseController
{
    private EncryptionService $encryptionService;

    public function __construct()
    {
        parent::__construct(); // Call parent constructor to initialize PDO
        $this->encryptionService = new EncryptionService();
    }

    public function showApiKeys(): void
    {
        $this->checkAuth(); // Use checkAuth from BaseController

        $current_user_id = $_SESSION['user_id'];
        $stmt = $this->pdo->prepare("SELECT id, key_name, is_active, created_at FROM user_api_keys WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$current_user_id]);
        $user_keys = $stmt->fetchAll();

        $this->render('api_keys', ['user_keys' => $user_keys]);
    }

    public function handleAddKey(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        try {
            $current_user_id = $_SESSION['user_id'];
            $key_name = trim(htmlspecialchars($_POST['key_name'] ?? ''));
            $binance_key = trim($_POST['binance_api_key'] ?? '');
            $binance_secret = trim($_POST['binance_api_secret'] ?? '');
            $gemini_key = trim($_POST['gemini_api_key'] ?? '');

            if (empty($key_name) || empty($binance_key) || empty($binance_secret) || empty($gemini_key)) {
                throw new \Exception("All fields are required to add a new key set.");
            }

            $stmt = $this->pdo->prepare("INSERT INTO user_api_keys (user_id, key_name, binance_api_key_encrypted, binance_api_secret_encrypted, gemini_api_key_encrypted) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$current_user_id, $key_name, $this->encryptionService->encrypt($binance_key), $this->encryptionService->encrypt($binance_secret), $this->encryptionService->encrypt($gemini_key)]);
            
            $_SESSION['success_message'] = "API Key set '" . htmlspecialchars($key_name) . "' added successfully!";
            header('Location: /api-keys');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
            header('Location: /api-keys');
            exit;
        } catch (RuntimeException $e) {
            $_SESSION['error_message'] = "Encryption error: " . $e->getMessage();
            header('Location: /api-keys');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /api-keys');
            exit;
        }
    }

    public function handleDeleteKey(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }

        try {
            $current_user_id = $_SESSION['user_id'];
            $key_id = (int)($_POST['key_id'] ?? 0);
            if ($key_id <= 0) {
                $_SESSION['error_message'] = "Invalid Key ID provided.";
                header('Location: /api-keys');
                exit;
            }

            $stmt = $this->pdo->prepare("DELETE FROM user_api_keys WHERE id = ? AND user_id = ?");
            $stmt->execute([$key_id, $current_user_id]);
            
            $_SESSION['success_message'] = "API Key set deleted successfully.";
            header('Location: /api-keys');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
            header('Location: /api-keys');
            exit;
        } catch (\Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /api-keys');
            exit;
        }
    }
}
