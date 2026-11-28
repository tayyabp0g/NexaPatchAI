<?php

namespace App\Services\Scanner;

class ReferenceBuilder
{
    private const OWASP = [
        'A01' => 'A01_2021-Broken_Access_Control',
        'A02' => 'A02_2021-Cryptographic_Failures',
        'A03' => 'A03_2021-Injection',
        'A04' => 'A04_2021-Insecure_Design',
        'A05' => 'A05_2021-Security_Misconfiguration',
        'A06' => 'A06_2021-Vulnerable_and_Outdated_Components',
        'A07' => 'A07_2021-Identification_and_Authentication_Failures',
        'A08' => 'A08_2021-Software_and_Data_Integrity_Failures',
        'A09' => 'A09_2021-Security_Logging_and_Monitoring_Failures',
        'A10' => 'A10_2021-Server-Side_Request_Forgery_%28SSRF%29',
    ];

    /** @return list<string> */
    public static function for(?string $cwe, ?string $owasp): array
    {
        $refs = [];
        if ($cwe && preg_match('/CWE-(\d+)/', $cwe, $m)) {
            $refs[] = 'https://cwe.mitre.org/data/definitions/'.$m[1].'.html';
        }
        if ($owasp && preg_match('/^(A\d{2})/', $owasp, $m) && isset(self::OWASP[$m[1]])) {
            $refs[] = 'https://owasp.org/Top10/'.self::OWASP[$m[1]].'/';
        }

        return $refs;
    }
}
