<?php

declare(strict_types=1);

namespace ReviewRadar\Auth;

use ReviewRadar\Entities\Company;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Repositories\CompanyRepository;
use ReviewRadar\Support\Config;
use ReviewRadar\Support\Database;

/**
 * Resolves the acting tenant (company) for an API request.
 *
 * Resolution order:
 *  1. PHP session  (logged-in dashboard user)
 *  2. Authorization: Bearer <JWT>
 *  3. X-API-Key    (ingestion integrations / webhooks)
 */
final class Authenticator
{
    private function __construct()
    {
    }

    /**
     * @throws AuthenticationException When no valid tenant credential is present
     *                                 or the tenant account is suspended.
     */
    public static function requireCompany(): Company
    {
        $company = self::resolve();
        if ($company === null) {
            throw new AuthenticationException(
                'Authentication required: provide a valid session cookie, Bearer token, or X-API-Key header.',
            );
        }
        if (!$company->isActive) {
            throw new AuthenticationException('This company account is suspended.');
        }

        return $company;
    }

    /** Require the browser's logged-in session, excluding API-key and JWT auth. */
    public static function requireSessionCompany(): Company
    {
        self::startSession();
        $id = $_SESSION['company_id'] ?? null;
        if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
            throw new AuthenticationException('A signed-in company session is required.');
        }
        $company = (new CompanyRepository(Database::pdo()))->find((int) $id);
        if ($company === null || !$company->isActive) {
            throw new AuthenticationException('A signed-in active company is required.');
        }
        return $company;
    }

    /**
     * Establish a logged-in session for the tenant (called by login/signup).
     */
    public static function login(int $companyId): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['company_id'] = $companyId;
    }

    /**
     * Tear down the logged-in session (called by logout).
     */
    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => self::secureCookie(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_destroy();
        }
    }

    private static function resolve(): ?Company
    {
        $repository = new CompanyRepository(Database::pdo());

        // 1. Session (dashboard)
        self::startSession();
        $sessionCompanyId = $_SESSION['company_id'] ?? null;
        if (is_int($sessionCompanyId) || (is_string($sessionCompanyId) && ctype_digit($sessionCompanyId))) {
            $company = $repository->find((int) $sessionCompanyId);
            if ($company !== null) {
                return $company;
            }
        }

        // 2. Bearer JWT
        $bearerToken = self::bearerToken();
        if ($bearerToken !== null) {
            $claims = Jwt::decode($bearerToken);
            if ($claims !== null && isset($claims['company_id']) && is_numeric($claims['company_id'])) {
                $company = $repository->find((int) $claims['company_id']);
                if ($company !== null) {
                    return $company;
                }
            }
        }

        // 3. API key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if (is_string($apiKey) && trim($apiKey) !== '') {
            $company = $repository->findByApiKey(trim($apiKey));
            if ($company !== null) {
                return $company;
            }
        }

        return null;
    }

    public static function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        session_name('reviewradar_session');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::secureCookie(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private static function secureCookie(): bool
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || Config::string('app.env', 'production') === 'production';
    }

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
