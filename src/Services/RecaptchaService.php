<?php
declare(strict_types=1);

namespace App\Services;

class RecaptchaService
{
    public function verify(string $recaptchaResponse): bool
    {
        $recaptchaSecretKey = $_ENV['RECAPTCHA_SECRET_KEY'];
        $recaptchaUrl = 'https://www.google.com/recaptcha/api/siteverify';
        $recaptchaData = ['secret' => $recaptchaSecretKey, 'response' => $recaptchaResponse];
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($recaptchaData)
            ]
        ];
        $context  = stream_context_create($options);
        $verify = file_get_contents($recaptchaUrl, false, $context);
        $captchaSuccess = json_decode($verify);
        return $captchaSuccess->success ?? false;
    }
}
