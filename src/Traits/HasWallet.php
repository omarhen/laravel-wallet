<?php

namespace Bavix\Wallet\Traits;

use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Transfer;
use Bavix\Wallet\Models\Wallet as WalletModel;
use Bavix\Wallet\Objects\Bring;
use Bavix\Wallet\Objects\Operation;
use Bavix\Wallet\Services\CommonService;
use Bavix\Wallet\Services\ProxyService;
use Bavix\Wallet\Services\WalletService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use function app;
use function config;
use function current;

/**
 * Trait HasWallet
 *
 * @package Bavix\Wallet\Traits
 *
 * @property-read WalletModel $wallet
 * @property-read Collection|WalletModel[] $wallets
 * @property-read int $balance
 */
trait HasWallet
{

    /**
     * The input means in the system
     *
     * @param int $amount
     * @param array|null $meta
     * @param bool $confirmed
     *
     * @return Transaction
     */
    public function deposit(int $amount, ?array $meta = null, bool $confirmed = true): Transaction
    {
        $walletService = app(WalletService::class);
        $walletService->checkAmount($amount);

        /**
         * @var WalletModel $wallet
         */
        $wallet = $walletService->getWallet($this);

        $transactions = app(CommonService::class)->enforce($wallet, [
            (new Operation())
                ->setType(Transaction::TYPE_DEPOSIT)
                ->setConfirmed($confirmed)
                ->setAmount($amount)
                ->setMeta($meta)
        ]);

        return current($transactions);
    }

    /**
     * Magic laravel framework method, makes it
     *  possible to call property balance
     *
     * Example:
     *  $user1 = User::first()->load('wallet');
     *  $user2 = User::first()->load('wallet');
     *
     * Without static:
     *  var_dump($user1->balance, $user2->balance); // 100 100
     *  $user1->deposit(100);
     *  $user2->deposit(100);
     *  var_dump($user1->balance, $user2->balance); // 200 200
     *
     * With static:
     *  var_dump($user1->balance, $user2->balance); // 100 100
     *  $user1->deposit(100);
     *  var_dump($user1->balance); // 200
     *  $user2->deposit(100);
     *  var_dump($user2->balance); // 300
     *
     * @return int
     * @throws
     */
    public function getBalanceAttribute(): int
    {
        if ($this instanceof WalletModel) {
            $this->exists or $this->save();
            $proxy = app(ProxyService::class);
            if (!$proxy->has($this->getKey())) {
                $proxy->set($this->getKey(), (int)($this->attributes['balance'] ?? 0));
            }

            return $proxy[$this->getKey()];
        }

        return $this->wallet->balance;
    }

    /**
     * all user actions on wallets will be in this method
     *
     * @return MorphMany
     */
    public function transactions(): MorphMany
    {
        return ($this instanceof WalletModel ? $this->holder : $this)
            ->morphMany(config('wallet.transaction.model'), 'payable');
    }

    /**
     * This method ignores errors that occur when transferring funds
     *
     * @param Wallet $wallet
     * @param int $amount
     * @param array|null $meta
     * @param string $status
     * @return null|Transfer
     */
    public function safeTransfer(Wallet $wallet, int $amount, ?array $meta = null, string $status = Transfer::STATUS_TRANSFER): ?Transfer
    {
        try {
            return $this->transfer($wallet, $amount, $meta, $status);
        } catch (Throwable $throwable) {
            return null;
        }
    }

    /**
     * A method that transfers funds from host to host
     *
     * @param Wallet $wallet
     * @param int $amount
     * @param array|null $meta
     * @param string $status
     * @return Transfer
     * @throws
     */
    public function transfer(Wallet $wallet, int $amount, ?array $meta = null, string $status = Transfer::STATUS_TRANSFER): Transfer
    {
        app(CommonService::class)->verifyWithdraw($this, $amount);
        return $this->forceTransfer($wallet, $amount, $meta, $status);
    }

    /**
     * Withdrawals from the system
     *
     * @param int $amount
     * @param array|null $meta
     * @param bool $confirmed
     *
     * @return Transaction
     */
    public function withdraw(int $amount, ?array $meta = null, bool $confirmed = true): Transaction
    {
        app(CommonService::class)->verifyWithdraw($this, $amount);
        return $this->forceWithdraw($amount, $meta, $confirmed);
    }

    /**
     * Checks if you can withdraw funds
     *
     * @param int $amount
     * @return bool
     */
    public function canWithdraw(int $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Forced to withdraw funds from system
     *
     * @param int $amount
     * @param array|null $meta
     * @param bool $confirmed
     *
     * @return Transaction
     */
    public function forceWithdraw(int $amount, ?array $meta = null, bool $confirmed = true): Transaction
    {
        $walletService = app(WalletService::class);
        $walletService->checkAmount($amount);

        /**
         * @var WalletModel $wallet
         */
        $wallet = $walletService->getWallet($this);

        $transactions = app(CommonService::class)->enforce($wallet, [
            (new Operation())
                ->setType(Transaction::TYPE_WITHDRAW)
                ->setConfirmed($confirmed)
                ->setAmount(-$amount)
                ->setMeta($meta)
        ]);

        return current($transactions);
    }

    /**
     * the forced transfer is needed when the user does not have the money and we drive it.
     * Sometimes you do. Depends on business logic.
     *
     * @param Wallet $wallet
     * @param int $amount
     * @param array|null $meta
     * @param string $status
     * @return Transfer
     */
    public function forceTransfer(Wallet $wallet, int $amount, ?array $meta = null, string $status = Transfer::STATUS_TRANSFER): Transfer
    {
        return DB::transaction(function () use ($amount, $wallet, $meta, $status) {
            $fee = app(WalletService::class)
                ->fee($wallet, $amount);

            $withdraw = $this->forceWithdraw($amount + $fee, $meta);
            $deposit = $wallet->deposit($amount, $meta);

            $from = app(WalletService::class)
                ->getWallet($this);

            $transfers = app(CommonService::class)->assemble([
                (new Bring())
                    ->setStatus($status)
                    ->setDeposit($deposit)
                    ->setWithdraw($withdraw)
                    ->setFrom($from)
                    ->setTo($wallet)
            ]);

            return current($transfers);
        });
    }

    /**
     * the transfer table is used to confirm the payment
     * this method receives all transfers
     *
     * @return MorphMany
     */
    public function transfers(): MorphMany
    {
        return app(WalletService::class)
            ->getWallet($this)
            ->morphMany(config('wallet.transfer.model'), 'from');
    }

    /**
     * Get default Wallet
     * this method is used for Eager Loading
     *
     * @return MorphOne|WalletModel
     */
    public function wallet(): MorphOne
    {
        return ($this instanceof WalletModel ? $this->holder : $this)
            ->morphOne(config('wallet.wallet.model'), 'holder')
            ->where('slug', config('wallet.wallet.default.slug'))
            ->withDefault([
                'name' => config('wallet.wallet.default.name'),
                'slug' => config('wallet.wallet.default.slug'),
                'balance' => 0,
            ]);
    }

}
