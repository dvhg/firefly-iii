<?php

/*
 * AccountUpdateRequest.php
 * Copyright (c) 2021 james@firefly-iii.org
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

namespace FireflyIII\Api\V1\Requests\Models\Account;

use FireflyIII\Models\Location;
use FireflyIII\Rules\IsBoolean;
use FireflyIII\Rules\UniqueAccountNumber;
use FireflyIII\Rules\UniqueIban;
use FireflyIII\Support\Request\AppendsLocationData;
use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class UpdateRequest
 *
 * @codeCoverageIgnore
 */
class UpdateRequest extends FormRequest
{
    use ConvertsDataTypes, AppendsLocationData, ChecksLogin;

    /**
     * @return array
     */
    public function getUpdateData(): array
    {
        $active          = null;
        $includeNetWorth = null;
        if (null !== $this->get('active')) {
            $active = $this->boolean('active');
        }
        if (null !== $this->get('include_net_worth')) {
            $includeNetWorth = $this->boolean('include_net_worth');
        }
        $data = [
            'name'                    => $this->nullableString('name'),
            'active'                  => $active,
            'include_net_worth'       => $includeNetWorth,
            'account_type'            => $this->nullableString('type'),
            'account_type_id'         => null,
            'virtual_balance'         => $this->nullableString('virtual_balance'),
            'iban'                    => $this->nullableString('iban'),
            'BIC'                     => $this->nullableString('bic'),
            'account_number'          => $this->nullableString('account_number'),
            'account_role'            => $this->nullableString('account_role'),
            'liability_type'          => $this->nullableString('liability_type'),
            'opening_balance'         => $this->nullableString('opening_balance'),
            'opening_balance_date'    => $this->date('opening_balance_date'),
            'cc_type'                 => $this->nullableString('credit_card_type'),
            'cc_monthly_payment_date' => $this->nullableString('monthly_payment_date'),
            'notes'                   => $this->nullableNlString('notes'),
            'interest'                => $this->nullableString('interest'),
            'interest_period'         => $this->nullableString('interest_period'),
        ];
        if (null !== $this->get('order')) {
            $data['order'] = $this->integer('order');
        }
        if (null !== $this->get('currency_id')) {
            $data['currency_id'] = $this->nullableInteger('currency_id');
        }
        if (null !== $this->get('currency_code')) {
            $data['currency_code'] = $this->nullableString('currency_code');
        }

        $data = $this->appendLocationData($data, null);

        if ('liability' === $data['account_type']) {
            $data['opening_balance']      = bcmul($this->nullableString('liability_amount'), '-1');
            $data['opening_balance_date'] = $this->date('liability_start_date');
            $data['account_type']         = $this->nullableString('liability_type');
            $data['account_type_id']      = null;
        }

        return $data;
    }

    /**
     * The rules that the incoming request must be matched against.
     *
     * @return array
     */
    public function rules(): array
    {
        $account        = $this->route()->parameter('account');
        $accountRoles   = implode(',', config('firefly.accountRoles'));
        $types          = implode(',', array_keys(config('firefly.subTitlesByIdentifier')));
        $ccPaymentTypes = implode(',', array_keys(config('firefly.ccTypes')));

        $rules = [
            'name'                 => sprintf('min:1|uniqueAccountForUser:%d', $account->id),
            'type'                 => sprintf('in:%s', $types),
            'iban'                 => ['iban', 'nullable', new UniqueIban($account, $this->nullableString('type'))],
            'bic'                  => 'bic|nullable',
            'account_number'       => ['between:1,255', 'nullable', new UniqueAccountNumber($account, $this->nullableString('type'))],
            'opening_balance'      => 'numeric|required_with:opening_balance_date|nullable',
            'opening_balance_date' => 'date|required_with:opening_balance|nullable',
            'virtual_balance'      => 'numeric|nullable',
            'order'                => 'numeric|nullable',
            'currency_id'          => 'numeric|exists:transaction_currencies,id',
            'currency_code'        => 'min:3|max:3|exists:transaction_currencies,code',
            'active'               => [new IsBoolean],
            'include_net_worth'    => [new IsBoolean],
            'account_role'         => sprintf('in:%s|required_if:type,asset', $accountRoles),
            'credit_card_type'     => sprintf('in:%s|required_if:account_role,ccAsset', $ccPaymentTypes),
            'monthly_payment_date' => 'date' . '|required_if:account_role,ccAsset|required_if:credit_card_type,monthlyFull',
            'liability_type'       => 'required_if:type,liability|in:loan,debt,mortgage',
            'interest'             => 'required_if:type,liability|between:0,100|numeric',
            'interest_period'      => 'required_if:type,liability|in:daily,monthly,yearly',
            'notes'                => 'min:0|max:65536',
        ];

        return Location::requestRules($rules);
    }
}
