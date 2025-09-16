<?php

namespace Generator;

final class ClienteGPT
{
    public function gerar(string $sBase, string $sApiKey, string $sModelo, string $sPrompt): ?string
    {
        $sUrl = rtrim($sBase, '/') . '/chat/completions';
        $aPayload = [
            'model' => $sModelo,
            'messages' => [
                ['role' => 'system','content' => 'VocÃª gera apenas DocBlocks PHPDoc.'],
                ['role' => 'user','content' => $sPrompt],
            ],
            'temperature' => 0.2,
        ];

        $h = curl_init($sUrl);
        curl_setopt_array($h, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . (str_starts_with($sApiKey, 'Bearer ') ? $sApiKey : 'Bearer ' . $sApiKey),
            ],
            CURLOPT_POSTFIELDS => json_encode($aPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $sResp = curl_exec($h);
        $iCode = curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);

        if ($sResp === false || $iCode >= 400) {
            return null;
        }

        $aJson = json_decode($sResp, true);
        $sDoc  = $aJson['choices'][0]['message']['content'] ?? null;
        if (!$sDoc) {
            return null;
        }

        $sDoc = trim($sDoc);
        if (!str_starts_with($sDoc, '/**')) {
            $sDoc = "/**\n * " . preg_replace('/^\*?\s*/m', '* ', $sDoc) . "\n */";
        }
        return $sDoc;
    }
}
