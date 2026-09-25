<?php

namespace App\Services\Monitoring;

use RuntimeException;

class PublicEndpoint
{
    public function host(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('blocked');
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true) || ! str_contains($host, '.')
            || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || filter_var($host, FILTER_VALIDATE_IP) || ctype_digit(str_replace('.', '', $host))) {
            throw new RuntimeException('blocked');
        }

        return $host;
    }

    /** @return list<string> */
    public function addresses(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }

    public function options(string $url): array
    {
        $host = $this->host($url);
        $addresses = $this->addresses($host);
        if ($addresses === []) {
            throw new RuntimeException('dns');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE) || (int) explode('.', $address)[0] >= 224) {
                throw new RuntimeException('blocked');
            }
        }
        if (! extension_loaded('curl')) {
            throw new RuntimeException('configuration');
        }
        $port = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);

        return ['proxy' => '', 'verify' => true, 'force_ip_resolve' => 'v4',
            'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$addresses[0]]]];
    }

    public static function description(?string $code): string
    {
        return match ($code) {
            'blocked' => 'Check blocked: use a public hostname on port 80 or 443, without credentials, query parameters or redirects to private services.',
            'dns' => 'The hostname did not resolve to an IPv4 address.',
            'connection' => 'The endpoint could not be reached within 10 seconds, or its TLS connection failed.',
            'status' => 'The endpoint returned a different HTTP status than expected. Redirects are not followed.',
            'interrupted' => 'The check did not finish. Availability is unknown for this check.',
            'changed' => 'Settings changed while this check was running; the result was discarded.',
            'configuration' => 'The monitoring runtime requires PHP cURL.',
            'internal' => 'The check could not be completed due to a local monitoring error.',
            default => 'No error recorded.',
        };
    }
}
