<?php
/**
 * Steam.php
 * Copyright (c) 2019 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace FireflyIII\Support;

use Carbon\Carbon;
use DB;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Class Steam.
 *
 * @codeCoverageIgnore
 */
class Steam
{

    /**
     * Gets balance at the end of current month by default
     *
     * @param \FireflyIII\Models\Account $account
     * @param \Carbon\Carbon             $date
     *
     * @return string
     */
    public function balance(Account $account, Carbon $date, ?TransactionCurrency $currency = null): string
    {
        // abuse chart properties:
        $cache = new CacheProperties;
        $cache->addProperty($account->id);
        $cache->addProperty('balance');
        $cache->addProperty($date);
        $cache->addProperty($currency ? $currency->id : 0);
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        if (null === $currency) {
            $currency = $repository->getAccountCurrency($account) ?? app('amount')->getDefaultCurrencyByUser($account->user);
        }
        // first part: get all balances in own currency:
        $transactions  = $account->transactions()
                                 ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                                 ->where('transaction_journals.date', '<=', $date->format('Y-m-d 23:59:59'))
                                 ->where('transactions.transaction_currency_id', $currency->id)
                                 ->get(['transactions.amount'])->toArray();
        $nativeBalance = $this->sumTransactions($transactions, 'amount');
        // get all balances in foreign currency:
        $transactions   = $account->transactions()
                                  ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                                  ->where('transaction_journals.date', '<=', $date->format('Y-m-d 23:59:59'))
                                  ->where('transactions.foreign_currency_id', $currency->id)
                                  ->where('transactions.transaction_currency_id', '!=', $currency->id)
                                  ->get(['transactions.foreign_amount'])->toArray();
        $foreignBalance = $this->sumTransactions($transactions, 'foreign_amount');
        $balance        = bcadd($nativeBalance, $foreignBalance);
        $virtual        = null === $account->virtual_balance ? '0' : (string)$account->virtual_balance;
        $balance        = bcadd($balance, $virtual);

        $cache->store($balance);

        return $balance;
    }

    /**
     * @param \FireflyIII\Models\Account $account
     * @param \Carbon\Carbon             $date
     *
     * @return string
     */
    public function balanceIgnoreVirtual(Account $account, Carbon $date): string
    {
        // abuse chart properties:
        $cache = new CacheProperties;
        $cache->addProperty($account->id);
        $cache->addProperty('balance-no-virtual');
        $cache->addProperty($date);
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($account->user);

        $currencyId = (int)$repository->getMetaValue($account, 'currency_id');


        $transactions  = $account->transactions()
                                 ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                                 ->where('transaction_journals.date', '<=', $date->format('Y-m-d 23:59:59'))
                                 ->where('transactions.transaction_currency_id', $currencyId)
                                 ->get(['transactions.amount'])->toArray();
        $nativeBalance = $this->sumTransactions($transactions, 'amount');

        // get all balances in foreign currency:
        $transactions = $account->transactions()
                                ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                                ->where('transaction_journals.date', '<=', $date->format('Y-m-d 23:59:59'))
                                ->where('transactions.foreign_currency_id', $currencyId)
                                ->where('transactions.transaction_currency_id', '!=', $currencyId)
                                ->get(['transactions.foreign_amount'])->toArray();

        $foreignBalance = $this->sumTransactions($transactions, 'foreign_amount');
        $balance        = bcadd($nativeBalance, $foreignBalance);

        $cache->store($balance);

        return $balance;
    }

    /**
     * @param array  $transactions
     * @param string $key
     *
     * @return string
     */
    public function sumTransactions(array $transactions, string $key): string
    {
        $sum = '0';
        /** @var array $transaction */
        foreach ($transactions as $transaction) {
            $sum = bcadd($sum, $transaction[$key] ?? '0');
        }

        return $sum;
    }

    /**
     * Gets the balance for the given account during the whole range, using this format:.
     *
     * [yyyy-mm-dd] => 123,2
     *
     * @param \FireflyIII\Models\Account $account
     * @param \Carbon\Carbon             $start
     * @param \Carbon\Carbon             $end
     * @param TransactionCurrency|null   $currency
     *
     * @return array
     */
    public function balanceInRange(Account $account, Carbon $start, Carbon $end, ?TransactionCurrency $currency = null): array
    {
        // abuse chart properties:
        $cache = new CacheProperties;
        $cache->addProperty($account->id);
        $cache->addProperty('balance-in-range');
        $cache->addProperty($currency ? $currency->id : 0);
        $cache->addProperty($start);
        $cache->addProperty($end);
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }

