<?php
declare(strict_types=1);

namespace App\Services;

/**
 * RecaptchaService.php
 *
 * This Service class provides functionality for verifying Google reCAPTCHA responses.
 * It communicates with the reCAPTCHA API to validate user interactions,
 * helping to protect forms and other sensitive actions from bot abuse.
 */

class RecaptchaService
{
    /**
     * Verifies a reCAPTCHA response token with Google's reCAPTCHA API.
     *
     * @param string $recaptchaResponse The reCAPTCHA token provided by the client-side.
     * @return bool True if the reCAPTCHA verification is successful, false otherwise.
     */
    public function verify(string $recaptchaResponse): bool
    {
        // Retrieve the reCAPTCHA secret key from environment variables.
        $recaptchaSecretKey = $_ENV['RECAPTCHA_SECRET_KEY'];
        // The URL for Google's reCAPTCHA verification API.
        $recaptchaUrl = 'https://www.google.com/recaptcha/api/siteverify';
        
        // Prepare the data payload for the POST request to the reCAPTCHA API.
        $recaptchaData = [
            'secret' => $recaptchaSecretKey,
            'response' => $recaptchaResponse
        ];

        // Configure stream context options for the HTTP POST request.
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($recaptchaData) // Encode data as URL-encoded string.
            ]
        ];
        
        // Create the stream context.
        $context  = stream_context_create($options);
        
        // Make the POST request to the reCAPTCHA API and get the response.
        $verify = file_get_contents($recaptchaUrl, false, $context);
        
        // Decode the JSON response from the reCAPTCHA API.
        $captchaSuccess = json_decode($verify);
        
        // Return the 'success' status from the decoded response, defaulting to false if not set.
        return $captchaSuccess->success ?? false;
    }
}
