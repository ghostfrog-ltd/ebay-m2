<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Account;

use GhostFrog\EbayLedger\Model\Account;
use GhostFrog\EbayLedger\Model\AccountFactory;
use GhostFrog\EbayLedger\Model\Ebay\Identity;
use GhostFrog\EbayLedger\Model\Ebay\OAuth;
use GhostFrog\EbayLedger\Model\ResourceModel\Account as AccountResource;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stores each account's eBay tokens encrypted and hands out a valid access token, refreshing it when it is
 * within five minutes of expiry. Tokens are never logged.
 */
class Tokens
{
    private const MARGIN_SECONDS = 300;

    public function __construct(
        private readonly AccountFactory $accounts,
        private readonly AccountResource $resource,
        private readonly OAuth $oauth,
        private readonly Identity $identity,
        private readonly EncryptorInterface $encryptor,
        private readonly LockManagerInterface $lock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function load(int $accountId): Account
    {
        $account = $this->accounts->create();
        $this->resource->load($account, $accountId);
        if (!$account->getId()) {
            throw new RuntimeException("eBay account $accountId does not exist.");
        }
        return $account;
    }

    /** Saves the tokens from a completed consent and records which eBay user the account is. */
    public function connect(Account $account, string $code, array $scopes): void
    {
        $tokens = $this->oauth->exchange($code);
        $now = time();
        $user = ['username' => null, 'user_id' => null];
        try {
            $user = $this->identity->user($tokens['access_token']);
        } catch (Throwable $error) {
            $this->logger->warning('GhostFrog Ledger could not read the connected eBay username.', [
                'account_id' => $account->getId(), 'error' => $error->getMessage(),
            ]);
        }
        if ($user['user_id'] !== null) {
            $other = $this->resource->getConnection()->fetchOne(
                $this->resource->getConnection()->select()
                    ->from($this->resource->getMainTable(), ['label'])
                    ->where('ebay_user_id = ?', $user['user_id'])
                    ->where('account_id <> ?', (int)$account->getId())
            );
            if ($other !== false) {
                throw new RuntimeException(sprintf('That eBay account (%s) is already connected as "%s".',
                    $user['username'] ?? $user['user_id'], $other));
            }
        }
        $account->addData([
            'access_token' => $this->encryptor->encrypt($tokens['access_token']),
            'access_expires_at' => gmdate('Y-m-d H:i:s', $now + $tokens['expires_in']),
            'refresh_token' => $this->encryptor->encrypt($tokens['refresh_token']),
            'refresh_expires_at' => $tokens['refresh_token_expires_in'] > 0
                ? gmdate('Y-m-d H:i:s', $now + $tokens['refresh_token_expires_in']) : null,
            'scopes' => implode("\n", $scopes),
            'ebay_username' => $user['username'] ?? $account->getData('ebay_username'),
            'ebay_user_id' => $user['user_id'] ?? $account->getData('ebay_user_id'),
            'connected_at' => gmdate('Y-m-d H:i:s', $now),
            'status' => Account::STATUS_CONNECTED,
            'last_error' => null,
        ]);
        $this->resource->save($account);
    }

    /** A usable access token for the account, refreshed first when needed. */
    public function accessToken(Account $account, bool $forceRefresh = false): string
    {
        if (!$forceRefresh && ($token = $this->currentToken($account)) !== null) {
            return $token;
        }
        $lockName = 'ghostfrog_ledger_token_' . $account->getId();
        if (!$this->lock->lock($lockName, 30)) {
            throw new RuntimeException('Another process is refreshing this account\'s eBay token.');
        }
        try {
            $this->resource->load($account, (int)$account->getId());
            if (!$forceRefresh && ($token = $this->currentToken($account)) !== null) {
                return $token;
            }
            return $this->refresh($account);
        } finally {
            $this->lock->unlock($lockName);
        }
    }

    public function disconnect(Account $account): void
    {
        $account->addData([
            'access_token' => null,
            'access_expires_at' => null,
            'refresh_token' => null,
            'refresh_expires_at' => null,
            'status' => Account::STATUS_NOT_CONNECTED,
            'last_error' => null,
        ]);
        $this->resource->save($account);
    }

    private function currentToken(Account $account): ?string
    {
        $expires = strtotime((string)$account->getData('access_expires_at') . ' UTC');
        $encrypted = (string)$account->getData('access_token');
        if ($encrypted === '' || $expires === false || $expires <= time() + self::MARGIN_SECONDS) {
            return null;
        }
        $token = $this->encryptor->decrypt($encrypted);
        return $token === '' ? null : $token;
    }

    private function refresh(Account $account): string
    {
        $refreshToken = $this->encryptor->decrypt((string)$account->getData('refresh_token'));
        if ($refreshToken === '') {
            throw new RuntimeException(sprintf('eBay account "%s" is not connected.', $account->getData('label')));
        }
        $refreshExpires = strtotime((string)$account->getData('refresh_expires_at') . ' UTC');
        if ($account->getData('refresh_expires_at') && $refreshExpires !== false && $refreshExpires <= time()) {
            $this->fail($account, Account::STATUS_EXPIRED, 'The eBay connection expired. Connect the account again.');
        }
        $scopes = array_values(array_filter(explode("\n", (string)$account->getData('scopes'))));
        try {
            $tokens = $this->oauth->refresh($refreshToken, $scopes);
        } catch (Throwable $error) {
            // 400 invalid_grant means the refresh token was revoked or expired.
            $status = str_contains($error->getMessage(), 'invalid_grant') ? Account::STATUS_EXPIRED : Account::STATUS_ERROR;
            $this->fail($account, $status, $error->getMessage());
        }
        $account->addData([
            'access_token' => $this->encryptor->encrypt($tokens['access_token']),
            'access_expires_at' => gmdate('Y-m-d H:i:s', time() + $tokens['expires_in']),
            'status' => Account::STATUS_CONNECTED,
            'last_error' => null,
        ]);
        $this->resource->save($account);
        return $tokens['access_token'];
    }

    private function fail(Account $account, string $status, string $message): never
    {
        $account->addData(['status' => $status, 'last_error' => mb_substr($message, 0, 1000)]);
        $this->resource->save($account);
        throw new RuntimeException($message);
    }
}
