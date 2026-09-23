<?php

namespace App\Services\Trunkrs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GithubScheduleIdentity
{
    private const ISSUER = 'https://token.actions.githubusercontent.com';

    private const AUDIENCE = 'https://planning.bbquality.nl/api/trunkrs/scheduled-sync';

    private const WORKFLOW = 'DennisBBQuality/markteting-tool/.github/workflows/trunkrs-sync.yml@refs/heads/main';

    public function accepts(?string $token): bool
    {
        if (! is_string($token) || strlen($token) > 16384 || substr_count($token, '.') !== 2) {
            return false;
        }

        try {
            [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $token);
            $header = json_decode($this->decode($encodedHeader), true, 16, JSON_THROW_ON_ERROR);
            $claims = json_decode($this->decode($encodedClaims), true, 16, JSON_THROW_ON_ERROR);
            $signature = $this->decode($encodedSignature);
            if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256'
                || ! is_string($header['kid'] ?? null) || strlen($header['kid']) > 128) {
                return false;
            }

            $now = time();
            if (($claims['iss'] ?? null) !== self::ISSUER || ($claims['aud'] ?? null) !== self::AUDIENCE
                || ($claims['repository'] ?? null) !== 'DennisBBQuality/markteting-tool'
                || (string) ($claims['repository_id'] ?? '') !== '1167471646'
                || (string) ($claims['repository_owner_id'] ?? '') !== '157789343'
                || ($claims['ref'] ?? null) !== 'refs/heads/main'
                || ($claims['workflow_ref'] ?? null) !== self::WORKFLOW
                || ! in_array($claims['event_name'] ?? null, ['schedule', 'workflow_dispatch'], true)
                || ! is_int($claims['iat'] ?? null) || ! is_int($claims['nbf'] ?? null) || ! is_int($claims['exp'] ?? null)
                || $claims['iat'] > $now + 30 || $claims['iat'] < $now - 600
                || $claims['nbf'] > $now + 30 || $claims['exp'] <= $now - 30
                || $claims['exp'] > $now + 600) {
                return false;
            }

            foreach ([false, true] as $refresh) {
                $keys = $this->keys($refresh);
                foreach ($keys as $key) {
                    if (! is_array($key) || ($key['kid'] ?? null) !== $header['kid']
                        || ($key['kty'] ?? null) !== 'RSA' || ($key['alg'] ?? null) !== 'RS256'
                        || ($key['use'] ?? null) !== 'sig') {
                        continue;
                    }
                    $publicKey = $this->rsaKey($key);

                    return openssl_verify($encodedHeader.'.'.$encodedClaims, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function keys(bool $refresh): array
    {
        if ($refresh) {
            Cache::forget('trunkrs-github-oidc-jwks');
        }

        return Cache::remember('trunkrs-github-oidc-jwks', 600, function () {
            $response = Http::acceptJson()->timeout(5)->withoutRedirecting()
                ->get(self::ISSUER.'/.well-known/jwks');
            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new \RuntimeException('OIDC signing keys unavailable');
            }

            return $response->json('keys');
        });
    }

    private function decode(string $value): string
    {
        if ($value === '' || strlen($value) % 4 === 1 || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException('Invalid token encoding');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid token encoding');
        }

        return $decoded;
    }

    private function rsaKey(array $key): string
    {
        $modulus = $this->decode($key['n'] ?? '');
        $exponent = $this->decode($key['e'] ?? '');
        if (strlen($modulus) < 256 || strlen($modulus) > 1024 || strlen($exponent) < 1 || strlen($exponent) > 8) {
            throw new \InvalidArgumentException('Invalid signing key');
        }
        $rsa = $this->der(0x30, $this->integer($modulus).$this->integer($exponent));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $spki = $this->der(0x30, $algorithm.$this->der(0x03, "\0".$rsa));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\0".$bytes;
        }

        return $this->der(0x02, $bytes);
    }

    private function der(int $tag, string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            return chr($tag).chr($length).$value;
        }
        $encoded = ltrim(pack('N', $length), "\0");

        return chr($tag).chr(0x80 | strlen($encoded)).$encoded.$value;
    }
}
