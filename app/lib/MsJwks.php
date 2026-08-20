<?php
/**
 * Microsoft id_token signature verification.
 *
 * The auth-code callback already checks aud / exp / iss / nonce, and receives the
 * token over a TLS back-channel straight from Microsoft's token endpoint rather
 * than through the browser — which is why the missing signature check was never
 * exploitable in practice. It is still the one claim-check that does not depend
 * on assumptions about how the token arrived, so it belongs there.
 *
 * The keys are cached on disk. A login must never hang on Microsoft's discovery
 * endpoint, and it must never fail because the cache is cold and the network is
 * briefly unhappy — see verify(), which is deliberately fail-open on transport
 * problems and fail-closed on an actual bad signature.
 */

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

class MsJwks
{
    /** Refetch the key set at most this often under normal conditions. */
    private const TTL = 21600;          // 6 hours
    /** How long a stale cache may still be used when Microsoft is unreachable. */
    private const STALE_GRACE = 604800; // 7 days

    private static function cachePath()
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/ms_jwks.json';
    }

    /**
     * The signing key set, from cache when fresh, otherwise refetched.
     * Returns null when there is no usable key material at all.
     */
    private static function keySet($jwksUri)
    {
        $path = self::cachePath();
        $now  = time();

        $cached = null;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $cached = $raw ? json_decode($raw, true) : null;
            if (is_array($cached) && !empty($cached['keys'])
                && ($now - (int)($cached['fetched_at'] ?? 0)) < self::TTL) {
                return $cached['keys'];
            }
        }

        try {
            $resp = Graph::rawCall('GET', $jwksUri);
            if (($resp['code'] ?? 0) === 200) {
                $fresh = json_decode($resp['body'], true);
                if (!empty($fresh['keys'])) {
                    @file_put_contents(
                        $path,
                        json_encode(['fetched_at' => $now, 'keys' => $fresh['keys']]),
                        LOCK_EX
                    );
                    return $fresh['keys'];
                }
            }
            error_log('MsJwks: unexpected response from ' . $jwksUri . ' (HTTP ' . ($resp['code'] ?? '?') . ')');
        } catch (\Throwable $e) {
            error_log('MsJwks: fetching keys: ' . $e->getMessage());
        }

        // Refetch failed. A slightly stale key set is far better than turning a
        // network blip into an outage — Microsoft rotates keys over weeks.
        if (is_array($cached) && !empty($cached['keys'])
            && ($now - (int)($cached['fetched_at'] ?? 0)) < self::STALE_GRACE) {
            error_log('MsJwks: using stale cached keys');
            return $cached['keys'];
        }
        return null;
    }

    /**
     * Verify an id_token's RS256 signature.
     *
     * Returns true when the signature is good, false when it is definitively
     * bad, and null when verification could not be performed at all (no key
     * material reachable). The caller decides what null means — see the note in
     * AuthController::microsoftCallback about why that is not treated as a
     * failure there.
     */
    public static function verify($idToken, $jwksUri)
    {
        $keys = self::keySet($jwksUri);
        if (!$keys) return null;

        // The token names the key that signed it. Refusing anything but RS256
        // here is what stops an alg=none or HS256-with-the-public-key forgery.
        try {
            $parsed = JWK::parseKeySet(['keys' => $keys], 'RS256');
        } catch (\Throwable $e) {
            error_log('MsJwks: key set unusable: ' . $e->getMessage());
            return null;
        }

        // Past this point we HAVE usable key material, so every failure is a
        // property of the token and none of them are fail-open.
        //
        // Matching only SignatureInvalidException here would be a hole: the
        // library rejects alg=none with "Algorithm not supported" and an
        // HS256-signed-with-the-RSA-public-key forgery with "Incorrect key for
        // this algorithm" — different exception types, both meaning forged.
        // Anything thrown while decoding a token we can check is a rejection.
        try {
            JWT::$leeway = 60;
            JWT::decode($idToken, $parsed);
            return true;
        } catch (\Throwable $e) {
            error_log('MsJwks: rejecting id_token: ' . $e->getMessage());
            return false;
        }
    }
}
