<?php

namespace App\Services;

class SmsService
{
    public function sendSmsMtarget($message, $msisdn, $sender = 'TOO AUTO')
    {
        if (!function_exists('curl_init')) {
            throw new \Exception('Extension PHP cURL non activee.');
        }

        $url = config('services.mtarget.url', 'https://api-public-2.mtarget.fr/messages');

        if (strpos($msisdn, '+') !== 0) {
            $msisdn = '+' . $msisdn;
        }

        $postData = http_build_query([
            'username' => config('services.mtarget.username', 'bwantech'),
            'password' => config('services.mtarget.password', 'x7jyKG0IJRNH'),
            'msisdn' => $msisdn,
            'msg' => $message,
            'sender' => $sender,
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Erreur cURL : ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('Erreur HTTP : ' . $httpCode . ' - Reponse : ' . $response);
        }

        return $response;
    }
}