        $start->subDay();
        $end->addDay();
        $balances     = [];
        $formatted    = $start->format('Y-m-d');
        $startBalance = $this->balance($account, $start, $currency);

        /** @var AccountRepositoryInterface $repository */

        $balances[$formatted] = $startBalance;
        if (null === $currency) {
            $repository = app(AccountRepositoryInterface::class);
            $repository->setUser($account->user);
            $currency = $repository->getAccountCurrency($account) ?? app('amount')->getDefaultCurrencyByUser($account->user);
        }
        $currencyId = (int)$currency->id;

        $start->addDay();

        // query!
        $set = $account->transactions()
                       ->leftJoin('transaction_journals', 'transactions.transaction_journal_id', '=', 'transaction_journals.id')
                       ->where('transaction_journals.date', '>=', $start->format('Y-m-d 00:00:00'))
                       ->where('transaction_journals.date', '<=', $end->format('Y-m-d  23:59:59'))
                       ->groupBy('transaction_journals.date')
                       ->groupBy('transactions.transaction_currency_id')
                       ->groupBy('transactions.foreign_currency_id')
                       ->orderBy('transaction_journals.date', 'ASC')
                       ->whereNull('transaction_journals.deleted_at')
                       ->get(
                           [
                               'transaction_journals.date',
                               'transactions.transaction_currency_id',
                               DB::raw('SUM(transactions.amount) AS modified'),
                               'transactions.foreign_currency_id',
                               DB::raw('SUM(transactions.foreign_amount) AS modified_foreign'),
                           ]
                       );

        $currentBalance = $startBalance;
        /** @var Transaction $entry */
        foreach ($set as $entry) {
            // normal amount and foreign amount
            $modified        = null === $entry->modified ? '0' : (string)$entry->modified;
            $foreignModified = null === $entry->modified_foreign ? '0' : (string)$entry->modified_foreign;
            $amount          = '0';
            if ($currencyId === (int)$entry->transaction_currency_id || 0 === $currencyId) {
                // use normal amount:
                $amount = $modified;
            }
            if ($currencyId === (int)$entry->foreign_currency_id) {
                // use foreign amount:
                $amount = $foreignModified;
            }

            $currentBalance  = bcadd($currentBalance, $amount);
            $carbon          = new Carbon($entry->date, config('app.timezone'));
            $date            = $carbon->format('Y-m-d');
            $balances[$date] = $currentBalance;
        }

        $cache->store($balances);

