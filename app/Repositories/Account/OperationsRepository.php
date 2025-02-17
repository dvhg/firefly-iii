<?php
/**
 * OperationsRepository.php
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

namespace FireflyIII\Repositories\Account;

use Carbon\Carbon;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionType;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 *
 * Class OperationsRepository
 */
class OperationsRepository implements OperationsRepositoryInterface
{
    private User $user;

    /**
     * This method returns a list of all the withdrawal transaction journals (as arrays) set in that period
     * which have the specified accounts. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always negative.
     *
     * @param Carbon     $start
     * @param Carbon     $end
     * @param Collection $accounts
     *
     * @return array
     */
    public function listExpenses(Carbon $start, Carbon $end, Collection $accounts): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL]);
        $collector->setBothAccounts($accounts);
        $collector->withCategoryInformation()->withAccountInformation()->withBudgetInformation()->withTagInformation();
        $journals = $collector->getExtractedJournals();
        $array    = [];


        foreach ($journals as $journal) {
            $currencyId         = (int)$journal['currency_id'];
            $array[$currencyId] = $array[$currencyId] ?? [

                    'currency_id'             => $currencyId,
                    'currency_name'           => $journal['currency_name'],
                    'currency_symbol'         => $journal['currency_symbol'],
                    'currency_code'           => $journal['currency_code'],
                    'currency_decimal_places' => $journal['currency_decimal_places'],
                    'transaction_journals'    => [],
                ];

            $journalId                                              = (int)$journal['transaction_journal_id'];
            $array[$currencyId]['transaction_journals'][$journalId] = [
                'amount'                   => app('steam')->negative($journal['amount']),
                'date'                     => $journal['date'],
                'transaction_journal_id'   => $journalId,
                'budget_name'              => $journal['budget_name'],
                'category_name'            => $journal['category_name'],
                'source_account_id'        => $journal['source_account_id'],
                'source_account_name'      => $journal['source_account_name'],
                'source_account_iban'      => $journal['source_account_iban'],
                'destination_account_id'   => $journal['destination_account_id'],
                'destination_account_name' => $journal['destination_account_name'],
                'destination_account_iban' => $journal['destination_account_iban'],
                'tags'                     => $journal['tags'],
                'description'              => $journal['description'],
                'transaction_group_id'     => $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    /**
     * This method returns a list of all the deposit transaction journals (as arrays) set in that period
     * which have the specified accounts. It's grouped per currency, with as few details in the array
     * as possible. Amounts are always positive.
     *
     * @param Carbon          $start
     * @param Carbon          $end
     * @param Collection|null $accounts
     *
     * @return array
     */
    public function listIncome(Carbon $start, Carbon $end, ?Collection $accounts = null): array
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionType::DEPOSIT]);
        $collector->setBothAccounts($accounts);
        $collector->withCategoryInformation()->withAccountInformation()->withBudgetInformation()->withTagInformation();
        $journals = $collector->getExtractedJournals();
        $array    = [];


        foreach ($journals as $journal) {
            $currencyId         = (int)$journal['currency_id'];
            $array[$currencyId] = $array[$currencyId] ?? [

                    'currency_id'             => $currencyId,
                    'currency_name'           => $journal['currency_name'],
                    'currency_symbol'         => $journal['currency_symbol'],
                    'currency_code'           => $journal['currency_code'],
                    'currency_decimal_places' => $journal['currency_decimal_places'],
                    'transaction_journals'    => [],
                ];

            $journalId                                              = (int)$journal['transaction_journal_id'];
            $array[$currencyId]['transaction_journals'][$journalId] = [
                'amount'                   => app('steam')->positive($journal['amount']),
                'date'                     => $journal['date'],
                'transaction_journal_id'   => $journalId,
                'budget_name'              => $journal['budget_name'],
                'tags'                     => $journal['tags'],
                'category_name'            => $journal['category_name'],
                'source_account_id'        => $journal['source_account_id'],
                'source_account_name'      => $journal['source_account_name'],
                'source_account_iban'      => $journal['source_account_iban'],
                'destination_account_id'   => $journal['destination_account_id'],
                'destination_account_name' => $journal['destination_account_name'],
                'destination_account_iban' => $journal['destination_account_iban'],
                'description'              => $journal['description'],
                'transaction_group_id'     => $journal['transaction_group_id'],
            ];
        }

        return $array;
    }

    /**
     * @param User $user
     */
    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * @inheritDoc
     */
    public function sumExpenses(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $expense = null, ?TransactionCurrency $currency = null
    ): array {
        $start->startOfDay();
        $end->endOfDay();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector
            ->setUser($this->user)
            ->setRange($start, $end)
            ->setTypes([TransactionType::WITHDRAWAL]);

        if (null !== $accounts) {
            $collector->setSourceAccounts($accounts);
        }
        if (null !== $expense) {
            $collector->setDestinationAccounts($expense);
        }
        if (null !== $currency) {
            $collector->setCurrency($currency);
        }
        $journals = $collector->getExtractedJournals();

        // same but for foreign currencies:
        if (null !== $currency) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])
                      ->setForeignCurrency($currency);

            if (null !== $accounts) {
                $collector->setSourceAccounts($accounts);
            }
            if (null !== $expense) {
                $collector->setDestinationAccounts($expense);
            }

            $result = $collector->getExtractedJournals();

            // do not use array_merge because you want keys to overwrite (otherwise you get double results):
            $journals = $result + $journals;
        }
        $array = [];

        foreach ($journals as $journal) {
            $currencyId                = (int)$journal['currency_id'];
            $array[$currencyId]        = $array[$currencyId] ?? [
                    'sum'                     => '0',
                    'currency_id'             => $currencyId,
                    'currency_name'           => $journal['currency_name'],
                    'currency_symbol'         => $journal['currency_symbol'],
                    'currency_code'           => $journal['currency_code'],
                    'currency_decimal_places' => $journal['currency_decimal_places'],
                ];
            $array[$currencyId]['sum'] = bcadd($array[$currencyId]['sum'], app('steam')->negative($journal['amount']));

            // also do foreign amount:
            $foreignId = (int)$journal['foreign_currency_id'];
            if (0 !== $foreignId) {
                $array[$foreignId]        = $array[$foreignId] ?? [
                        'sum'                     => '0',
                        'currency_id'             => $foreignId,
                        'currency_name'           => $journal['foreign_currency_name'],
                        'currency_symbol'         => $journal['foreign_currency_symbol'],
                        'currency_code'           => $journal['foreign_currency_code'],
                        'currency_decimal_places' => $journal['foreign_currency_decimal_places'],
                    ];
                $array[$foreignId]['sum'] = bcadd($array[$foreignId]['sum'], app('steam')->negative($journal['foreign_amount']));
            }
        }

        return $array;
    }

    /**
     * // TODO same as income but copied.
     *
     * @inheritDoc
     */
    public function sumIncome(Carbon $start, Carbon $end, ?Collection $accounts = null, ?Collection $revenue = null, ?TransactionCurrency $currency = null
    ): array {
        $start->startOfDay();
        $end->endOfDay();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector
            ->setUser($this->user)
            ->setRange($start, $end)
            ->setTypes([TransactionType::DEPOSIT]);

        if (null !== $accounts) {
            $collector->setDestinationAccounts($accounts);
        }
        if (null !== $revenue) {
            $collector->setSourceAccounts($revenue);
        }
        if (null !== $currency) {
            $collector->setCurrency($currency);
        }
        $journals = $collector->getExtractedJournals();

        // same but for foreign currencies:
        if (null !== $currency) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])
                      ->setForeignCurrency($currency);

            if (null !== $accounts) {
                $collector->setDestinationAccounts($accounts);
            }
            if (null !== $revenue) {
                $collector->setSourceAccounts($revenue);
            }
            $result = $collector->getExtractedJournals();

            // do not use array_merge because you want keys to overwrite (otherwise you get double results):
            $journals = $result + $journals;
        }
        $array = [];

        foreach ($journals as $journal) {
            $currencyId                = (int)$journal['currency_id'];
            $array[$currencyId]        = $array[$currencyId] ?? [
                    'sum'                     => '0',
                    'currency_id'             => $currencyId,
                    'currency_name'           => $journal['currency_name'],
                    'currency_symbol'         => $journal['currency_symbol'],
                    'currency_code'           => $journal['currency_code'],
                    'currency_decimal_places' => $journal['currency_decimal_places'],
                ];
            $array[$currencyId]['sum'] = bcadd($array[$currencyId]['sum'], app('steam')->positive($journal['amount']));

            // also do foreign amount:
            $foreignId = (int)$journal['foreign_currency_id'];
            if (0 !== $foreignId) {
                $array[$foreignId]        = $array[$foreignId] ?? [
                        'sum'                     => '0',
                        'currency_id'             => $foreignId,
                        'currency_name'           => $journal['foreign_currency_name'],
                        'currency_symbol'         => $journal['foreign_currency_symbol'],
                        'currency_code'           => $journal['foreign_currency_code'],
                        'currency_decimal_places' => $journal['foreign_currency_decimal_places'],
                    ];
                $array[$foreignId]['sum'] = bcadd($array[$foreignId]['sum'], app('steam')->positive($journal['foreign_amount']));
            }
        }

        return $array;
    }

    /**
     * @inheritDoc
     */
    public function sumTransfers(Carbon $start, Carbon $end, ?Collection $accounts = null, ?TransactionCurrency $currency = null): array
    {
        $start->startOfDay();
        $end->endOfDay();

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionType::TRANSFER]);

        if (null !== $accounts) {
            $collector->setAccounts($accounts);
        }
        if (null !== $currency) {
            $collector->setCurrency($currency);
        }
        $journals = $collector->getExtractedJournals();

        // same but for foreign currencies:
        if (null !== $currency) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector->setUser($this->user)->setRange($start, $end)->setTypes([TransactionType::WITHDRAWAL])
                      ->setForeignCurrency($currency);

            if (null !== $accounts) {
                $collector->setAccounts($accounts);
            }
            $result = $collector->getExtractedJournals();

            // do not use array_merge because you want keys to overwrite (otherwise you get double results):
            $journals = $result + $journals;
        }
        $array = [];

        foreach ($journals as $journal) {
            $currencyId                = (int)$journal['currency_id'];
            $array[$currencyId]        = $array[$currencyId] ?? [
                    'sum'                     => '0',
                    'currency_id'             => $currencyId,
                    'currency_name'           => $journal['currency_name'],
                    'currency_symbol'         => $journal['currency_symbol'],
                    'currency_code'           => $journal['currency_code'],
                    'currency_decimal_places' => $journal['currency_decimal_places'],
                ];
            $array[$currencyId]['sum'] = bcadd($array[$currencyId]['sum'], app('steam')->positive($journal['amount']));

            // also do foreign amount:
            $foreignId = (int)$journal['foreign_currency_id'];
            if (0 !== $foreignId) {
                $array[$foreignId]        = $array[$foreignId] ?? [
                        'sum'                     => '0',
                        'currency_id'             => $foreignId,
                        'currency_name'           => $journal['foreign_currency_name'],
                        'currency_symbol'         => $journal['foreign_currency_symbol'],
                        'currency_code'           => $journal['foreign_currency_code'],
                        'currency_decimal_places' => $journal['foreign_currency_decimal_places'],
                    ];
                $array[$foreignId]['sum'] = bcadd($array[$foreignId]['sum'], app('steam')->positive($journal['foreign_amount']));
            }
        }

        return $array;
    }
}