        return $balances;
    }

    /**
     * @param \FireflyIII\Models\Account $account
     * @param \Carbon\Carbon             $date
     *
     * @return array
     */
    public function balancePerCurrency(Account $account, Carbon $date): array
    {
        // abuse chart properties:
        $cache = new CacheProperties;
        $cache->addProperty($account->id);
        $cache->addProperty('balance-per-currency');
        $cache->addProperty($date);
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }
        $query    = $account->transactions()
                            ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
                            ->where('transaction_journals.date', '<=', $date->format('Y-m-d 23:59:59'))
                            ->groupBy('transactions.transaction_currency_id');
        $balances = $query->get(['transactions.transaction_currency_id', DB::raw('SUM(transactions.amount) as sum_for_currency')]);
        $return   = [];
        /** @var stdClass $entry */
        foreach ($balances as $entry) {
            $return[(int)$entry->transaction_currency_id] = $entry->sum_for_currency;
        }
        $cache->store($return);

        return $return;
    }

    /**
     * This method always ignores the virtual balance.
     *
     * @param \Illuminate\Support\Collection $accounts
     * @param \Carbon\Carbon                 $date
     *
     * @return array
     */
    public function balancesByAccounts(Collection $accounts, Carbon $date): array
    {
        $ids = $accounts->pluck('id')->toArray();
        // cache this property.
        $cache = new CacheProperties;
        $cache->addProperty($ids);
        $cache->addProperty('balances');
        $cache->addProperty($date);
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }

        // need to do this per account.
        $result = [];
        /** @var Account $account */
        foreach ($accounts as $account) {
            $result[$account->id] = $this->balance($account, $date);
        }

        $cache->store($result);

        return $result;
    }

    /**
     * Same as above, but also groups per currency.
     *
     * @param \Illuminate\Support\Collection $accounts
     * @param \Carbon\Carbon                 $date
     *
     * @return array
     */
    public function balancesPerCurrencyByAccounts(Collection $accounts, Carbon $date): array
    {
        $ids = $accounts->pluck('id')->toArray();
        // cache this property.
        $cache = new CacheProperties;
        $cache->addProperty($ids);
        $cache->addProperty('balances-per-currency');
        $cache->addProperty($date);
        if ($cache->has()) {
            return $cache->get(); // @codeCoverageIgnore
        }

        // need to do this per account.
        $result = [];
        /** @var Account $account */
        foreach ($accounts as $account) {
            $result[$account->id] = $this->balancePerCurrency($account, $date);
        }

        $cache->store($result);

        return $result;
    }

    /**
     * Remove weird chars from strings.
     *
     * @param string $string
     * TODO migrate to trait.
     *
     * @return string
     * @deprecated
     */
    public function cleanString(string $string): string
    {
        $search  = [
            "\u{0001}", // start of heading
            "\u{0002}", // start of text
            "\u{0003}", // end of text
            "\u{0004}", // end of transmission
            "\u{0005}", // enquiry
            "\u{0006}", // ACK
            "\u{0007}", // BEL
            "\u{0008}", // backspace
            "\u{000E}", // shift out
            "\u{000F}", // shift in
            "\u{0010}", // data link escape
            "\u{0011}", // DC1
            "\u{0012}", // DC2
            "\u{0013}", // DC3
            "\u{0014}", // DC4
            "\u{0015}", // NAK
            "\u{0016}", // SYN
            "\u{0017}", // ETB
            "\u{0018}", // CAN
            "\u{0019}", // EM
            "\u{001A}", // SUB
            "\u{001B}", // escape
            "\u{001C}", // file separator
            "\u{001D}", // group separator
            "\u{001E}", // record separator
            "\u{001F}", // unit separator
            "\u{007F}", // DEL
            "\u{00A0}", // non-breaking space
            "\u{1680}", // ogham space mark
            "\u{180E}", // mongolian vowel separator
            "\u{2000}", // en quad
            "\u{2001}", // em quad
            "\u{2002}", // en space
            "\u{2003}", // em space
            "\u{2004}", // three-per-em space
            "\u{2005}", // four-per-em space
            "\u{2006}", // six-per-em space
            "\u{2007}", // figure space
            "\u{2008}", // punctuation space
            "\u{2009}", // thin space
            "\u{200A}", // hair space
            "\u{200B}", // zero width space
            "\u{202F}", // narrow no-break space
            "\u{3000}", // ideographic space
            "\u{FEFF}", // zero width no -break space
        ];
        $replace = "\x20"; // plain old normal space
        $string  = str_replace($search, $replace, $string);
        $string  = str_replace(["\n", "\t", "\r"], "\x20", $string);

        return trim($string);
    }

    /**
     * Remove weird chars from strings, but keep newlines and tabs.
     *
     * @param string $string
     * TODO migrate to trait.
     *
     * @return string
     * @deprecated
     */
    public function nlCleanString(string $string): string
    {
        $search  = [
            "\u{0001}", // start of heading
            "\u{0002}", // start of text
            "\u{0003}", // end of text
            "\u{0004}", // end of transmission
            "\u{0005}", // enquiry
            "\u{0006}", // ACK
            "\u{0007}", // BEL
            "\u{0008}", // backspace
            "\u{000E}", // shift out
            "\u{000F}", // shift in
            "\u{0010}", // data link escape
            "\u{0011}", // DC1
            "\u{0012}", // DC2
            "\u{0013}", // DC3
            "\u{0014}", // DC4
            "\u{0015}", // NAK
            "\u{0016}", // SYN
            "\u{0017}", // ETB
            "\u{0018}", // CAN
            "\u{0019}", // EM
            "\u{001A}", // SUB
            "\u{001B}", // escape
            "\u{001C}", // file separator
            "\u{001D}", // group separator
            "\u{001E}", // record separator
            "\u{001F}", // unit separator
            "\u{007F}", // DEL
            "\u{00A0}", // non-breaking space
            "\u{1680}", // ogham space mark
            "\u{180E}", // mongolian vowel separator
            "\u{2000}", // en quad
            "\u{2001}", // em quad
            "\u{2002}", // en space
            "\u{2003}", // em space
            "\u{2004}", // three-per-em space
            "\u{2005}", // four-per-em space
            "\u{2006}", // six-per-em space
            "\u{2007}", // figure space
            "\u{2008}", // punctuation space
            "\u{2009}", // thin space
            "\u{200A}", // hair space
            "\u{200B}", // zero width space
            "\u{202F}", // narrow no-break space
            "\u{3000}", // ideographic space
            "\u{FEFF}", // zero width no -break space
        ];
        $replace = "\x20"; // plain old normal space
        $string  = str_replace($search, $replace, $string);
        $string  = str_replace("\r", '', $string);

        return trim($string);
    }

    /**
     * @param array $accounts
     *
     * @return array
     */
    public function getLastActivities(array $accounts): array
    {
        $list = [];

        $set = auth()->user()->transactions()
                     ->whereIn('transactions.account_id', $accounts)
                     ->groupBy(['transactions.account_id', 'transaction_journals.user_id'])
                     ->get(['transactions.account_id', DB::raw('MAX(transaction_journals.date) AS max_date')]);

        foreach ($set as $entry) {
            $date = new Carbon($entry->max_date, 'UTC');
            $date->setTimezone(config('app.timezone'));
            $list[(int)$entry->account_id] = $date;
        }

        return $list;
    }

    /**
     * @param string $amount
     *
     * @return string
     */
    public function negative(string $amount): string
    {
        if (1 === bccomp($amount, '0')) {
            $amount = bcmul($amount, '-1');
        }

        return $amount;
    }

    /**
     * @param string $amount
     *
     * @return string|null
     */
    public function opposite(string $amount = null): ?string
    {
        if (null === $amount) {
            return null;
        }

        return bcmul($amount, '-1');
    }

    /**
     * @param string $string
     *
     * @return int
     */
    public function phpBytes(string $string): int
    {
        $string = str_replace(['kb', 'mb', 'gb'], ['k', 'm', 'g'], strtolower($string));

        if (false !== stripos($string, 'k')) {
            // has a K in it, remove the K and multiply by 1024.
            $bytes = bcmul(rtrim($string, 'k'), '1024');

            return (int)$bytes;
        }

        if (false !== stripos($string, 'm')) {
            // has a M in it, remove the M and multiply by 1048576.
            $bytes = bcmul(rtrim($string, 'm'), '1048576');

            return (int)$bytes;
        }

        if (false !== stripos($string, 'g')) {
            // has a G in it, remove the G and multiply by (1024)^3.
            $bytes = bcmul(rtrim($string, 'g'), '1073741824');

            return (int)$bytes;
        }

        return (int)$string;
    }

    /**
     * @param string $amount
     *
     * @return string
     */
    public function positive(string $amount): string
    {
        if (bccomp($amount, '0') === -1) {
            $amount = bcmul($amount, '-1');
        }

        return $amount;
    }

    /**
     * Get user's language.
     *
     * @return string
     */
    public function getLanguage(): string // get preference
    {
        return app('preferences')->get('language', config('firefly.default_language', 'en_US'))->data;
    }

    /**
     * Get user's locale.
     *
     * @return string
     */
    public function getLocale(): string // get preference
    {
        /** @var string $language */
        $locale = app('preferences')->get('locale', config('firefly.default_locale', 'equal'))->data;
        if ('equal' === $locale) {
            $locale = $this->getLanguage();
        }

        // Check for Windows to replace the locale correctly.
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $locale = str_replace('_', '-', $locale);
        }

        return $locale;
    }

    /**
     * @param string $locale
     *
     * @return array
     */
    public function getLocaleArray(string $locale): array
    {
        return [
            sprintf('%s.utf8', $locale),
            sprintf('%s.UTF-8', $locale),
        ];
    }

}
